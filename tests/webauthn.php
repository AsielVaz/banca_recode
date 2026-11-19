<?php
declare(strict_types=1);

use Banca\Core\Csrf;
use Banca\Core\Database;
use Banca\Core\Env;
use Banca\Core\Auth;
use Banca\Repository\BankingRepository;
use Banca\Repository\PasskeyRepository;
use Banca\Service\WebAuthnConfig;
use Banca\Service\WebAuthnController;
use Banca\Service\WebAuthnRateLimiter;
use Banca\Service\WebAuthnService;

require dirname(__DIR__) . '/src/bootstrap.php';

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
};
$expectFailure = static function (callable $callback, string $fragment, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $exception) {
        $assert(str_contains(mb_strtolower($exception->getMessage()), mb_strtolower($fragment)), $message);
        return;
    }
    throw new RuntimeException($message);
};

$_SERVER['HTTP_USER_AGENT'] = 'Sistema14 WebAuthn automated compatibility test';
$pdo = Database::connection();
$config = WebAuthnConfig::load();
$repository = new PasskeyRepository($pdo);
$users = new BankingRepository($pdo);
$service = new WebAuthnService($config, $repository);

$assert($config['enabled'] === true, 'WebAuthn no está habilitado.');
$assert($config['origin'] === 'https://dev2.sistema14.com', 'El origen de Dev2 no es exacto.');
$assert($config['rp_id'] === 'sistema14.com', 'El RP ID compartido no es correcto.');
$assert($config['user_verification'] === 'required', 'La verificación de usuario no es obligatoria.');
$assert($config['challenge_ttl'] === 120 && $config['enrollment_ttl'] === 300, 'Los TTL no coinciden con la configuración solicitada.');
$assert(strlen((string) $config['handle_secret']) >= 32, 'El secreto compartido no está configurado.');
$assert(class_exists(lbuchs\WebAuthn\WebAuthn::class), 'La biblioteca lbuchs/webauthn no está disponible.');

$rows = $pdo->query('SELECT id,id_usuario,credential_id,credential_hash,public_key,user_handle,sign_count,backup_eligible,backup_state,ultimo_uso FROM usuarios_passkeys ORDER BY id')->fetchAll();
$assert($rows !== [], 'No hay Passkeys existentes para comprobar compatibilidad con Dev1.');
foreach ($rows as $row) {
    $assert(hash_equals((string) $row['credential_hash'], hash('sha256', (string) $row['credential_id'], true)), 'Un hash binario no corresponde a su credential_id.');
    $assert(openssl_pkey_get_public((string) $row['public_key']) !== false, 'Una clave pública existente no está en formato PEM compatible.');
    $found = $repository->findByCredentialId((string) $row['credential_id']);
    $assert(is_array($found) && (int) $found['id'] === (int) $row['id'], 'La búsqueda segura no recuperó una Passkey existente.');
    $assert(hash_equals((string) $row['user_handle'], $repository->stableUserHandle((int) $row['id_usuario'], (string) $config['handle_secret'])), 'No se reutilizó literalmente el user_handle existente.');
}

$first = $rows[0];
$firstUser = $users->userById((int) $first['id_usuario']);
$assert($firstUser !== false, 'El usuario de la Passkey existente no está disponible.');
$registration = $service->registrationOptions($firstUser);
$selection = $registration['publicKey']['authenticatorSelection'] ?? [];
$assert(($registration['publicKey']['rp']['id'] ?? '') === 'sistema14.com', 'El registro no usa el RP ID compartido.');
$assert(($selection['residentKey'] ?? '') === 'required' && ($selection['userVerification'] ?? '') === 'required', 'El registro no exige credencial descubrible y verificación de usuario.');
$assert(($registration['publicKey']['attestation'] ?? '') === 'none', 'La atestación no está configurada en none.');
$assert(count($registration['publicKey']['excludeCredentials'] ?? []) === $repository->countForUser((int) $first['id_usuario']), 'No se excluyeron las Passkeys existentes del usuario.');

