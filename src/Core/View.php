<?php
declare(strict_types=1);

namespace Banca\Core;

use DateTimeImmutable;
use Exception;
use RuntimeException;

final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data = [], bool $layout = true): void
    {
        $file = dirname(__DIR__, 2) . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Vista no encontrada: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        $content = (string) ob_get_clean();
        if (!$layout) {
            echo $content;
            return;
        }
        require dirname(__DIR__, 2) . '/views/layout.php';
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function date(mixed $value): string
    {
        $raw = trim(is_string($value) ? $value : '');
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return 'Sin fecha';
        }

        try {
            $date = new DateTimeImmutable($raw);
        } catch (Exception) {
            return 'Sin fecha';
        }

        $weekdays = [
            1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
            5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
        ];
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return sprintf(
            '%s %d de %s del %d',
            $weekdays[(int) $date->format('N')],
            (int) $date->format('j'),
            $months[(int) $date->format('n')],
            (int) $date->format('Y'),
        );
    }
}

