<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Repository\PasskeyRepository;
use lbuchs\WebAuthn\Attestation\AuthenticatorData;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use RuntimeException;

final class WebAuthnService
{
    private const SESSION_KEY = '_webauthn_challenges';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly PasskeyRepository $repository,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /** @param array<string, mixed> $user
     *  @return array<string, mixed>
     */
    public function registrationOptions(array $user): array
    {
        $this->assertEnabled();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('La sesión autenticada no está disponible.');
        }
        $credentials = $this->repository->credentialsForUser($userId);
        if (count($credentials) >= (int) $this->config['max_credentials']) {
            throw new RuntimeException('La cuenta alcanzó el máximo de Passkeys permitidas.');
        }
        $userHandle = $this->repository->stableUserHandle($userId, (string) $this->config['handle_secret']);
        $displayName = trim(implode(' ', array_filter([
            (string) ($user['nombre'] ?? ''), (string) ($user['appat'] ?? ''), (string) ($user['apmat'] ?? ''),
        ]))) ?: (string) ($user['email'] ?? 'Usuario');
        $options = $this->library()->getCreateArgs(
            $userHandle,
            (string) ($user['email'] ?? 'usuario-' . $userId),
            $displayName,
            (int) $this->config['timeout'],
            'required',
            (string) $this->config['user_verification'],
            null,
            array_column($credentials, 'credential_id'),
        );
        $this->storeChallenge('register', $options->publicKey->challenge->getBinaryString(), [
            'user_id' => $userId,
            'user_handle' => self::base64UrlEncode($userHandle),
        ], (int) $this->config['enrollment_ttl']);
        return $this->normalizeOptions($options);
    }

    /** @param array<string, mixed>|null $user
     *  @return array<string, mixed>
     */
    public function loginOptions(?array $user = null): array
    {
        $this->assertEnabled();
        $userId = null;
        $credentialIds = [];
        if (is_array($user)) {
            $userId = (int) ($user['id'] ?? 0);
            $credentials = $this->repository->credentialsForUser($userId);
            if ($credentials === []) {
                throw new RuntimeException('No hay una Passkey disponible para esa cuenta.');
            }
            $credentialIds = array_column($credentials, 'credential_id');
        }
        $options = $this->library()->getGetArgs(
            $credentialIds,
            (int) $this->config['timeout'],
            true, true, true, true, true,
            (string) $this->config['user_verification'],
        );
        $this->storeChallenge('login', $options->publicKey->challenge->getBinaryString(), ['user_id' => $userId]);
        return $this->normalizeOptions($options);
    }

    /** @param array<string, mixed> $payload
     *  @param array<string, mixed> $user
     *  @return array{id:int,message:string}
     */
    public function verifyRegistration(array $payload, array $user): array
    {
        $this->assertEnabled();
        $challenge = $this->consumeChallenge('register');
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || $userId !== (int) ($challenge['user_id'] ?? 0)) {
            throw new RuntimeException('La ceremonia no corresponde al usuario autenticado.');
        }
        $response = $this->requireArray($payload, 'response');
        $clientData = $this->decodeRequired((string) ($response['clientDataJSON'] ?? ''), 'datos del cliente');
        $attestation = $this->decodeRequired((string) ($response['attestationObject'] ?? ''), 'atestación');
        $this->assertExactOrigin($clientData, 'webauthn.create');

        $created = $this->library()->processCreate(
            $clientData,
            $attestation,
            (string) $challenge['challenge'],
            $this->config['user_verification'] === 'required',
            true,
            false,
            false,
        );
        $credentialId = $created->credentialId instanceof ByteBuffer
            ? $created->credentialId->getBinaryString()
            : (string) $created->credentialId;
        $browserId = $this->decodeRequired((string) ($payload['rawId'] ?? $payload['id'] ?? ''), 'credencial');
        if (!hash_equals($credentialId, $browserId)) {
            throw new RuntimeException('La Passkey no coincide con la atestación recibida.');
        }
        $expectedHandle = self::base64UrlDecode((string) ($challenge['user_handle'] ?? ''));
        $name = mb_substr(trim((string) ($payload['name'] ?? 'Mi dispositivo')), 0, 100);
        $transports = array_values(array_intersect(
            is_array($response['transports'] ?? null) ? $response['transports'] : [],
            ['usb', 'nfc', 'ble', 'hybrid', 'internal'],
        ));
        $id = $this->repository->insert($userId, [
            'credential_id' => $credentialId,
            'public_key' => (string) $created->credentialPublicKey,
            'user_handle' => $expectedHandle,
            'sign_count' => (int) ($created->signatureCounter ?? 0),
            'transports' => $transports,
            'backup_eligible' => (bool) ($created->isBackupEligible ?? false),
            'backup_state' => (bool) ($created->isBackedUp ?? false),
            'name' => $name !== '' ? $name : 'Mi dispositivo',
        ], (int) $this->config['max_credentials']);
        return ['id' => $id, 'message' => 'La Passkey quedó registrada.'];
    }

    /** @param array<string, mixed> $payload
     *  @return array{user_id:int,credential_id:int}
     */
    public function verifyLogin(array $payload): array
    {
        $this->assertEnabled();
        $challenge = $this->consumeChallenge('login');
        $response = $this->requireArray($payload, 'response');
        $credentialId = $this->decodeRequired((string) ($payload['rawId'] ?? $payload['id'] ?? ''), 'credencial');
        $credential = $this->repository->findByCredentialId($credentialId);
        if ($credential === null) {
            throw new RuntimeException('La Passkey no está registrada en este sistema.');
        }
        $restrictedUser = (int) ($challenge['user_id'] ?? 0);
        if ($restrictedUser > 0 && $restrictedUser !== (int) $credential['id_usuario']) {
            throw new RuntimeException('La Passkey no corresponde a la cuenta seleccionada.');
        }

        $encodedHandle = (string) ($response['userHandle'] ?? '');
        if ($encodedHandle === '' && $restrictedUser === 0) {
            throw new RuntimeException('La Passkey descubrible no identificó a su usuario.');
        }
        if ($encodedHandle !== '') {
            $userHandle = self::base64UrlDecode($encodedHandle);
            if (!hash_equals((string) $credential['user_handle'], $userHandle)) {
                throw new RuntimeException('La Passkey no corresponde a su usuario.');
            }
        }

        $clientData = $this->decodeRequired((string) ($response['clientDataJSON'] ?? ''), 'datos del cliente');
        $authenticatorData = $this->decodeRequired((string) ($response['authenticatorData'] ?? ''), 'datos del autenticador');
        $signature = $this->decodeRequired((string) ($response['signature'] ?? ''), 'firma');
        $this->assertExactOrigin($clientData, 'webauthn.get');
        $previousCounter = (bool) $credential['backup_eligible'] ? null : (int) $credential['sign_count'];
        $webauthn = $this->library();
        if (!$webauthn->processGet(
            $clientData,
            $authenticatorData,
            $signature,
            (string) $credential['public_key'],
            (string) $challenge['challenge'],
            $previousCounter,
            $this->config['user_verification'] === 'required',
            true,
        )) {
            throw new RuntimeException('No fue posible validar la firma de la Passkey.');
        }
        $authenticator = new AuthenticatorData($authenticatorData);
        $this->repository->updateUsage(
            (int) $credential['id'],
            $webauthn->getSignatureCounter() ?? (int) $credential['sign_count'],
            $authenticator->getIsBackup(),
            $previousCounter,
        );
        return ['user_id' => (int) $credential['id_usuario'], 'credential_id' => (int) $credential['id']];
    }

    private function library(): WebAuthn
    {
        return new WebAuthn((string) $this->config['rp_name'], (string) $this->config['rp_id'], ['none'], true);
    }

    /** @param array<string, mixed> $context */
    private function storeChallenge(string $operation, string $challenge, array $context, ?int $ttl = null): void
    {
        $_SESSION[self::SESSION_KEY][$operation] = $context + [
            'operation' => $operation,
            'challenge' => $challenge,
            'expires_at' => time() + ($ttl ?? (int) $this->config['challenge_ttl']),
            'ua_hash' => hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private function consumeChallenge(string $operation): array
    {
        $stored = $_SESSION[self::SESSION_KEY][$operation] ?? null;
        unset($_SESSION[self::SESSION_KEY][$operation]);
        $uaHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!is_array($stored)
            || !hash_equals($operation, (string) ($stored['operation'] ?? ''))
            || !is_string($stored['challenge'] ?? null)
            || (int) ($stored['expires_at'] ?? 0) < time()
            || !hash_equals((string) ($stored['ua_hash'] ?? ''), $uaHash)) {
            throw new RuntimeException('El desafío de la Passkey venció. Inténtalo nuevamente.');
        }
        return $stored;
    }

    private function assertExactOrigin(string $clientDataJson, string $expectedType): void
    {
        $data = json_decode($clientDataJson, true);
        if (!is_array($data)
            || !hash_equals($expectedType, (string) ($data['type'] ?? ''))
            || !hash_equals((string) $this->config['origin'], (string) ($data['origin'] ?? ''))
            || ($data['crossOrigin'] ?? false) !== false) {
            throw new RuntimeException('El origen de la solicitud WebAuthn no es válido.');
        }
    }

    /** @return array<string, mixed> */
    private function normalizeOptions(object $options): array
    {
        $decoded = json_decode(json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('No fue posible preparar la solicitud WebAuthn.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    private function requireArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException('La respuesta de la Passkey está incompleta.');
        }
        return $value;
    }

    private function decodeRequired(string $value, string $label): string
    {
        if ($value === '') {
            throw new RuntimeException('Faltan ' . $label . ' de la Passkey.');
        }
        return self::base64UrlDecode($value);
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new RuntimeException('La respuesta WebAuthn contiene datos binarios no válidos.');
        }
        $decoded = base64_decode(strtr($value . str_repeat('=', (4 - strlen($value) % 4) % 4), '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('La respuesta WebAuthn contiene datos binarios no válidos.');
        }
        return $decoded;
    }

    private function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('El acceso con Passkey no está habilitado.');
        }
    }
}