$login = $service->loginOptions($firstUser);
$assert(($login['publicKey']['rpId'] ?? '') === 'sistema14.com', 'El login no usa el RP ID compartido.');
$assert(count($login['publicKey']['allowCredentials'] ?? []) === $repository->countForUser((int) $first['id_usuario']), 'El login restringido no contiene las credenciales existentes.');
$discoverable = $service->loginOptions();
$assert(!isset($discoverable['publicKey']['allowCredentials']), 'El login descubrible restringió indebidamente las credenciales.');

$b64 = [WebAuthnService::class, 'base64UrlEncode'];
$payload = static function (array $credential, string $challenge, string $origin, bool $crossOrigin = false) use ($b64): array {
    $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => $crossOrigin], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    return [
        'id' => $b64((string) $credential['credential_id']),
        'rawId' => $b64((string) $credential['credential_id']),
        'response' => [
            'clientDataJSON' => $b64($clientData),
            'authenticatorData' => $b64(hash('sha256', 'sistema14.com', true) . chr(0x05) . pack('N', (int) $credential['sign_count'] + 1)),
            'signature' => $b64(random_bytes(64)),
            'userHandle' => $b64((string) $credential['user_handle']),
        ],
    ];
};

foreach (['https://dev1.sistema14.com', 'http://localhost', 'https://otro.example'] as $invalidOrigin) {
    $options = $service->loginOptions($firstUser);
    $expectFailure(fn () => $service->verifyLogin($payload($first, (string) $options['publicKey']['challenge'], $invalidOrigin)), 'origen', 'Se aceptó un origen distinto de Dev2.');
}
$crossOrigin = $service->loginOptions($firstUser);
$expectFailure(fn () => $service->verifyLogin($payload($first, (string) $crossOrigin['publicKey']['challenge'], $config['origin'], true)), 'origen', 'Se aceptó crossOrigin=true.');

$expired = $service->loginOptions($firstUser);
$_SESSION['_webauthn_challenges']['login']['expires_at'] = time() - 1;
$expectFailure(fn () => $service->verifyLogin($payload($first, (string) $expired['publicKey']['challenge'], $config['origin'])), 'venció', 'Se aceptó un desafío vencido.');
$expectFailure(fn () => $service->verifyLogin($payload($first, (string) $expired['publicKey']['challenge'], $config['origin'])), 'venció', 'Un desafío pudo utilizarse dos veces.');

$invalidSignature = $service->loginOptions($firstUser);
$expectFailure(fn () => $service->verifyLogin($payload($first, (string) $invalidSignature['publicKey']['challenge'], $config['origin'])), 'signature', 'Se aceptó una firma modificada.');

$other = null;
foreach ($rows as $candidate) {
    if ((int) $candidate['id_usuario'] !== (int) $first['id_usuario']) {
        $other = $candidate;
        break;
    }
}
$assert(is_array($other), 'No existe una Passkey de otro usuario para comprobar el aislamiento.');
$restricted = $service->loginOptions($firstUser);
$expectFailure(fn () => $service->verifyLogin($payload($other, (string) $restricted['publicKey']['challenge'], $config['origin'])), 'cuenta seleccionada', 'Una Passkey de otro usuario superó un login restringido.');

$beforeCount = count($rows);
$pdo->beginTransaction();
try {
    $repository->updateUsage((int) $first['id'], (int) $first['sign_count'], !(bool) $first['backup_state'], (bool) $first['backup_eligible'] ? null : (int) $first['sign_count']);
    $usage = $pdo->prepare('SELECT sign_count,ultimo_uso FROM usuarios_passkeys WHERE id=:id');
    $usage->execute(['id' => $first['id']]);
    $updated = $usage->fetch();
    $assert((int) $updated['sign_count'] === (int) $first['sign_count'] && $updated['ultimo_uso'] !== null, 'No se actualizaron contador y último uso de forma controlada.');
} finally {
    $pdo->rollBack();
}
$assert((int) $pdo->query('SELECT COUNT(*) FROM usuarios_passkeys')->fetchColumn() === $beforeCount, 'La prueba alteró las Passkeys existentes.');

