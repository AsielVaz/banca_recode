<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Core\Env;
use PDO;
use RuntimeException;

final class MovementRecorder
{
    /** @var array<int, array{company:int,account:int}> */
    private const SOURCE_ACCOUNTS = [
        1 => ['company' => 103, 'account' => 67],
        2 => ['company' => 122, 'account' => 114],
        3 => ['company' => 108, 'account' => 117],
        4 => ['company' => 10, 'account' => 127],
        5 => ['company' => 127, 'account' => 130],
        6 => ['company' => 127, 'account' => 141],
        7 => ['company' => 106, 'account' => 166],
    ];
    /**
     * @param array{name:string,account:string,amount:float,concept:string,email:string,phone:string,bank:string,totp:string} $data
     * @param array{provider_id:?int,status:string,message:string,cep:?string,queued?:bool} $provider
     * @param array{company:int,account:int}|null $source
     */
    public function record(PDO $pdo, int $userId, int $clientId, array $data, string $reference, array $provider, ?array $source = null): int
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('El movimiento debe registrarse dentro de una transacción.');
        }
        if (!OperationToken::isValidReference($reference)) {
            throw new RuntimeException('La referencia idempotente no es válida.');
        }
        $data['concept'] = WithdrawalConcept::normalize($data['concept'] ?? '');

        $existing = $pdo->prepare('SELECT id FROM pre_ejecuta_plantilla WHERE cliente = :client AND folio = :reference LIMIT 1 FOR UPDATE');
        $existing->execute(['client' => $clientId, 'reference' => $reference]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            return (int) $existingId;
        }

        $lock = $pdo->prepare('SELECT id FROM pagos WHERE nombre = :client ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $lock->execute(['client' => $clientId]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('El cliente no tiene una cuenta de saldo inicializada.');
        }
        $sum = $pdo->prepare('SELECT COALESCE(SUM(saldo_acreditable),0) FROM pagos WHERE nombre = :client');
        $sum->execute(['client' => $clientId]);
        $balance = round((float) $sum->fetchColumn(), 2);
        if ($data['amount'] > $balance) {
            throw new RuntimeException('Saldo insuficiente para completar el retiro.');
        }

        $source ??= $this->resolveSourceAccount($pdo);
        $this->assertSourceAccount($pdo, $source);
        $isClabe = strlen($data['account']) === 18;
        $description = 'Retiro Banca 14 · folio ' . $reference;
        $queued = (bool) ($provider['queued'] ?? false);

        $withdrawal = $pdo->prepare("INSERT INTO pre_ejecuta_plantilla (plantilla,cliente,nombre,tarjeta,clabe,institucion,monto,fecha_creacion,status,status_banxico,id_stp,folio,estado,status_pre,consulta_cep,cep,espera_liquidacion,email_alterno,usuario_inserta,procesado,telefono_notificacion,concepto,estatus_saldo) VALUES (0,:client,:name,:card,:clabe,:bank,:amount,NOW(),:status,:provider_status,:provider_id,:folio,:state,:message,0,:cep,:waiting,:email,:user,0,:phone,:concept,1)");
        $withdrawal->execute([
            'client' => $clientId,
            'name' => $data['name'],
            'card' => $isClabe ? null : $data['account'],
            'clabe' => $isClabe ? $data['account'] : null,
            'bank' => (int) $data['bank'],
            'amount' => $data['amount'],
            // La cola histórica sólo reconoce el estado inicial 0 y espera
            // que los campos del proveedor permanezcan vacíos hasta el envío.
            'status' => $queued ? '0' : $provider['status'],
            'provider_status' => $queued ? null : $provider['status'],
            'provider_id' => $provider['provider_id'],
            'folio' => $reference,
            'state' => $queued ? null : $provider['status'],
            'message' => $queued ? null : mb_substr($provider['message'], 0, 1000),
            'cep' => $provider['cep'],
            'waiting' => $queued ? 0 : 1,
            'email' => $data['email'],
            'user' => $userId,
            'phone' => $data['phone'],
            'concept' => $data['concept'],
        ]);
        $withdrawalId = (int) $pdo->lastInsertId();

        $capture = $pdo->prepare("INSERT INTO captura_edo_cta (id_captura,fecha_operacion,hora_operacion,fecha,referencia,descripcion,retiro,descrip_det,tipo_movimiento,fecha_sistema,asignado,cliente_asignado,cuenta) VALUES (0,CURDATE(),CURTIME(),CURDATE(),:reference,:description,:amount,:detail,'Retiro',CURDATE(),'si',:client,:account)");
        $capture->execute([
            'reference' => $reference,
            'description' => $description,
            'amount' => $data['amount'],
            'detail' => $description,
            'client' => (string) $clientId,
            'account' => $source['account'],
        ]);
        $captureId = (int) $pdo->lastInsertId();

        $newBalance = round($balance - $data['amount'], 2);
        $ledger = $pdo->prepare("INSERT INTO pagos (nombre,monto,factura,empresa,status,fecha_asignacion,fecha_captura,tipo_movimiento,saldo_acreditable,banco,saldo,usuario_registra,id_factura,fecha_banco,detalle,nombre_corto,tipo_captura,descripcion,descripcion_detallada,id_captura_edo_cta,notificado) VALUES (:client,:amount,'NA',:company,'asignado',CURDATE(),CURDATE(),'Retiro',:credit,'STP',:balance,:user,0,CURDATE(),:detail,'STP','Banca',:description,:description_detailed,:capture,'no')");
        $ledger->execute([
            'client' => $clientId,
            'amount' => $data['amount'],
            'company' => $source['company'],
            'credit' => -$data['amount'],
            'balance' => $newBalance,
            'user' => $userId,
            'detail' => $data['concept'],
            'description' => $description,
            'description_detailed' => $description,
            'capture' => $captureId,
        ]);
        $paymentId = (int) $pdo->lastInsertId();

        $link = $pdo->prepare('UPDATE pre_ejecuta_plantilla SET id_pago = :payment, id_captura_edo = :capture WHERE id = :id');
        $link->execute(['payment' => $paymentId, 'capture' => $captureId, 'id' => $withdrawalId]);
        if ($link->rowCount() !== 1) {
            throw new RuntimeException('No fue posible relacionar los registros del retiro.');
        }

        $notification = $pdo->prepare("INSERT INTO mailer_movimientos (cliente,fecha,hora,enviado,mailer_tipo,id_movimiento) VALUES (:client,CURDATE(),CURTIME(),'no','Banca',:movement)");
        $notification->execute(['client' => $clientId, 'movement' => $paymentId]);
        return $withdrawalId;
    }

    /** @return array{company:int,account:int} */
    public function resolveSourceAccount(PDO $pdo): array
    {
        $selection = (int) Env::get('WITHDRAWAL_SOURCE', '7');
        if (!isset(self::SOURCE_ACCOUNTS[$selection])) {
            throw new RuntimeException('WITHDRAWAL_SOURCE no corresponde a una salida bancaria permitida.');
        }
        $source = self::SOURCE_ACCOUNTS[$selection];

        $this->assertSourceAccount($pdo, $source);
        return $source;
    }

    /** @param array{company:int,account:int} $source */
    private function assertSourceAccount(PDO $pdo, array $source): void
    {
        $account = $pdo->prepare("SELECT id, razon FROM bancos WHERE id = :account AND status_banco = 'AC' LIMIT 1");
        $account->execute(['account' => $source['account']]);
        $row = $account->fetch();
        if ($row === false || (int) $row['razon'] !== $source['company']) {
            throw new RuntimeException('La cuenta bancaria de salida no está activa o no corresponde a la empresa configurada.');
        }
    }
}
