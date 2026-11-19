<?php
declare(strict_types=1);

namespace Banca\Service;

final class WithdrawalConcept
{
    public static function normalize(mixed $value): string
    {
        $concept = trim(is_string($value) ? $value : '');

        // El procesador histórico sólo conserva referencias totalmente
        // numéricas. Cualquier texto se envía con el concepto fijo solicitado.
        return $concept !== '' && preg_match('/^\d+$/D', $concept) === 1 && strlen($concept) <= 50
            ? $concept
            : 'Deposito';
    }
}
