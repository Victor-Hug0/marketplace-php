<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Converts between the API's decimal BRL amounts ("1999.90") and the integer cents stored everywhere.
 * String arithmetic (bcmath) only: floats never touch money.
 */
final class Money
{
    public static function toCents(string|int|float $amount): int
    {
        // JSON numbers arrive as floats; requests already cap them at 2 decimal places.
        $normalized = is_float($amount) ? sprintf('%.2F', $amount) : trim((string) $amount);

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException("Valor monetário inválido: {$normalized}");
        }

        return (int) bcmul($normalized, '100', 0);
    }

    /**
     * Human-readable BRL for e-mails and messages: 179990 => "R$ 1.799,90".
     */
    public static function formatBrl(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%sR$ %s,%02d', $sign, number_format(intdiv($cents, 100), 0, '', '.'), $cents % 100);
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
