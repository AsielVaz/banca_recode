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
        return self::id() > 0 && self::clientId() > 0;
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
        $_SESSION['auth'] = [
            'id' => (int) $user['id'],
            'client_id' => (int) $user['cliente'],
            'email' => (string) $user['email'],
            'name' => trim(implode(' ', array_filter([$user['nombre'] ?? '', $user['appat'] ?? '', $user['apmat'] ?? '']))),
            'type' => (string) ($user['tipo'] ?? ''),
        ];
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

