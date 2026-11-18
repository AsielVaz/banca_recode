<?php
declare(strict_types=1);

namespace Banca\Core;

final class Http
{
    public static function route(): string
    {
        $route = $_GET['route'] ?? '/';
        return '/' . trim(is_string($route) ? $route : '/', '/');
    }

    public static function redirect(string $route): never
    {
        header('Location: ' . self::url($route));
        exit;
    }

    public static function url(string $route = '/'): string
    {
        $configured = trim((string) Env::get('APP_URL', ''));
        $base = $configured !== ''
            ? rtrim($configured, '/')
            : rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/.');
        $route = '/' . ltrim($route, '/');
        return $base . '/index.php?route=' . rawurlencode($route);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }
}

