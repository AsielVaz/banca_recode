<?php
declare(strict_types=1);

use Banca\Core\Database;
use Banca\Repository\BankingRepository;
use Banca\Service\TotpService;
use Shuchkin\SimpleXLS;

require dirname(__DIR__) . '/src/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = Database::connection();
$assert($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'La conexión no usa MySQL.');
$required = ['usuarios', 'clientes', 'pagos', 'pre_ejecuta_plantilla', 'cuentas_clientes_frecuentes'];
$placeholders = implode(',', array_fill(0, count($required), '?'));
$statement = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})");
$statement->execute($required);
$assert(count($statement->fetchAll()) === count($required), 'Faltan tablas requeridas.');

$repository = BankingRepository::make();
$assert(is_array($repository->banks()), 'No fue posible consultar bancos.');
$assert((new TotpService())->generateSecret() !== '', 'No fue posible generar un secreto TOTP.');
$template = SimpleXLS::parse(dirname(__DIR__) . '/public/assets/plantilla.xls');
$assert($template !== false, 'No fue posible leer la plantilla XLS original.');
$headers = array_map(static fn (mixed $value): string => mb_strtoupper(trim((string) $value)), array_slice($template->rows()[0] ?? [], 0, 4));
$assert($headers === ['NOMBRE', 'TARJETA/CLABE', 'MONTO', 'TEL. (WHATSAPP)'], 'La plantilla XLS no tiene las columnas esperadas.');

echo "Smoke test correcto: PHP " . PHP_VERSION . ", MySQL conectado y esquema disponible.\n";
