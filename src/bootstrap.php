<?php
declare(strict_types=1);

use Banca\Core\Env;

define('BANCA_ROOT', dirname(__DIR__));

$composerAutoload = BANCA_ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Banca\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = BANCA_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

Env::load(BANCA_ROOT . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'America/Mexico_City'));

if (Env::bool('APP_DEBUG')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name((string) Env::get('SESSION_NAME', 'banca14_recode'));
$sameSite = (string) Env::get('SESSION_COOKIE_SAMESITE', 'Lax');
if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
    $sameSite = 'Lax';
}
$cookie = [
    'lifetime' => max(300, (int) Env::get('SESSION_ABSOLUTE_TIMEOUT', '7200')),
    'path' => '/',
    'secure' => Env::bool('SESSION_SECURE_COOKIE', Env::bool('SESSION_SECURE')),
    'httponly' => Env::bool('SESSION_COOKIE_HTTPONLY', true),
    'samesite' => $sameSite,
];
ini_set('session.gc_maxlifetime', (string) $cookie['lifetime']);
$cookieDomain = trim((string) Env::get('SESSION_COOKIE_DOMAIN', ''));
if ($cookieDomain !== '') {
    $cookie['domain'] = $cookieDomain;
}
session_set_cookie_params($cookie);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

set_exception_handler(static function (Throwable $exception): void {
    $line = sprintf("[%s] %s: %s in %s:%d\n", date(DATE_ATOM), $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine());
    error_log($line, 3, BANCA_ROOT . '/storage/logs/app.log');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $line);
        exit(1);
    }
    http_response_code(500);
    $message = Env::bool('APP_DEBUG') ? $exception->getMessage() : 'Ocurrió un error inesperado. Inténtalo nuevamente.';
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><style>body{font:16px system-ui;max-width:720px;margin:10vh auto;padding:2rem;color:#17202a}a{color:#1956d1}</style><h1>No pudimos completar la solicitud</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><a href="' . htmlspecialchars((string) Env::get('APP_URL', '/'), ENT_QUOTES, 'UTF-8') . '">Volver</a></p>';
});

