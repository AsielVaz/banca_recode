<?php
declare(strict_types=1);

use Banca\Service\OperationToken;

require dirname(__DIR__) . '/src/bootstrap.php';

$token = OperationToken::issue('test-withdrawal');
$first = OperationToken::reference('test-withdrawal', $token);
$same = OperationToken::reference('test-withdrawal', $token);
$secondRow = OperationToken::reference('test-withdrawal', $token, 2);

if ($first !== $same || $first === $secondRow || !preg_match('/^10HUM[A-F0-9]{15}$/', $first) || !OperationToken::isValidReference($first)) {
    throw new RuntimeException('Las referencias idempotentes no son deterministas o no tienen el formato esperado.');
}

OperationToken::complete('test-withdrawal', $token);
try {
    OperationToken::reference('test-withdrawal', $token);
    throw new RuntimeException('Un token completado volvió a autorizar una operación.');
} catch (RuntimeException $exception) {
    if (str_contains($exception->getMessage(), 'volvió a autorizar')) {
        throw $exception;
    }
}

echo "Idempotencia correcta: referencias estables, secuencias separadas y token de un solo uso.\n";
