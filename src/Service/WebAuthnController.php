<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Core\Auth;
use Banca\Core\Csrf;
use Banca\Core\Http;
use Banca\Repository\BankingRepository;
use Banca\Repository\PasskeyRepository;
use lbuchs\WebAuthn\WebAuthnException;
use PDO;
use RuntimeException;
use Throwable;

final class WebAuthnController
{
    private readonly WebAuthnRateLimiter $limiter;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
        private readonly BankingRepository $users,
        private readonly PasskeyRepository $passkeys,
        private readonly WebAuthnService $service,
    ) {
        $this->limiter = new WebAuthnRateLimiter($pdo, (int) $config['max_options_per_minute']);
    }

    public function handle(string $action): never
    {
        $payload = $this->payload();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->respond(['ok' => false, 'message' => 'Método no permitido.'], 405);
        }
        $ip = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 100);
        try {
            if (!$this->service->isEnabled()) {
                throw new RuntimeException('El acceso con Passkey no está habilitado.');
            }
            $this->assertSecureRequest();
            switch ($action) {
                case 'login-options':
                    $identity = mb_substr(trim((string) ($payload['identity'] ?? '')), 0, 254);
                    $this->limiter->assertAvailable($action, $ip, $identity);
                    $this->limiter->hit($action, $ip, $identity);
                    $user = $identity !== '' ? $this->users->userByIdentifier($identity) : false;
                    if ($identity !== '' && $user === false) {
                        $this->failureDelay();
                        throw new RuntimeException('No hay una Passkey disponible para esa cuenta.');
                    }
                    $this->respond(['ok' => true, 'options' => $this->service->loginOptions($user !== false ? $user : null)]);

                case 'login-verify':
                    $this->limiter->assertAvailable($action, $ip);
                    $this->limiter->hit($action, $ip);
                    $result = $this->service->verifyLogin($payload);
                    $user = $this->users->userById((int) $result['user_id']);
                    if ($user === false || (int) ($user['cliente'] ?? 0) <= 0) {
                        throw new RuntimeException('La cuenta asociada ya no puede iniciar sesión.');
                    }
                    $user['_auth_method'] = 'passkey';
                    Auth::login($user);
                    $this->users->logLogin((string) $user['email'], true);
                    $this->respond(['ok' => true, 'redirect' => Http::url('/')]);

                case 'register-options':
                    $this->requireUserAndCsrf($payload);
                    $user = $this->currentUserRecord();
                    $this->respond(['ok' => true, 'options' => $this->service->registrationOptions($user)]);

                case 'register-verify':
                    $this->requireUserAndCsrf($payload);
                    $result = $this->service->verifyRegistration($payload, $this->currentUserRecord());
                    $this->respond(['ok' => true, 'data' => $result]);

                case 'rename':
                    $this->requireUserAndCsrf($payload);
                    if (!$this->passkeys->rename((int) ($payload['credential_id'] ?? 0), Auth::id(), (string) ($payload['name'] ?? ''))) {
                        throw new RuntimeException('La Passkey no existe o no pertenece a tu cuenta.');
                    }
                    $this->respond(['ok' => true, 'message' => 'Nombre actualizado.']);

                case 'delete':
                    $this->requireUserAndCsrf($payload);
                    $authTime = (int) (Auth::user()['authenticated_at'] ?? 0);
                    if ($authTime <= 0 || time() - $authTime > (int) $this->config['recent_auth_seconds']) {
                        throw new RuntimeException('Por seguridad, vuelve a iniciar sesión antes de eliminar una Passkey.');
                    }
                    $user = $this->currentUserRecord();
                    if ($this->passkeys->countForUser(Auth::id()) <= 1 && trim((string) ($user['password'] ?? '')) === '') {
                        throw new RuntimeException('No puedes eliminar tu único método de acceso sin configurar una alternativa.');
                    }
                    if (!$this->passkeys->delete((int) ($payload['credential_id'] ?? 0), Auth::id())) {
                        throw new RuntimeException('La Passkey no existe o no pertenece a tu cuenta.');
                    }
                    $this->respond(['ok' => true, 'message' => 'Passkey eliminada.']);

                default:
                    $this->respond(['ok' => false, 'message' => 'Operación WebAuthn no disponible.'], 404);
            }
        } catch (WebAuthnException $exception) {
            $this->auditFailure($action, $payload, $exception);
            $this->failureDelay();
            $this->respond(['ok' => false, 'message' => 'No fue posible verificar la Passkey. Solicita una nueva comprobación.'], 422);
        } catch (RuntimeException $exception) {
            $this->auditFailure($action, $payload, $exception);
            $this->failureDelay();
            $status = str_contains(mb_strtolower($exception->getMessage()), 'demasiad') ? 429 : 422;
            $this->respond(['ok' => false, 'message' => $exception->getMessage()], $status);
        } catch (Throwable $exception) {
            $this->auditFailure($action, $payload, $exception);
            $this->failureDelay();
            $this->respond(['ok' => false, 'message' => 'No fue posible completar la operación con la Passkey.'], 500);
        }
    }

    /** @param array<string, mixed> $payload */
    public static function assertCsrf(array $payload): void
    {
        $sent = (string) ($payload['csrf_token'] ?? '');
        if ($sent === '' || !hash_equals(Csrf::token(), $sent)) {
            throw new RuntimeException('La sesión del formulario venció. Recarga la página.');
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_contains($contentType, 'application/json')) {
            return $_POST;
        }
        $raw = file_get_contents('php://input');
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function requireUserAndCsrf(array $payload): void
    {
        if (!Auth::check()) {
            throw new RuntimeException('Debes iniciar sesión para administrar Passkeys.');
        }
        self::assertCsrf($payload);
    }

    /** @return array<string, mixed> */
    private function currentUserRecord(): array
    {
        $user = $this->users->userById(Auth::id());
        if ($user === false) {
            throw new RuntimeException('La cuenta autenticada ya no está disponible.');
        }
        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function auditFailure(string $action, array $payload, Throwable $exception): void
    {
        $clientData = [];
        $encoded = (string) ($payload['response']['clientDataJSON'] ?? '');
        if ($encoded !== '' && preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) === 1) {
            try {
                $parsed = json_decode(WebAuthnService::base64UrlDecode($encoded), true);
                $clientData = is_array($parsed) ? $parsed : [];
            } catch (Throwable) {
                $clientData = [];
            }
        }
        $context = [
            'event' => 'webauthn_failure',
            'action' => $action,
            'expected_origin' => (string) $this->config['origin'],
            'received_origin' => mb_substr((string) ($clientData['origin'] ?? ''), 0, 200),
            'rp_id' => (string) $this->config['rp_id'],
            'exception_class' => $exception::class,
        ];
        $statement = $this->pdo->prepare("INSERT INTO logsesiones (usuario,agent,ip,hora_i,fecha,tipo,activa) VALUES (:user,:agent,:ip,CURTIME(),CURDATE(),'WA Error','no')");
        $statement->execute([
            'user' => Auth::check() ? (string) (Auth::user()['email'] ?? 'autenticado') : 'publico',
            'agent' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 100),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    private function failureDelay(): void
    {
        usleep(random_int(75_000, 225_000));
    }

    private function assertSecureRequest(): void
    {
        $host = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return;
        }
        $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $secure = strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on'
            || strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https'
            || $forwarded === 'https';
        if (!$secure) {
            throw new RuntimeException('WebAuthn requiere una conexión HTTPS.');
        }
    }
}
