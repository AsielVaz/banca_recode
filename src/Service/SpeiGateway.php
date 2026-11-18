<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Core\Env;
use RuntimeException;

final class SpeiGateway
{
    /** @param array<string, mixed> $payload
     *  @return array{provider_id:?int,status:string,message:string,cep:?string,queued:bool}
     */
    public function send(array $payload): array
    {
        $mode = Env::get('SPEI_MODE', 'disabled');
        if ($mode === 'local_queue') {
            // El portal heredado no enviaba directamente a STP: insertaba una
            // fila status=0 para que el procesador SPEI compartido la tomara.
            return ['provider_id' => null, 'status' => 'PENDIENTE', 'message' => 'Pendiente de envío por el procesador SPEI.', 'cep' => null, 'queued' => true];
        }
        if ($mode !== 'api') {
            throw new RuntimeException('Los retiros SPEI están deshabilitados. Configura SPEI_MODE y el proveedor antes de operar.');
        }
        $url = trim((string) Env::get('SPEI_API_URL', ''));
        if ($url === '') {
            throw new RuntimeException('Falta configurar SPEI_API_URL.');
        }
        $headers = "Content-Type: application/json\r\nAccept: application/json\r\nIdempotency-Key: {$payload['reference']}\r\n";
        $token = trim((string) Env::get('SPEI_API_TOKEN', ''));
        if ($token !== '') {
            $headers .= "Authorization: Bearer {$token}\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
            throw new RuntimeException('El proveedor SPEI no confirmó la operación.');
        }
        $data = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !in_array($data['status'] ?? '', ['accepted', 'pending', 'ok'], true)) {
            throw new RuntimeException((string) ($data['message'] ?? 'El proveedor SPEI rechazó la operación.'));
        }
        return [
            'provider_id' => isset($data['id']) ? (int) $data['id'] : null,
            'status' => strtoupper((string) $data['status']),
            'message' => (string) ($data['message'] ?? 'Operación aceptada.'),
            'cep' => isset($data['cep']) ? (string) $data['cep'] : null,
            'queued' => false,
        ];
    }
}

