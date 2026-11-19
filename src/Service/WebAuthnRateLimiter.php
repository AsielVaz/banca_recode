<?php
declare(strict_types=1);

namespace Banca\Service;

use PDO;

final class WebAuthnRateLimiter
{
    public function __construct(private readonly PDO $pdo, private readonly int $maximum)
    {
    }

    public function assertAvailable(string $action, string $ip, string $identity = ''): void
    {
        $type = $this->type($action);
        $byIp = $this->pdo->prepare('SELECT COUNT(*) FROM logsesiones WHERE tipo=:type AND ip=:ip AND TIMESTAMP(fecha,hora_i)>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)');
        $byIp->execute(['type' => $type, 'ip' => $ip]);
        if ((int) $byIp->fetchColumn() >= $this->maximum) {
            throw new \RuntimeException('Se realizaron demasiados intentos. Espera un minuto.');
        }
        if ($identity !== '') {
            $byIdentity = $this->pdo->prepare('SELECT COUNT(*) FROM logsesiones WHERE tipo=:type AND usuario=:identity AND TIMESTAMP(fecha,hora_i)>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)');
            $byIdentity->execute(['type' => $type, 'identity' => $this->identity($identity)]);
            if ((int) $byIdentity->fetchColumn() >= $this->maximum) {
                throw new \RuntimeException('Se realizaron demasiados intentos para esta cuenta. Espera un minuto.');
            }
        }
    }

    public function hit(string $action, string $ip, string $identity = ''): void
    {
        $statement = $this->pdo->prepare("INSERT INTO logsesiones (usuario,agent,ip,hora_i,fecha,tipo,activa) VALUES (:identity,:agent,:ip,CURTIME(),CURDATE(),:type,'no')");
        $statement->execute([
            'identity' => $identity !== '' ? $this->identity($identity) : '*',
            'agent' => json_encode(['event' => 'webauthn_rate', 'action' => $action], JSON_THROW_ON_ERROR),
            'ip' => mb_substr($ip, 0, 100),
            'type' => $this->type($action),
        ]);
    }

    private function identity(string $identity): string
    {
        return 'id:' . hash('sha256', mb_strtolower(trim($identity)));
    }

    private function type(string $action): string
    {
        return mb_substr('WA ' . $action, 0, 50);
    }
}
