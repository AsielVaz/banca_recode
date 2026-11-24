<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Core\Env;
use RuntimeException;

final class OperationToken
{
    private const LIFETIME = 7200;
    private const MAX_TOKENS_PER_SCOPE = 10;
    private const SOURCE_PREFIXES = [1 => 'DE', 2 => 'WK', 3 => 'EN', 4 => 'ZA', 5 => 'TO', 6 => 'KU', 7 => 'UM'];

    public static function issue(string $scope): string
    {
        self::prune();
        $token = bin2hex(random_bytes(16));
        $_SESSION['_operations'][$scope][$token] = time();
        if (count($_SESSION['_operations'][$scope]) > self::MAX_TOKENS_PER_SCOPE) {
            asort($_SESSION['_operations'][$scope]);
            $_SESSION['_operations'][$scope] = array_slice(
                $_SESSION['_operations'][$scope],
                -self::MAX_TOKENS_PER_SCOPE,
                null,
                true,
            );
        }
        return $token;
    }

    public static function reference(string $scope, mixed $token, int $sequence = 0): string
    {
        self::prune();
        $token = is_string($token) ? $token : '';
        $issued = $_SESSION['_operations'][$scope][$token] ?? null;
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_int($issued)) {
            throw new RuntimeException('La autorización de la operación expiró. Actualiza la página antes de intentarlo de nuevo.');
        }
        $key = trim((string) Env::get('APP_KEY', ''));
        $normalizedKey = mb_strtolower($key);
        if (strlen($key) < 32 || preg_match('/change|replace|example|placeholder|changeme/', $normalizedKey) === 1) {
            throw new RuntimeException('APP_KEY debe ser un secreto aleatorio de al menos 32 caracteres antes de procesar retiros.');
        }
        $digest = strtoupper(hash_hmac('sha256', $scope . '|' . $token . '|' . $sequence, $key));
        $source = (int) Env::get('WITHDRAWAL_SOURCE', '7');
        $prefix = self::SOURCE_PREFIXES[$source] ?? null;
        if ($prefix === null) {
            throw new RuntimeException('WITHDRAWAL_SOURCE no corresponde a una salida bancaria permitida.');
        }

        // Conserva la familia de folios 10Hxx usada por el procesador legado,
        // pero evita el inseguro SELECT MAX(id) mediante un HMAC idempotente.
        return '10H' . $prefix . substr($digest, 0, 15);
    }

    public static function isValidReference(string $reference): bool
    {
        // Se aceptan folios R ya emitidos a formularios abiertos antes de esta
        // actualización, además del formato compatible con la cola histórica.
        return preg_match('/^(?:R[A-F0-9]{18}|10H(?:DE|WK|EN|ZA|TO|KU|UM)[A-F0-9]{15})$/', $reference) === 1;
    }

    public static function complete(string $scope, mixed $token): void
    {
        if (is_string($token)) {
            unset($_SESSION['_operations'][$scope][$token]);
        }
    }

    private static function prune(): void
    {
        $operations = $_SESSION['_operations'] ?? [];
        if (!is_array($operations)) {
            $_SESSION['_operations'] = [];
            return;
        }
        $minimum = time() - self::LIFETIME;
        foreach ($operations as $scope => $tokens) {
            if (!is_array($tokens)) {
                unset($operations[$scope]);
                continue;
            }
            foreach ($tokens as $token => $issued) {
                if (!is_int($issued) || $issued < $minimum) {
                    unset($tokens[$token]);
                }
            }
            if ($tokens === []) {
                unset($operations[$scope]);
            } else {
                $operations[$scope] = $tokens;
            }
        }
        $_SESSION['_operations'] = $operations;
    }
}
