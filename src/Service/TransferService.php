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
        $detail = self::normalizeConcept($detail);
        if ($originClient === $destinationClient) {
            throw new InvalidArgumentException('La cartera de destino debe ser diferente a la de origen.');
        }
        Database::transaction(function (PDO $pdo) use ($originClient, $destinationClient, $userId, $amount, $detail): void {
            // Se bloquean en orden estable y se vuelve a validar aquí para que
            // ni un POST manipulado ni un cambio concurrente evadan la regla.
            $clients = $pdo->prepare('SELECT id, activo, grupo, lidera FROM clientes WHERE id IN (:origin, :destination) ORDER BY id FOR UPDATE');
            $clients->execute(['origin' => $originClient, 'destination' => $destinationClient]);
            $found = [];
            foreach ($clients->fetchAll() as $client) {
                $found[(int) $client['id']] = $client;
            }
            $origin = $found[$originClient] ?? null;
            $destination = $found[$destinationClient] ?? null;
            if ($origin === null || $destination === null || (int) $origin['activo'] !== 1 || (int) $destination['activo'] !== 1) {
                throw new RuntimeException('Una de las carteras no existe o está inactiva.');
            }
            $originGroup = (int) ($origin['grupo'] ?? 0);
            if ((int) $origin['lidera'] !== 1 || $originGroup === 0) {
                throw new RuntimeException('Sólo una cartera líder de un grupo puede realizar traspasos.');
            }
            if ((int) ($destination['grupo'] ?? 0) !== $originGroup) {
                throw new RuntimeException('El traspaso sólo puede realizarse entre carteras del mismo grupo.');
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

    public static function normalizeConcept(mixed $concept): string
    {
        $concept = trim(is_string($concept) ? $concept : '');
        return $concept === '' ? 'Deposito' : $concept;
    }
}

