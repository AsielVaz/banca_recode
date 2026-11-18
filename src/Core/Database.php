<?php
declare(strict_types=1);

namespace Banca\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }
        $driver = Env::get('DB_DRIVER', 'mysql');
        if ($driver !== 'mysql') {
            throw new RuntimeException('Esta aplicación requiere el controlador MySQL.');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            Env::get('DB_HOST', '127.0.0.1'),
            Env::get('DB_PORT', '3306'),
            Env::get('DB_DATABASE', ''),
            Env::get('DB_CHARSET', 'utf8mb4')
        );
        try {
            self::$connection = new PDO($dsn, Env::get('DB_USERNAME', ''), Env::get('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            self::$connection->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            self::$connection->exec('SET SESSION innodb_lock_wait_timeout = 5');
        } catch (PDOException $exception) {
            throw new RuntimeException('No fue posible conectar con la base de datos.', 0, $exception);
        }
        return self::$connection;
    }

    public static function transaction(callable $callback, int $maxAttempts = 3): mixed
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('No se permiten transacciones anidadas.');
        }
        $maxAttempts = max(1, min($maxAttempts, 5));
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $pdo->beginTransaction();
            try {
                $result = $callback($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($attempt >= $maxAttempts || !self::isRetryable($exception)) {
                    throw $exception;
                }
                usleep(random_int(25_000, 90_000) * $attempt);
            }
        }
        throw new RuntimeException('No fue posible completar la transacción.');
    }

    private static function isRetryable(Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }
        $driverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;
        return $exception->getCode() === '40001' || in_array($driverCode, [1205, 1213], true);
    }
}