$_SESSION['_csrf'] = 'csrf-correcto-para-prueba';
$expectFailure(fn () => WebAuthnController::assertCsrf(['csrf_token' => 'csrf-incorrecto']), 'sesión', 'Se aceptó un CSRF inválido.');
WebAuthnController::assertCsrf(['csrf_token' => Csrf::token()]);
$assert(true, 'El CSRF válido fue aceptado.');

$pdo->beginTransaction();
try {
    $limiter = new WebAuthnRateLimiter($pdo, 2);
    $testIp = '198.51.100.' . random_int(1, 250);
    $limiter->hit('test-options', $testIp, 'rate@example.test');
    $limiter->hit('test-options', $testIp, 'rate@example.test');
    $expectFailure(fn () => $limiter->assertAvailable('test-options', $testIp, 'rate@example.test'), 'demasiados', 'El rate limiting no bloqueó el exceso de opciones.');
} finally {
    $pdo->rollBack();
}

$assert(Env::get('SESSION_NAME') === 'S14DEV2SESSION', 'Dev2 no usa una cookie de sesión independiente.');
$assert(Env::bool('SESSION_SECURE_COOKIE') && Env::bool('SESSION_COOKIE_HTTPONLY'), 'La cookie de sesión no está protegida.');
$assert(Env::get('SESSION_COOKIE_DOMAIN', '') === '' && Env::get('SESSION_COOKIE_SAMESITE') === 'Lax', 'El dominio o SameSite de la cookie no es correcto.');

$controller = new WebAuthnController($pdo, $config, $users, $repository, $service);
$pdo->beginTransaction();
try {
    $sensitiveChallenge = 'challenge-no-debe-aparecer';
    $sensitiveSignature = 'signature-no-debe-aparecer';
    $sensitiveCredential = 'credential-no-debe-aparecer';
    $clientData = WebAuthnService::base64UrlEncode(json_encode([
        'type' => 'webauthn.get', 'challenge' => $sensitiveChallenge, 'origin' => 'https://dev1.sistema14.com',
    ], JSON_THROW_ON_ERROR));
    $audit = Closure::bind(function (array $payload): void {
        $this->auditFailure('test-audit', $payload, new RuntimeException('fallo controlado'));
    }, $controller, WebAuthnController::class);
    $audit(['rawId' => $sensitiveCredential, 'response' => ['clientDataJSON' => $clientData, 'signature' => $sensitiveSignature]]);
    $log = (string) $pdo->query("SELECT agent FROM logsesiones WHERE tipo='WA Error' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $assert(str_contains($log, 'expected_origin') && str_contains($log, 'received_origin') && str_contains($log, 'rp_id'), 'El log no contiene el contexto seguro requerido.');
    $assert(!str_contains($log, $sensitiveChallenge) && !str_contains($log, $sensitiveSignature) && !str_contains($log, $sensitiveCredential), 'El log contiene material criptográfico sensible.');
    $assert(!str_contains($log, (string) $config['handle_secret']), 'El log contiene el secreto de user_handle.');
} finally {
    $pdo->rollBack();
}

Auth::login($firstUser);
$assert((Auth::user()['method'] ?? '') === 'password', 'El login tradicional dejó de establecer la sesión normal.');
$passkeyUser = $firstUser;
$passkeyUser['_auth_method'] = 'passkey';
Auth::login($passkeyUser);
$assert((Auth::user()['method'] ?? '') === 'passkey' && (int) (Auth::user()['authenticated_at'] ?? 0) > 0, 'El login Passkey no establece autenticación fuerte reciente.');

echo "WebAuthn correcto: {$passed} comprobaciones superadas; credenciales existentes sin cambios.\n";
