<?php
declare(strict_types=1);

use Banca\Core\Auth;
use Banca\Core\Database;
use Banca\Core\Env;
use Banca\Repository\BankingRepository;

require dirname(__DIR__) . '/src/bootstrap.php';

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
};

$index = file_get_contents(dirname(__DIR__) . '/public/index.php') ?: '';
foreach ([
    'Strict-Transport-Security',
    'Cross-Origin-Opener-Policy',
    'Content-Security-Policy',
    'X-Content-Type-Options',
    'X-Frame-Options',
    'Referrer-Policy',
] as $header) {
    $assert(str_contains($index, $header), "Falta la cabecera {$header}.");
}
$assert(!str_contains($index, "'unsafe-inline'"), 'La CSP permite contenido inline inseguro.');

$htaccess = file_get_contents(dirname(__DIR__) . '/.htaccess') ?: '';
foreach (['src|views|vendor|storage|tests|database|docs', 'composer\\.(?:json|lock)', 'Options -Indexes'] as $rule) {
    $assert(str_contains($htaccess, $rule), "Falta una regla de protección: {$rule}");
}

$key = trim((string) Env::get('APP_KEY', ''));
$assert(strlen($key) >= 32, 'APP_KEY no tiene longitud suficiente.');
$assert(preg_match('/change|replace|example|placeholder|changeme/i', $key) !== 1, 'APP_KEY conserva un valor de ejemplo.');
$assert((int) Env::get('SESSION_IDLE_TIMEOUT', '0') >= 300, 'No se configuró el tiempo de inactividad.');
$assert((int) Env::get('SESSION_ABSOLUTE_TIMEOUT', '0') >= 300, 'No se configuró la duración absoluta.');
$assert((int) Env::get('SESSION_ABSOLUTE_TIMEOUT', '0') >= (int) Env::get('SESSION_IDLE_TIMEOUT', '0'), 'La duración absoluta es menor al tiempo de inactividad.');

$pdo = Database::connection();
$repository = new BankingRepository($pdo);
$pdo->beginTransaction();
try {
    $email = 'security-' . bin2hex(random_bytes(6)) . '@example.test';
    $ip = '198.51.100.' . random_int(1, 254);
    $_SERVER['REMOTE_ADDR'] = $ip;
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $repository->logLogin($email, false);
    }
    $assert($repository->failedLogins($email, '203.0.113.10') >= 6, 'El límite por cuenta no detectó los intentos.');
    $assert($repository->failedLogins('other@example.test', $ip) >= 6, 'El límite por IP no detectó los intentos.');
} finally {
    $pdo->rollBack();
}

Auth::login([
    'id' => 1,
    'cliente' => 1,
    'email' => 'session@example.test',
    'nombre' => 'Prueba',
]);
$_SESSION['auth']['last_activity'] = time() - ((int) Env::get('SESSION_IDLE_TIMEOUT', '1800') + 1);
$assert(Auth::check() === false, 'Una sesión inactiva continuó autenticada.');

echo "Seguridad correcta: {$passed} controles verificados.\n";
