<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Core\Env;
use RuntimeException;

final class LegacyCepPdf
{
    private const CIPHER = 'aes-256-cbc';

    public function url(int $movementId): string
    {
        if ($movementId <= 0) {
            throw new RuntimeException('El movimiento no tiene un identificador válido para generar el PDF.');
        }
        $baseUrl = trim((string) Env::get('LEGACY_CEP_PDF_URL', 'https://old.sistema14.com/gen_cep_pdf-directo.php'));
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('La dirección del generador PDF no es válida.');
        }

        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'pago=' . rawurlencode($this->encrypt((string) $movementId));
    }

    public function encrypt(string $value): string
    {
        $key = (string) Env::get('LEGACY_CEP_PDF_KEY', '');
        if ($key === '') {
            throw new RuntimeException('Falta configurar la clave del generador PDF de comprobantes.');
        }
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false || $ivLength <= 0) {
            throw new RuntimeException('El cifrado requerido para el comprobante PDF no está disponible.');
        }
        $iv = random_bytes($ivLength);
        // Compatibilidad exacta con encriptar() del sistema original: options=0
        // devuelve Base64, que después se concatena al IV y vuelve a codificarse.
        $encrypted = openssl_encrypt($value, self::CIPHER, $key, 0, $iv);
        if (!is_string($encrypted)) {
            throw new RuntimeException('No fue posible preparar el enlace del comprobante PDF.');
        }
        return base64_encode($iv . $encrypted);
    }
}
