<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is handled as integer cents in PHP so totals never suffer from float rounding.
 * Values come in and go out as decimal strings ("19.99"), matching the DECIMAL(12,2) columns.
 */
final class Money
{
    public static function toCents(string|int|float $amount): int
    {
        $value = is_string($amount) ? trim($amount) : number_format((float) $amount, 2, '.', '');

        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            throw new InvalidArgumentException("Invalid money amount [{$value}].");
        }

        $fraction = substr(str_pad($matches[3] ?? '', 2, '0'), 0, 2);
        $cents = ((int) $matches[2]) * 100 + (int) $fraction;

        return $matches[1] === '-' ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
