<?php
declare(strict_types=1);

namespace Banca\Core;

use InvalidArgumentException;

final class Validation
{
    public static function text(mixed $value, string $field, int $max = 255, bool $required = true): string
    {
        $value = trim(is_string($value) ? $value : '');
        if ($required && $value === '') {
            throw new InvalidArgumentException("{$field} es obligatorio.");
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$field} excede {$max} caracteres.");
        }
        return $value;
    }

    public static function email(mixed $value, bool $required = false): string
    {
        $email = self::text($value, 'Correo', 500, $required);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El correo no tiene un formato válido.');
        }
        return $email;
    }

    public static function account(mixed $value): string
    {
        $account = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (!in_array(strlen($account), [16, 18], true)) {
            throw new InvalidArgumentException('La cuenta debe ser una tarjeta de 16 dígitos o una CLABE de 18 dígitos.');
        }
        return $account;
    }

    public static function money(mixed $value): float
    {
        $normalized = str_replace([',', '$', ' '], '', (string) $value);
        if (!is_numeric($normalized)) {
            throw new InvalidArgumentException('El monto no es válido.');
        }
        $amount = round((float) $normalized, 2);
        if ($amount <= 0 || $amount > 999999999.99) {
            throw new InvalidArgumentException('El monto debe ser mayor a cero y estar dentro del límite permitido.');
        }
        return $amount;
    }
}
