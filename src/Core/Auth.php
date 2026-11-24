<?php
declare(strict_types=1);

namespace Banca\Core;

final class Auth
{
    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        return isset($_SESSION['auth']) && is_array($_SESSION['auth']) ? $_SESSION['auth'] : null;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function clientId(): int
    {
        return (int) (self::user()['client_id'] ?? 0);
    }

    public static function check(): bool
    {
        $user = self::user();
        if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0 || (int) ($user['client_id'] ?? 0) <= 0) {
            return false;
        }

        $now = time();
        $authenticatedAt = (int) ($user['authenticated_at'] ?? 0);
        $lastActivity = (int) ($user['last_activity'] ?? 0);
        $idleTimeout = max(300, (int) Env::get('SESSION_IDLE_TIMEOUT', '1800'));
        $absoluteTimeout = max($idleTimeout, (int) Env::get('SESSION_ABSOLUTE_TIMEOUT', '7200'));
        if ($authenticatedAt <= 0 || $lastActivity <= 0
            || $now - $lastActivity > $idleTimeout
            || $now - $authenticatedAt > $absoluteTimeout) {
            self::logout();
            return false;
        }

        $_SESSION['auth']['last_activity'] = $now;
        return true;
    }

    public static function requireUser(): void
    {
        if (!self::check()) {
            Http::redirect('/login');
        }
    }

    /** @param array<string, mixed> $user */
    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $now = time();
        $_SESSION['auth'] = [
            'id' => (int) $user['id'],
            'client_id' => (int) $user['cliente'],
            'email' => (string) $user['email'],
            'name' => trim(implode(' ', array_filter([$user['nombre'] ?? '', $user['appat'] ?? '', $user['apmat'] ?? '']))),
            'type' => (string) ($user['tipo'] ?? ''),
            'method' => (string) ($user['_auth_method'] ?? 'password'),
            'authenticated_at' => $now,
            'last_activity' => $now,
        ];
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
}

