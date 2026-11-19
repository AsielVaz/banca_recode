<?php
declare(strict_types=1);

namespace Banca\Repository;

use Banca\Core\Database;
use PDO;

final class BankingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function make(): self
    {
        return new self(Database::connection());
    }

    /** @return array<string, mixed>|false */
    public function userByEmail(string $email): array|false
    {
        $statement = $this->pdo->prepare('SELECT id, nombre, appat, apmat, email, password, usuario, tipo, cliente, `2fa` FROM usuarios WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        return $statement->fetch();
    }

    /** @return array<string, mixed>|false */
    public function userById(int $id): array|false
    {
        $statement = $this->pdo->prepare('SELECT id, nombre, appat, apmat, email, password, usuario, tipo, cliente, `2fa` FROM usuarios WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        return $statement->fetch();
    }

    /** @return array<string, mixed>|false */
    public function userByIdentifier(string $identifier): array|false
    {
        $statement = $this->pdo->prepare('SELECT id, nombre, appat, apmat, email, password, usuario, tipo, cliente, `2fa` FROM usuarios WHERE email = :email OR usuario = :username ORDER BY CASE WHEN email = :exact_email THEN 0 ELSE 1 END, id LIMIT 1');
        $statement->execute(['email' => $identifier, 'username' => $identifier, 'exact_email' => $identifier]);
        return $statement->fetch();
    }

    public function failedLogins(string $email): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM logsesiones WHERE usuario = :email AND tipo <> 'Correcto' AND TIMESTAMP(fecha, hora_i) >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $statement->execute(['email' => $email]);
        return (int) $statement->fetchColumn();
    }

    public function logLogin(string $email, bool $success): void
    {
        $statement = $this->pdo->prepare('INSERT INTO logsesiones (usuario, agent, ip, hora_i, fecha, tipo, activa) VALUES (:email, :agent, :ip, CURTIME(), CURDATE(), :type, :active)');
        $statement->execute([
            'email' => $email,
            'agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'desconocido'), 0, 1000),
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 100),
            'type' => $success ? 'Correcto' : 'Error',
            'active' => $success ? 'si' : 'no',
        ]);
    }

    public function updatePassword(int $userId, string $hash): void
    {
        $statement = $this->pdo->prepare('UPDATE usuarios SET password = :password WHERE id = :id');
        $statement->execute(['password' => $hash, 'id' => $userId]);
    }

    public function updateTotp(int $userId, ?string $secret): void
    {
        $statement = $this->pdo->prepare('UPDATE usuarios SET `2fa` = :secret WHERE id = :id');
        $statement->execute(['secret' => $secret, 'id' => $userId]);
    }

    public function balance(int $clientId): float
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(SUM(saldo_acreditable), 0) FROM pagos WHERE nombre = :client');
        $statement->execute(['client' => $clientId]);
        return round((float) $statement->fetchColumn(), 2);
    }

    /** @return list<array<string, mixed>> */
    public function movements(int $clientId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $statement = $this->pdo->prepare("SELECT id, monto, saldo_acreditable, saldo, fecha_asignacion, fecha_operacion, tipo_movimiento, status, detalle, descripcion, tipo_captura, origen_traspaso, destino_traspaso FROM pagos WHERE nombre = :client ORDER BY fecha_asignacion DESC, id DESC LIMIT {$limit}");
        $statement->execute(['client' => $clientId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function withdrawals(int $clientId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $statement = $this->pdo->prepare("SELECT p.id, p.nombre, p.tarjeta, p.clabe, p.institucion, b.nombre_corto AS banco, p.monto, p.fecha_creacion, p.fecha_ejecucion, p.status, p.status_banxico, p.folio, p.estado, p.causa_devolucion, p.cep, p.concepto FROM pre_ejecuta_plantilla p LEFT JOIN stp_cat_bancos b ON b.cve_id = LPAD(p.institucion, 3, '0') WHERE p.cliente = :client ORDER BY p.id DESC LIMIT {$limit}");
        $statement->execute(['client' => $clientId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function contacts(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT id, numero, nombre, correo, telefono, concepto_pre FROM cuentas_clientes_frecuentes WHERE cliente = :client ORDER BY nombre');
        $statement->execute(['client' => $userId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|false */
    public function contact(int $id, int $userId): array|false
    {
        $statement = $this->pdo->prepare('SELECT id, numero, nombre, correo, telefono, concepto_pre FROM cuentas_clientes_frecuentes WHERE id = :id AND cliente = :client');
        $statement->execute(['id' => $id, 'client' => $userId]);
        return $statement->fetch();
    }

    /** @param array{numero:string,nombre:string,correo:string,telefono:string,concepto:string} $data */
    public function saveContact(int $userId, array $data, ?int $id = null): void
    {
        if ($id !== null) {
            $statement = $this->pdo->prepare('UPDATE cuentas_clientes_frecuentes SET numero=:numero,nombre=:nombre,correo=:correo,telefono=:telefono,concepto_pre=:concepto WHERE id=:id AND cliente=:client');
            $statement->execute($data + ['id' => $id, 'client' => $userId]);
            return;
        }
        $statement = $this->pdo->prepare("INSERT INTO cuentas_clientes_frecuentes (cliente, numero, nombre, fecha, hora, ip, datos, alias, clabe_inter, correo, telefono, concepto_pre) VALUES (:client,:numero,:nombre,CURDATE(),CURTIME(),:ip,:agent,'Favorito Banca14','',:correo,:telefono,:concepto)");
        $statement->execute($data + [
            'client' => $userId,
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 100),
            'agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'desconocido'), 0, 200),
        ]);
    }

    public function deleteContact(int $id, int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM cuentas_clientes_frecuentes WHERE id = :id AND cliente = :client');
        $statement->execute(['id' => $id, 'client' => $userId]);
    }

    /** @return list<array<string, mixed>> */
    public function destinationClients(int $currentClient): array
    {
        $statement = $this->pdo->prepare('SELECT destination.id, destination.nombre FROM clientes AS origin INNER JOIN clientes AS destination ON destination.grupo = origin.grupo WHERE origin.id = :current AND origin.activo = 1 AND origin.lidera = 1 AND origin.grupo IS NOT NULL AND origin.grupo <> 0 AND destination.activo = 1 AND destination.id <> origin.id ORDER BY destination.nombre');
        $statement->execute(['current' => $currentClient]);
        return $statement->fetchAll();
    }

    public function canInitiateTransfers(int $clientId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM clientes WHERE id = :client AND activo = 1 AND lidera = 1 AND grupo IS NOT NULL AND grupo <> 0 LIMIT 1');
        $statement->execute(['client' => $clientId]);
        return $statement->fetchColumn() !== false;
    }

    /** @return array<string, mixed>|false */
    public function client(int $id): array|false
    {
        $statement = $this->pdo->prepare('SELECT id, nombre, correo, telefono, activo FROM clientes WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        return $statement->fetch();
    }

    /** @return list<array<string, mixed>> */
    public function banks(): array
    {
        return $this->pdo->query('SELECT cve_id, nombre_corto FROM stp_cat_bancos WHERE activo = 1 ORDER BY nombre_corto')->fetchAll();
    }

    /** @return array<string, mixed>|false */
    public function bankByCode(string $code): array|false
    {
        $statement = $this->pdo->prepare("SELECT LPAD(cve_id, 3, '0') AS cve_id, nombre_corto FROM stp_cat_bancos WHERE LPAD(cve_id, 3, '0') = :code AND activo = 1 LIMIT 1");
        $statement->execute(['code' => $code]);
        return $statement->fetch();
    }
}

