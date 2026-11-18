<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Core\Env;
use Banca\Repository\BankingRepository;
use RuntimeException;

final class BankResolver
{
    private const CARD_LOOKUP_URL = 'https://old.sistema14.com/plus/sistema/api/getBanco.php';

    /** @var array<string, array{code:string,name:string,source:string}> */
    private array $cardCache = [];

    public function __construct(private readonly BankingRepository $repository)
    {
    }

    /** @return array{code:string,name:string,source:string} */
    public function resolve(string $account): array
    {
        if (strlen($account) === 18) {
            return $this->fromCatalog(substr($account, 0, 3), 'catálogo CLABE');
        }
        if (strlen($account) !== 16) {
            throw new RuntimeException('La cuenta debe tener 16 o 18 dígitos.');
        }

        $key = trim((string) Env::get('BANK_LOOKUP_KEY', ''));
        if ($key === '') {
            throw new RuntimeException('La consulta de bancos para tarjetas no está configurada.');
        }

        $bin = substr($account, 0, 6);
        if (isset($this->cardCache[$bin])) {
            return $this->cardCache[$bin];
        }
        $curl = curl_init(self::CARD_LOOKUP_URL . '?' . http_build_query([
            'numero' => $bin,
            'llave' => $key,
        ]));
        if ($curl === false) {
            throw new RuntimeException('No fue posible iniciar la consulta del banco.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Version: 3'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if (!is_string($response) || $response === '' || $status !== 200) {
            throw new RuntimeException('No fue posible determinar el banco de la tarjeta.' . ($error !== '' ? ' ' . $error : ''));
        }
        try {
            $payload = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('El servicio de bancos devolvió una respuesta inválida.');
        }
        $rawCode = preg_replace('/\D+/', '', (string) ($payload['bank']['code'] ?? '')) ?? '';
        if ($rawCode === '' || strlen($rawCode) > 3) {
            throw new RuntimeException('Banco no reconocido para la tarjeta indicada.');
        }
        $code = str_pad($rawCode, 3, '0', STR_PAD_LEFT);
        $bank = $this->fromCatalog($code, 'API Sistema 14');
        $apiName = trim((string) ($payload['bank']['name'] ?? ''));
        if ($apiName !== '') {
            $bank['name'] = $apiName;
        }
        return $this->cardCache[$bin] = $bank;
    }

    /** @return array{code:string,name:string,source:string} */
    private function fromCatalog(string $code, string $source): array
    {
        $bank = $this->repository->bankByCode($code);
        if ($bank === false) {
            throw new RuntimeException("El banco {$code} no está disponible en el catálogo.");
        }
        return [
            'code' => str_pad((string) $bank['cve_id'], 3, '0', STR_PAD_LEFT),
            'name' => trim((string) $bank['nombre_corto']),
            'source' => $source,
        ];
    }
}
