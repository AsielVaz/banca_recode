<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Core\Validation;
use RuntimeException;
use Shuchkin\SimpleXLS;
use Throwable;

final class BatchTemplateService
{
    private const MAX_ROWS = 50;
    private const MAX_FILE_SIZE = 2_000_000;
    private const MAX_SINGLE_AMOUNT = 50_000.00;
    private const MAX_ACCOUNT_TOTAL = 80_000.00;

    public function __construct(private readonly BankResolver $banks)
    {
    }

    /** @param array<string, mixed> $file
     *  @return array{rows:list<array<string,mixed>>,total:float,count:int,valid:bool}
     */
    public function parseUpload(array $file, float $balance): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('Selecciona la plantilla XLS original.');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_FILE_SIZE) {
            throw new RuntimeException('La plantilla no puede exceder 2 MB.');
        }
        if (mb_strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'xls') {
            throw new RuntimeException('Usa el archivo plantilla.xls en formato XLS.');
        }
        return $this->parseFile((string) $file['tmp_name'], $balance);
    }

    /** @return array{rows:list<array<string,mixed>>,total:float,count:int,valid:bool} */
    public function parseFile(string $path, float $balance): array
    {
        $xls = SimpleXLS::parse($path);
        if ($xls === false) {
            throw new RuntimeException('No se pudo leer la plantilla XLS: ' . SimpleXLS::parseError());
        }
        $sheet = $xls->rows();
        $header = array_map([$this, 'header'], array_slice($sheet[0] ?? [], 0, 4));
        if ($header !== ['NOMBRE', 'TARJETA/CLABE', 'MONTO', 'TEL. (WHATSAPP)']) {
            throw new RuntimeException('Las columnas no corresponden a la plantilla original. Descárgala nuevamente.');
        }

        $rows = [];
        $accountTotals = [];
        $total = 0.0;
        foreach (array_slice($sheet, 1) as $index => $values) {
            $values = array_pad(array_slice($values, 0, 4), 4, '');
            if (trim(implode('', array_map(static fn (mixed $value): string => (string) $value, $values))) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new RuntimeException('La plantilla no puede exceder 50 retiros.');
            }
            $rowNumber = $index + 2;
            $row = [
                'row' => $rowNumber,
                'name' => trim((string) $values[0]),
                'account' => preg_replace('/\D+/', '', (string) $values[1]) ?? '',
                'amount' => 0.0,
                'phone' => preg_replace('/[^0-9+]/', '', (string) $values[3]) ?? '',
                'concept' => 'Depósito',
                'email' => '',
                'bank' => '',
                'bank_name' => '',
                'bank_source' => '',
                'error' => '',
                'valid' => false,
            ];
            try {
                $row['name'] = Validation::text($row['name'], "Beneficiario de la fila {$rowNumber}", 40);
                $row['account'] = Validation::account($row['account']);
                $row['amount'] = Validation::money($values[2]);
                if ($row['amount'] > self::MAX_SINGLE_AMOUNT) {
                    throw new RuntimeException('El monto excede el límite de $50,000.00.');
                }
                $row['phone'] = Validation::text($row['phone'], 'Teléfono', 20, false);
                $bank = $this->banks->resolve($row['account']);
                $row['bank'] = $bank['code'];
                $row['bank_name'] = $bank['name'];
                $row['bank_source'] = $bank['source'];
                $accountTotals[$row['account']] = ($accountTotals[$row['account']] ?? 0.0) + $row['amount'];
                if ($accountTotals[$row['account']] > self::MAX_ACCOUNT_TOTAL) {
                    throw new RuntimeException('Los retiros para esta cuenta exceden $80,000.00.');
                }
                $row['valid'] = true;
                $total += $row['amount'];
            } catch (Throwable $exception) {
                $row['error'] = $exception->getMessage();
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            throw new RuntimeException('La plantilla no contiene retiros.');
        }
        if ($total > $balance) {
            foreach ($rows as &$row) {
                if ($row['valid']) {
                    $row['valid'] = false;
                    $row['error'] = 'El saldo disponible no cubre la plantilla.';
                }
            }
            unset($row);
        }
        $valid = true;
        foreach ($rows as $row) {
            if (!$row['valid']) {
                $valid = false;
                break;
            }
        }
        return [
            'rows' => $rows,
            'total' => round($total, 2),
            'count' => count($rows),
            'valid' => $valid,
        ];
    }

    /** @return array<string,mixed> */
    public static function publicRow(array $row): array
    {
        $account = (string) $row['account'];
        return [
            'row' => $row['row'],
            'name' => $row['name'],
            'account' => '•••• ' . substr($account, -4),
            'amount' => $row['amount'],
            'phone' => $row['phone'],
            'bank' => $row['bank_name'],
            'bank_source' => $row['bank_source'],
            'valid' => $row['valid'],
            'error' => $row['error'],
        ];
    }

    private function header(mixed $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }
}
