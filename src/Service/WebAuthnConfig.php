<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Core\Env;
use RuntimeException;

final class WebAuthnConfig
{
    /** @return array<string, mixed> */
    public static function load(): array
    {
        $enabled = Env::bool('WEBAUTHN_ENABLED');
        $origin = trim((string) Env::get('WEBAUTHN_ORIGIN', ''));
        $rpId = strtolower(trim((string) Env::get('WEBAUTHN_RP_ID', '')));
        $appUrl = trim((string) Env::get('APP_URL', ''));
        $verification = strtolower(trim((string) Env::get('WEBAUTHN_USER_VERIFICATION', 'required')));
        $secret = (string) Env::get('WEBAUTHN_HANDLE_SECRET', '');

        if ($enabled) {
            if (!hash_equals('https://dev2.sistema14.com', $origin)) {
                throw new RuntimeException('WEBAUTHN_ORIGIN debe ser exactamente https://dev2.sistema14.com.');
            }
            if (!hash_equals('sistema14.com', $rpId)) {
                throw new RuntimeException('WEBAUTHN_RP_ID debe ser exactamente sistema14.com.');
            }
            $parts = parse_url($origin);
            if (!is_array($parts)
                || ($parts['scheme'] ?? '') !== 'https'
                || ($parts['host'] ?? '') !== 'dev2.sistema14.com'
                || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || !in_array($parts['path'] ?? '', ['', '/'], true)) {
                throw new RuntimeException('El origen WebAuthn contiene componentes no permitidos.');
            }
            $appParts = parse_url($appUrl);
            $appOrigin = is_array($appParts)
                ? strtolower((string) ($appParts['scheme'] ?? '')) . '://' . strtolower((string) ($appParts['host'] ?? ''))
                : '';
            if (!hash_equals($origin, $appOrigin)) {
                throw new RuntimeException('APP_URL y WEBAUTHN_ORIGIN deben compartir el origen exacto.');
            }
            if (!hash_equals($origin, rtrim($appUrl, '/'))) {
                throw new RuntimeException('APP_URL no debe incluir rutas, consultas ni fragmentos.');
            }
            if (!hash_equals('S14DEV2SESSION', (string) Env::get('SESSION_NAME', ''))
                || trim((string) Env::get('SESSION_COOKIE_DOMAIN', '')) !== '') {
                throw new RuntimeException('La cookie de sesión de Dev2 no está aislada correctamente.');
            }
            if (!Env::bool('SESSION_SECURE_COOKIE') || !Env::bool('SESSION_COOKIE_HTTPONLY', true)) {
                throw new RuntimeException('WebAuthn requiere cookies Secure y HttpOnly.');
            }
            if (!hash_equals('Lax', (string) Env::get('SESSION_COOKIE_SAMESITE', ''))) {
                throw new RuntimeException('La cookie de Dev2 debe usar SameSite=Lax.');
            }
            if (!in_array($verification, ['required', 'preferred', 'discouraged'], true)) {
                throw new RuntimeException('WEBAUTHN_USER_VERIFICATION no es válido.');
            }
            if (strlen($secret) < 32) {
                throw new RuntimeException('WEBAUTHN_HANDLE_SECRET no está configurado correctamente.');
            }
            if (!class_exists(\lbuchs\WebAuthn\WebAuthn::class)) {
                throw new RuntimeException('La dependencia WebAuthn no está instalada.');
            }
        }

        return [
            'enabled' => $enabled,
            'rp_id' => $rpId,
            'rp_name' => trim((string) Env::get('WEBAUTHN_RP_NAME', 'Sistema 14')) ?: 'Sistema 14',
            'origin' => $origin,
            'handle_secret' => $secret,
            'timeout' => max(15, min(300, (int) Env::get('WEBAUTHN_TIMEOUT', '60000') / 1000)),
            'user_verification' => $verification,
            'challenge_ttl' => max(30, min(900, (int) Env::get('WEBAUTHN_CHALLENGE_TTL', '120'))),
            'enrollment_ttl' => max(30, min(900, (int) Env::get('WEBAUTHN_ENROLLMENT_TTL', '300'))),
            'max_credentials' => max(1, min(100, (int) Env::get('WEBAUTHN_MAX_CREDENTIALS', '20'))),
            'recent_auth_seconds' => max(60, min(3600, (int) Env::get('WEBAUTHN_RECENT_AUTH_SECONDS', '600'))),
            'max_options_per_minute' => max(1, min(120, (int) Env::get('WEBAUTHN_MAX_OPTIONS_PER_MINUTE', '30'))),
        ];
    }
}
