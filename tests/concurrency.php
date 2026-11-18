<?php
declare(strict_types=1);

use Banca\Core\Env;

require dirname(__DIR__) . '/src/bootstrap.php';

$connect = static function (): PDO {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        Env::get('DB_HOST', '127.0.0.1'),
        Env::get('DB_PORT', '3306'),
        Env::get('DB_DATABASE', ''),
        Env::get('DB_CHARSET', 'utf8mb4'),
    );
    return new PDO($dsn, Env::get('DB_USERNAME', ''), Env::get('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
};

$first = $connect();
$second = $connect();
$clientA = 'b14:test:client:' . bin2hex(random_bytes(8));
$clientB = 'b14:test:client:' . bin2hex(random_bytes(8));

$getLock = static function (PDO $pdo, string $name, int $timeout): int {
    $statement = $pdo->prepare('SELECT GET_LOCK(:name, :timeout)');
    $statement->bindValue('name', $name);
    $statement->bindValue('timeout', $timeout, PDO::PARAM_INT);
    $statement->execute();
    return (int) $statement->fetchColumn();
};
$release = static function (PDO $pdo, string $name): void {
    $statement = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
    $statement->execute(['name' => $name]);
};

try {
    if ($getLock($first, $clientA, 0) !== 1) {
        throw new RuntimeException('No fue posible adquirir el bloqueo del primer cliente.');
    }
    if ($getLock($second, $clientB, 0) !== 1) {
        throw new RuntimeException('Un cliente distinto quedó bloqueado incorrectamente.');
    }
    if ($getLock($second, $clientA, 0) !== 0) {
        throw new RuntimeException('Dos conexiones adquirieron simultáneamente el bloqueo del mismo cliente.');
    }
} finally {
    $release($first, $clientA);
    $release($second, $clientB);
}

echo "Concurrencia correcta: clientes distintos operan en paralelo y el mismo cliente queda serializado entre conexiones.\n";
