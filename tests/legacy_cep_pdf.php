<?php
declare(strict_types=1);

use Banca\Core\Env;
use Banca\Service\LegacyCepPdf;

require dirname(__DIR__) . '/src/bootstrap.php';

$service = new LegacyCepPdf();
$movementId = '123456';
$token = $service->encrypt($movementId);
$binary = base64_decode($token, true);
if (!is_string($binary)) {
    throw new RuntimeException('El token PDF no usa Base64 válido.');
}
$ivLength = openssl_cipher_iv_length('aes-256-cbc');
$iv = substr($binary, 0, $ivLength);
$encrypted = substr($binary, $ivLength);
$decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', (string) Env::get('LEGACY_CEP_PDF_KEY', ''), 0, $iv);
if (!is_string($decrypted) || !hash_equals($movementId, $decrypted)) {
    throw new RuntimeException('El token PDF no es compatible con desencriptar() del sistema original.');
}
$url = $service->url((int) $movementId);
$query = parse_url($url, PHP_URL_QUERY);
parse_str(is_string($query) ? $query : '', $parameters);
if (!isset($parameters['pago']) || base64_decode((string) $parameters['pago'], true) === false) {
    throw new RuntimeException('El enlace PDF no contiene un parámetro pago válido.');
}
if (hash_equals($token, $service->encrypt($movementId))) {
    throw new RuntimeException('El cifrado PDF reutilizó el IV y produjo tokens repetidos.');
}

echo "PDF CEP correcto: enlace cifrado compatible con el sistema original.\n";
