<?php
declare(strict_types=1);

use Banca\Core\Database;
use Banca\Repository\BankingRepository;
use Banca\Service\BankResolver;

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::connection();
$repository = new BankingRepository($pdo);
$catalogBank = $pdo->query("SELECT LPAD(cve_id,3,'0') code,nombre_corto FROM stp_cat_bancos WHERE activo=1 ORDER BY cve_id LIMIT 1")->fetch();
if ($catalogBank === false) {
    throw new RuntimeException('No existe un banco activo para comprobar la resolución de CLABE.');
}

$clabe = (string) $catalogBank['code'] . str_repeat('0', 15);
$resolved = (new BankResolver($repository))->resolve($clabe);
if ($resolved['code'] !== (string) $catalogBank['code']
    || $resolved['name'] !== trim((string) $catalogBank['nombre_corto'])
    || $resolved['source'] !== 'catálogo CLABE') {
    throw new RuntimeException('La CLABE no se resolvió con sus primeros tres dígitos en stp_cat_bancos.');
}

$missing = $pdo->query("SELECT code FROM (SELECT '000' code UNION SELECT '999') candidates WHERE NOT EXISTS (SELECT 1 FROM stp_cat_bancos b WHERE b.activo=1 AND LPAD(b.cve_id,3,'0')=candidates.code) LIMIT 1")->fetchColumn();
if (is_string($missing)) {
    try {
        (new BankResolver($repository))->resolve($missing . str_repeat('0', 15));
        throw new RuntimeException('Una CLABE sin banco activo fue aceptada.');
    } catch (RuntimeException $exception) {
        if (str_contains($exception->getMessage(), 'fue aceptada')) {
            throw $exception;
        }
    }
}

echo "Resolución bancaria correcta: CLABE consultada por prefijo en stp_cat_bancos.\n";
