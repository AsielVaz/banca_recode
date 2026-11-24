<?php
declare(strict_types=1);

use Banca\Core\View;

require dirname(__DIR__) . '/src/bootstrap.php';

$cases = [
    '2026-11-19' => 'Jueves 19 de Noviembre del 2026',
    '2025-01-01 13:45:20' => 'Miércoles 1 de Enero del 2025',
    '2024-02-29' => 'Jueves 29 de Febrero del 2024',
    '' => 'Sin fecha',
    '0000-00-00' => 'Sin fecha',
];

foreach ($cases as $input => $expected) {
    if (View::date($input) !== $expected) {
        throw new RuntimeException("Formato de fecha incorrecto para {$input}.");
    }
}

$withdrawView = file_get_contents(dirname(__DIR__) . '/views/withdraw.php') ?: '';
if (preg_match('/<input(?=[^>]*name="concept")(?![^>]*\srequired(?:\s|>|=))[^>]*>/i', $withdrawView) !== 1) {
    throw new RuntimeException('El concepto del retiro continúa siendo obligatorio en el navegador.');
}

echo "Presentación correcta: fechas en español y concepto SPEI opcional.\n";
