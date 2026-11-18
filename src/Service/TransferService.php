<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Core\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class TransferService
{
    public function execute(int $originClient, int $destinationClient, int $userId, float $amount, string $detail): void
    {
        if ($originClient === $destinationClient) {
            throw new InvalidArgumentException('La cartera de destino debe ser diferente a la de origen.');
        }
        Database::transaction(function (PDO $pdo) use ($originClient, $destinationClient, $userId, $amount, $detail): void {
            $clients = $pdo->prepare('SELECT id, activo FROM clientes WHERE id IN (:origin, :destination) FOR UPDATE');
            $clients->execute(['origin' => $originClient, 'destination' => $destinationClient]);
            $found = $clients->fetchAll();
            if (count($found) !== 2 || array_filter($found, static fn (array $row): bool => (int) $row['activo'] !== 1)) {
                throw new RuntimeException('Una de las carteras no existe o está inactiva.');
            }

            $lock = $pdo->prepare('SELECT id FROM pagos WHERE nombre = :client ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $lock->execute(['client' => $originClient]);
            $lock->fetchColumn();
            $balanceStatement = $pdo->prepare('SELECT COALESCE(SUM(saldo_acreditable), 0) FROM pagos WHERE nombre = :client');
            $balanceStatement->execute(['client' => $originClient]);
            $originBalance = round((float) $balanceStatement->fetchColumn(), 2);
            if ($amount > $originBalance) {
                throw new RuntimeException('Saldo insuficiente para completar el traspaso.');
            }
            $balanceStatement->execute(['client' => $destinationClient]);
            $destinationBalance = round((float) $balanceStatement->fetchColumn(), 2);

            $sql = "INSERT INTO pagos (nombre,monto,factura,empresa,status,fecha_asignacion,fecha_captura,tipo_movimiento,saldo_acreditable,banco,saldo,usuario_registra,fecha_banco,detalle,nombre_corto,tipo_captura,descripcion,origen_traspaso,destino_traspaso,cliente_origen_comision) VALUES (:client,:amount,'NA','99','asignado',CURDATE(),CURDATE(),:type,:credit,'99',:balance,:user,CURDATE(),:detail,'99','Manual','Traspaso',:origin,:destination,:commission)";
            $insert = $pdo->prepare($sql);
            $insert->execute([
                'client' => $destinationClient, 'amount' => $amount, 'type' => 'Deposito', 'credit' => $amount,
                'balance' => $destinationBalance + $amount, 'user' => $userId, 'detail' => $detail,
                'origin' => $originClient, 'destination' => $destinationClient, 'commission' => $originClient,
            ]);
            $insert->execute([
                'client' => $originClient, 'amount' => -$amount, 'type' => 'Retiro', 'credit' => -$amount,
                'balance' => $originBalance - $amount, 'user' => $userId, 'detail' => $detail,
                'origin' => $originClient, 'destination' => $destinationClient, 'commission' => $destinationClient,
            ]);
            $notify = $pdo->prepare("INSERT INTO mailer_movimientos (cliente,fecha,hora,enviado,mailer_tipo) VALUES (:client,CURDATE(),CURTIME(),'no','Banca')");
            $notify->execute(['client' => $originClient]);
            $notify->execute(['client' => $destinationClient]);
        });
    }
}

