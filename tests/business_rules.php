<?php
declare(strict_types=1);

use Banca\Core\Database;
use Banca\Repository\BankingRepository;
use Banca\Service\TransferService;
use Banca\Service\WithdrawalConcept;

require dirname(__DIR__) . '/src/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(WithdrawalConcept::normalize('874503') === '874503', 'Una referencia numérica válida fue reemplazada.');
$assert(WithdrawalConcept::normalize(' 00124 ') === '00124', 'La referencia numérica no fue normalizada correctamente.');
$assert(WithdrawalConcept::normalize('Pago 874503') === 'Deposito', 'Un concepto alfanumérico no se reemplazó por Deposito.');
$assert(WithdrawalConcept::normalize('') === 'Deposito', 'El concepto vacío no se reemplazó por Deposito.');

$pdo = Database::connection();
$repository = new BankingRepository($pdo);
$leader = $pdo->query('SELECT origin.id, origin.grupo FROM clientes origin WHERE origin.activo = 1 AND origin.lidera = 1 AND origin.grupo <> 0 AND EXISTS (SELECT 1 FROM clientes destination WHERE destination.activo = 1 AND destination.grupo = origin.grupo AND destination.id <> origin.id) ORDER BY origin.id LIMIT 1')->fetch();
$assert($leader !== false, 'No existe un grupo con cartera líder para comprobar la regla de traspasos.');
$destinations = $repository->destinationClients((int) $leader['id']);
$assert($repository->canInitiateTransfers((int) $leader['id']), 'La cartera líder no fue reconocida como autorizada.');
$assert($destinations !== [], 'La cartera líder no recibió destinos de su grupo.');
foreach ($destinations as $destination) {
    $check = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE id = :id AND activo = 1 AND grupo = :group_id');
    $check->execute(['id' => $destination['id'], 'group_id' => $leader['grupo']]);
    $assert((int) $check->fetchColumn() === 1, 'Se expuso una cartera de otro grupo como destino.');
}

$nonLeader = $pdo->query('SELECT origin.id, destination.id destination_id FROM clientes origin JOIN clientes destination ON destination.grupo = origin.grupo AND destination.id <> origin.id AND destination.activo = 1 WHERE origin.activo = 1 AND origin.lidera <> 1 AND origin.grupo <> 0 ORDER BY origin.id LIMIT 1')->fetch();
$assert($nonLeader !== false, 'No existe una cartera no líder para comprobar la autorización.');
$assert($repository->destinationClients((int) $nonLeader['id']) === [], 'Una cartera no líder recibió destinos de traspaso.');
try {
    (new TransferService())->execute((int) $nonLeader['id'], (int) $nonLeader['destination_id'], 0, 0.01, 'Prueba de autorización');
    throw new RuntimeException('Una cartera no líder pudo iniciar un traspaso.');
} catch (RuntimeException $exception) {
    if (str_contains($exception->getMessage(), 'pudo iniciar')) {
        throw $exception;
    }
}

$crossGroup = $pdo->prepare('SELECT id FROM clientes WHERE activo = 1 AND grupo <> 0 AND grupo <> :group_id ORDER BY id LIMIT 1');
$crossGroup->execute(['group_id' => $leader['grupo']]);
$crossGroupId = $crossGroup->fetchColumn();
$assert($crossGroupId !== false, 'No existe una cartera de otro grupo para comprobar el aislamiento.');
try {
    (new TransferService())->execute((int) $leader['id'], (int) $crossGroupId, 0, 0.01, 'Prueba entre grupos');
    throw new RuntimeException('Una cartera líder pudo transferir a otro grupo.');
} catch (RuntimeException $exception) {
    if (str_contains($exception->getMessage(), 'pudo transferir')) {
        throw $exception;
    }
}

echo "Reglas de negocio correctas: traspasos por líder dentro del grupo y conceptos de retiro normalizados.\n";
