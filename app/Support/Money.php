<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money helpers. All amounts inside the app are integer cents.
 */
final class Money
{
    public static function toCents(string|int|float|null $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        if (is_int($amount)) {
            return $amount * 100;
        }

        $value = trim(str_replace([' ', ','], '', (string) $amount));
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Not a decimal amount: {$amount}");
        }

        $fraction = str_pad($m[3] ?? '', 3, '0');
        $cents = ((int) $m[2]) * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $m[1] === '-' ? -$cents : $cents;
    }

    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    public static function format(?int $cents, string $symbol = 'R'): string
    {
        if ($cents === null) {
            return '';
        }

        $whole = intdiv(abs($cents), 100);
        $fraction = abs($cents) % 100;
        $formatted = number_format($whole, 0, '.', ',');
        if ($fraction !== 0) {
            $formatted .= sprintf('.%02d', $fraction);
        }

        return ($cents < 0 ? '-' : '').$symbol.$formatted;
    }

    public static function symbolFor(string $currency): string
    {
        return match (strtoupper($currency)) {
            'ZAR' => 'R',
            'USD', 'CAD', 'AUD', 'NZD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => strtoupper($currency).' ',
        };
    }
}
