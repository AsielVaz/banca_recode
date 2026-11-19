<?php
declare(strict_types=1);

namespace Banca\Repository;

use PDO;
use RuntimeException;
use Throwable;

final class PasskeyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByCredentialId(string $credentialId): ?array
    {
        if ($credentialId === '') {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT p.* FROM usuarios_passkeys p INNER JOIN usuarios u ON u.id = p.id_usuario WHERE p.credential_hash = :hash LIMIT 1');
        $statement->bindValue('hash', hash('sha256', $credentialId, true), PDO::PARAM_LOB);
        $statement->execute();
        $row = $statement->fetch();
        if (!is_array($row) || !hash_equals((string) $row['credential_id'], $credentialId)) {
            return null;
        }
        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function credentialsForUser(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT id,id_usuario,credential_id,user_handle,sign_count,transports,backup_eligible,backup_state,nombre,fecha_creacion,ultimo_uso FROM usuarios_passkeys WHERE id_usuario = :user ORDER BY fecha_creacion DESC,id DESC');
        $statement->execute(['user' => $userId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function publicCredentialsForUser(int $userId): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['nombre'],
            'created_at' => (string) $row['fecha_creacion'],
            'last_used_at' => $row['ultimo_uso'] !== null ? (string) $row['ultimo_uso'] : null,
            'backup_eligible' => (bool) $row['backup_eligible'],
            'backup_state' => (bool) $row['backup_state'],
            'transports' => self::decodeTransports($row['transports'] ?? null),
        ], $this->credentialsForUser($userId));
    }

    public function stableUserHandle(int $userId, string $secret): string
    {
        $statement = $this->pdo->prepare('SELECT user_handle FROM usuarios_passkeys WHERE id_usuario = :user ORDER BY id LIMIT 1');
        $statement->execute(['user' => $userId]);
        $handle = $statement->fetchColumn();
        if (is_string($handle) && $handle !== '') {
            return $handle;
        }
        if (strlen($secret) < 32) {
            throw new RuntimeException('No está configurado el identificador seguro de Passkeys.');
        }
        return hash_hmac('sha256', (string) $userId, $secret, true);
    }

    /** @param array<string, mixed> $data */
    public function insert(int $userId, array $data, int $maximum): int
    {
        $credentialId = (string) ($data['credential_id'] ?? '');
        $publicKey = trim((string) ($data['public_key'] ?? ''));
        $userHandle = (string) ($data['user_handle'] ?? '');
        if ($userId <= 0 || $credentialId === '' || $userHandle === '' || openssl_pkey_get_public($publicKey) === false) {
            throw new RuntimeException('Los datos de la credencial no son válidos.');
        }
        if (strlen($credentialId) > 1023 || strlen($userHandle) > 64) {
            throw new RuntimeException('La credencial excede el tamaño permitido.');
        }

        $this->pdo->beginTransaction();
        try {
            $userLock = $this->pdo->prepare('SELECT id FROM usuarios WHERE id = :user FOR UPDATE');
            $userLock->execute(['user' => $userId]);
            if ($userLock->fetchColumn() === false) {
                throw new RuntimeException('La cuenta autenticada ya no existe.');
            }
            if ($this->countForUser($userId) >= $maximum) {
                throw new RuntimeException('La cuenta alcanzó el máximo de Passkeys permitidas.');
            }
            if ($this->findByCredentialId($credentialId) !== null) {
                throw new RuntimeException('Esta Passkey ya está registrada.');
            }
            $statement = $this->pdo->prepare('INSERT INTO usuarios_passkeys (id_usuario,credential_id,credential_hash,public_key,user_handle,sign_count,transports,backup_eligible,backup_state,nombre) VALUES (:user,:credential,:hash,:public_key,:handle,:counter,:transports,:eligible,:backed_up,:name)');
            $statement->bindValue('user', $userId, PDO::PARAM_INT);
            $statement->bindValue('credential', $credentialId, PDO::PARAM_LOB);
            $statement->bindValue('hash', hash('sha256', $credentialId, true), PDO::PARAM_LOB);
            $statement->bindValue('public_key', $publicKey);
            $statement->bindValue('handle', $userHandle, PDO::PARAM_LOB);
            $statement->bindValue('counter', max(0, (int) ($data['sign_count'] ?? 0)), PDO::PARAM_INT);
            $statement->bindValue('transports', json_encode(array_values(array_unique($data['transports'] ?? [])), JSON_THROW_ON_ERROR));
            $statement->bindValue('eligible', !empty($data['backup_eligible']) ? 1 : 0, PDO::PARAM_INT);
            $statement->bindValue('backed_up', !empty($data['backup_state']) ? 1 : 0, PDO::PARAM_INT);
            $statement->bindValue('name', mb_substr(trim((string) ($data['name'] ?? 'Mi dispositivo')), 0, 100));
            $statement->execute();
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateUsage(int $id, int $newCounter, bool $backupState, ?int $expectedCounter): void
    {
        $sql = 'UPDATE usuarios_passkeys SET sign_count=:counter,backup_state=:backup,ultimo_uso=NOW() WHERE id=:id';
        if ($expectedCounter !== null) {
            $sql .= ' AND sign_count=:expected';
        }
        $statement = $this->pdo->prepare($sql);
        $params = ['counter' => max(0, $newCounter), 'backup' => $backupState ? 1 : 0, 'id' => $id];
        if ($expectedCounter !== null) {
            $params['expected'] = max(0, $expectedCounter);
        }
        $statement->execute($params);
        if ($statement->rowCount() === 1) {
            return;
        }
        $check = $this->pdo->prepare('SELECT sign_count FROM usuarios_passkeys WHERE id=:id');
        $check->execute(['id' => $id]);
        $current = $check->fetchColumn();
        $zeroCounter = $current !== false && $expectedCounter === 0 && $newCounter === 0 && (int) $current === 0;
        $synced = $current !== false && $expectedCounter === null;
        if (!$zeroCounter && !$synced) {
            throw new RuntimeException('La Passkey fue utilizada simultáneamente. Solicita una nueva comprobación.');
        }
    }

    public function rename(int $id, int $userId, string $name): bool
    {
        $name = mb_substr(trim($name), 0, 100);
        if ($name === '') {
            throw new RuntimeException('Escribe un nombre para la Passkey.');
        }
        $statement = $this->pdo->prepare('UPDATE usuarios_passkeys SET nombre=:name WHERE id=:id AND id_usuario=:user');
        $statement->execute(['name' => $name, 'id' => $id, 'user' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function delete(int $id, int $userId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM usuarios_passkeys WHERE id=:id AND id_usuario=:user');
        $statement->execute(['id' => $id, 'user' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function countForUser(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM usuarios_passkeys WHERE id_usuario=:user');
        $statement->execute(['user' => $userId]);
        return (int) $statement->fetchColumn();
    }

    /** @return list<string> */
    public static function decodeTransports(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, 'is_string'));
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
