<?php
declare(strict_types=1);

namespace Banca\Core;

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
}

