<?php

declare(strict_types=1);

namespace App\Application\Printing;

/**
 * Exact, locale-fixed formatting for printed documents (id-ID). Pure string
 * arithmetic — no floats — so what is printed is exactly the stored decimal.
 * Every value is returned twice: the exact decimal string (machine) and the
 * formatted text (printer). Flutter prints `formatted`; it never recomputes.
 */
final class PrintFormat
{
    /** @return array{amount: string, formatted: string} */
    public static function money(string $amount, string $currency = 'IDR'): array
    {
        return ['amount' => $amount, 'formatted' => ($currency === 'IDR' ? 'Rp' : $currency.' ').self::number($amount, 2)];
    }

    /** @return array{value: string, formatted: string, uom: string} */
    public static function quantity(string $value, string $uom): array
    {
        return ['value' => $value, 'formatted' => self::number($value, 4), 'uom' => $uom];
    }

    /**
     * "1275000.0000" → "1.275.000", "12.5000" → "12,5", "-500.0000" → "-500".
     * Fraction digits are kept up to $maxFraction and trailing zeros dropped
     * (money: 4 dp stored → at most 2 printed only when NOT zero; a non-zero
     * 3rd/4th digit is kept rather than silently rounded).
     */
    public static function number(string $decimal, int $maxFraction): string
    {
        $neg = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '-+');
        [$int, $frac] = array_pad(explode('.', $decimal, 2), 2, '');
        $int = ltrim($int, '0') ?: '0';
        $frac = rtrim($frac, '0');
        if (strlen($frac) > $maxFraction && $maxFraction < 4) {
            $maxFraction = strlen($frac); // never hide precision
        }
        $grouped = strrev(implode('.', str_split(strrev($int), 3)));
        $out = $grouped.($frac !== '' ? ','.$frac : '');

        return ($neg && $out !== '0' ? '-' : '').$out;
    }

    /** "0", "0.0000", "-0.00" → true (string check, no float). */
    public static function isZero(string $decimal): bool
    {
        return preg_match('/^[-+]?0*(\.0*)?$/', $decimal) === 1;
    }
}
