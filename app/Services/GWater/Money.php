<?php

namespace App\Services\GWater;

/**
 * G-Water keeps money as integer pesewas. This is the only place cedis typed by a person become pesewas, and the only place
 * pesewas are shown, so a rounding rule cannot differ between screens.
 */
class Money
{
    /**
     * "25", "25.5", "1,234.50", " 12.00 " -> pesewas. Null for blank, negative, not a number or more than two decimals
     * (a price with a fraction of a pesewa is a typing mistake, not something to round).
     */
    public static function toPesewas(mixed $cedis): ?int
    {
        if ($cedis === null || is_bool($cedis)) {
            return null;
        }

        $text = str_replace([',', ' '], '', trim((string) $cedis));

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $text)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');

        return ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** 1234567 -> "12,345.67" (no currency sign: screens say GH¢ in the heading or label). */
    public static function format(int|float|null $pesewas): string
    {
        return number_format(((int) round((float) $pesewas)) / 100, 2);
    }

    /** 2500 -> "25.00" for an input box (no thousands separator). */
    public static function input(?int $pesewas): string
    {
        return $pesewas === null ? '' : number_format($pesewas / 100, 2, '.', '');
    }
}
