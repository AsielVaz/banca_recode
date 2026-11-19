<?php
declare(strict_types=1);

use Banca\Core\Database;
use Banca\Service\MovementRecorder;

require dirname(__DIR__) . '/src/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = Database::connection();
$candidate = $pdo->query("SELECT u.id AS user_id, u.cliente AS client_id, SUM(p.saldo_acreditable) AS balance FROM usuarios u JOIN pagos p ON p.nombre = u.cliente WHERE u.cliente > 0 GROUP BY u.id, u.cliente HAVING balance > 1 ORDER BY balance DESC LIMIT 1")->fetch();
$assert($candidate !== false, 'No existe un usuario con saldo para la prueba transaccional.');

$reference = '10HUM' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 15));
$account = '4152310000000000';
$pdo->beginTransaction();
try {
    $recorder = new MovementRecorder();
    $movement = [
        'name' => 'PRUEBA TRANSACCIONAL',
        'account' => $account,
        'amount' => 0.01,
        'concept' => 'Prueba automática con rollback',
        'email' => '',
        'phone' => '',
        'bank' => '012',
        'totp' => '000000',
    ];
    $provider = [
        'provider_id' => null,
        'status' => 'PENDIENTE',
        'message' => 'Prueba local.',
        'cep' => null,
        'queued' => true,
    ];
    $withdrawalId = $recorder->record(
        $pdo,
        (int) $candidate['user_id'],
        (int) $candidate['client_id'],
        $movement,
        $reference,
        $provider,
    );

    $repeatedId = $recorder->record(
        $pdo,
        (int) $candidate['user_id'],
        (int) $candidate['client_id'],
        $movement,
        $reference,
        $provider,
    );
    $assert($repeatedId === $withdrawalId, 'Un reintento creó un retiro diferente.');

    $statement = $pdo->prepare('SELECT w.id, w.id_pago, w.id_captura_edo, w.status, w.status_banxico, w.estado, w.status_pre, w.espera_liquidacion, w.concepto, p.id_captura_edo_cta, p.monto, p.saldo_acreditable, p.descripcion, p.descripcion_detallada, c.referencia, c.descripcion AS captura_descripcion FROM pre_ejecuta_plantilla w JOIN pagos p ON p.id = w.id_pago JOIN captura_edo_cta c ON c.id = w.id_captura_edo WHERE w.id = :id');
    $statement->execute(['id' => $withdrawalId]);
    $linked = $statement->fetch();
    $assert($linked !== false, 'No se crearon los tres registros relacionados.');
    $assert((int) $linked['id_captura_edo'] === (int) $linked['id_captura_edo_cta'], 'El pago apunta a una captura distinta.');
    $assert((string) $linked['referencia'] === $reference, 'La captura no conserva la referencia idempotente.');
    $assert((string) $linked['status'] === '0' && $linked['status_banxico'] === null && $linked['estado'] === null && $linked['status_pre'] === null && (int) $linked['espera_liquidacion'] === 0, 'El retiro no quedó en el estado inicial que consume la cola SPEI histórica.');
    $assert((string) $linked['concepto'] === 'Deposito', 'Un concepto textual no fue normalizado antes de registrarse.');
    $assert((float) $linked['monto'] === 0.01 && (float) $linked['saldo_acreditable'] === -0.01, 'El cargo contable no conserva los signos esperados.');
    $descriptions = (string) $linked['descripcion'] . (string) $linked['descripcion_detallada'] . (string) $linked['captura_descripcion'];
    $assert(!str_contains($descriptions, $account), 'Un número de tarjeta completo quedó guardado en las descripciones.');

    $notification = $pdo->prepare('SELECT COUNT(*) FROM mailer_movimientos WHERE id_movimiento = :payment AND cliente = :client');
    $notification->execute(['payment' => $linked['id_pago'], 'client' => $candidate['client_id']]);
    $assert((int) $notification->fetchColumn() === 1, 'No se creó la notificación enlazada al pago.');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$check = $pdo->prepare('SELECT COUNT(*) FROM pre_ejecuta_plantilla WHERE folio = :reference');
$check->execute(['reference' => $reference]);
$assert((int) $check->fetchColumn() === 0, 'La prueba dejó información persistida.');

echo "Registro transaccional correcto: retiro, pago y captura enlazados; rollback verificado.\n";
