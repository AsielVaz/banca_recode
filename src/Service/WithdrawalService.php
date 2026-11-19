<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Core\Database;
use Banca\Repository\BankingRepository;
use PDO;
use RuntimeException;

final class WithdrawalService
{
    public function __construct(
        private readonly BankingRepository $repository,
        private readonly TotpService $totp = new TotpService(),
        private readonly SpeiGateway $gateway = new SpeiGateway(),
        private readonly MovementRecorder $recorder = new MovementRecorder(),
    ) {
    }

    /** @param array{name:string,account:string,amount:float,concept:string,email:string,phone:string,bank:string,totp:string,reference:string} $data */
    public function execute(int $userId, int $clientId, array $data): int
    {
        $data['concept'] = WithdrawalConcept::normalize($data['concept'] ?? '');
        $user = $this->repository->userById($userId);
        $secret = $user !== false ? trim((string) ($user['2fa'] ?? '')) : '';
        if ($secret === '') {
            throw new RuntimeException('Debes configurar la autenticación 2FA antes de retirar.');
        }
        if (!$this->totp->verify($secret, $data['totp'], 1)) {
            throw new RuntimeException('El código 2FA no es válido.');
        }
        $reference = (string) ($data['reference'] ?? '');
        if (!OperationToken::isValidReference($reference)) {
            throw new RuntimeException('La referencia de la operación no es válida.');
        }

        $pdo = Database::connection();
        // GET_LOCK vive en MySQL, por lo que coordina todas las instancias de
        // la aplicación que apuntan a esta base. Sólo serializa a un cliente;
        // clientes diferentes continúan operando en paralelo.
        $lockName = 'b14:client:' . substr(hash('sha256', (string) $clientId), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(:name, 10)');
        $lock->execute(['name' => $lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('La operación ya se está procesando. Espera unos segundos antes de reintentar.');
        }

        try {
            $existing = $pdo->prepare('SELECT id FROM pre_ejecuta_plantilla WHERE cliente = :client AND folio = :reference LIMIT 1');
            $existing->execute(['client' => $clientId, 'reference' => $reference]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) {
                return (int) $existingId;
            }

            // Segunda comprobación bajo el bloqueo distribuido del cliente.
            // No se abre aún una transacción, así que la llamada de red no
            // mantiene filas ni tablas bloqueadas.
            if ($data['amount'] > $this->repository->balance($clientId)) {
                throw new RuntimeException('Saldo insuficiente para completar el retiro.');
            }

            // Se valida antes de contactar al proveedor para no aceptar una
            // transferencia que después no pueda registrarse localmente.
            $source = $this->recorder->resolveSourceAccount($pdo);
            $provider = $this->gateway->send([
                'reference' => $reference,
                'client_id' => $clientId,
                'beneficiary' => $data['name'],
                'account' => $data['account'],
                'amount' => $data['amount'],
                'concept' => $data['concept'],
                'bank' => $data['bank'],
                'source_company_id' => $source['company'],
                'source_account_id' => $source['account'],
            ]);

            return Database::transaction(fn (PDO $transaction): int => $this->recorder->record(
                $transaction,
                $userId,
                $clientId,
                $data,
                $reference,
                $provider,
                $source,
            ));
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
        }
    }
}
