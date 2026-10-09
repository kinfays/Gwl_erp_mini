<?php

namespace App\Services\Commercial\Customers;

use DateTimeInterface;

/**
 * Defensive cell clean-up for the customer list: Excel serials or text dates, blanks and "0", thousands separators, stray
 * whitespace, several phone numbers in one cell. Pure functions; nothing here logs or keeps a value.
 */
final class CustomerValues
{
    /** Earliest date taken as real; Excel's 0 / 1900-01-00 / 1899-12-30 placeholders and anything older read as "no date". */
    public const MIN_YEAR = 1950;

    public const MAX_YEAR = 2100;

    /** A 12-digit account number as a string (leading zeros kept); a shorter all-digit number from a numeric cell is left-padded. */
    public static function accountNo(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $text = (string) (int) $value;

            return strlen($text) <= 12 && (int) $value > 0 ? str_pad($text, 12, '0', STR_PAD_LEFT) : null;
        }

        $text = preg_replace('/\s+/u', '', (string) $value) ?? '';

        return preg_match('/^\d{12}$/', $text) ? $text : null;
    }

    public static function text(mixed $value, int $max = 255): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_float($value) && floor($value) === $value) {
            $value = (string) (int) $value;
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** The category code as printed (digits), or null when blank (maps to UNKNOWN). */
    public static function categoryCode(mixed $value): ?string
    {
        $text = self::text($value, 12);

        if ($text === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $text) ?? '';

        return $digits !== '' ? $digits : mb_strtoupper($text);
    }

    public static function code(mixed $value): ?string
    {
        $text = self::text($value, 12);

        return $text === null ? null : mb_strtoupper($text);
    }

    /**
     * Money in whole pesewas. Accepts numbers, "1,234.50", "(12.00)" and a lone dash; null for blank or not a number.
     */
    public static function pesewas(mixed $value): ?int
    {
        $number = self::number($value);

        return $number === null ? null : (int) round($number * 100);
    }

    public static function number(mixed $value): ?float
    {
        if ($value === null || is_bool($value) || $value instanceof DateTimeInterface) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (in_array($text, ['-', '--', "\u{2013}"], true)) {
            return 0.0;
        }

        $negative = false;

        if (preg_match('/^\((.*)\)$/', $text, $matches)) {
            $negative = true;
            $text = $matches[1];
        }

        $cleaned = str_replace([',', ' ', "\u{00A0}", 'GH¢', 'GHS', '¢'], '', $text);

        if (! is_numeric($cleaned)) {
            return null;
        }

        return $negative ? -(float) $cleaned : (float) $cleaned;
    }

    /** A plain decimal with 3 places for a column, or null. */
    public static function decimal(mixed $value): ?string
    {
        $number = self::number($value);

        return $number === null ? null : number_format($number, 3, '.', '');
    }

    /**
     * A date as Y-m-d, or null. The second value says WHY it is null when something was there but unusable:
     * 'implausible' (before 1950 / after 2100, or Excel's zero-date), 'unreadable' (text that is no date).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function date(mixed $value): array
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return [null, null];
        }

        if ($value instanceof DateTimeInterface) {
            return self::checked($value->format('Y-m-d'));
        }

        if (is_int($value) || is_float($value)) {
            if ($value < 1) {
                return [null, null];
            }

            $date = XlsxStreamReader::serialToDate((float) $value);

            return $date === null ? [null, 'implausible'] : self::checked($date->format('Y-m-d'));
        }

        $text = trim((string) $value);

        if ($text === '' || preg_match('/^[0\/\-\.\s:]+$/', $text)) {
            return [null, null];
        }

        if (is_numeric($text) && (float) $text > 1000) {
            $date = XlsxStreamReader::serialToDate((float) $text);

            return $date === null ? [null, 'implausible'] : self::checked($date->format('Y-m-d'));
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd-M-Y', 'd M Y', 'j/n/Y', 'Y/m/d', 'M d, Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $text);
            $errors = \DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return self::checked($date->format('Y-m-d'));
            }
        }

        return [null, 'unreadable'];
    }

    /** @return array{0: ?string, 1: ?string} */
    protected static function checked(string $ymd): array
    {
        $year = (int) substr($ymd, 0, 4);

        return $year < self::MIN_YEAR || $year > self::MAX_YEAR ? [null, 'implausible'] : [$ymd, null];
    }

    /**
     * Ghana mobile numbers from a cell that may hold several ("0241234567 / 0207654321"), each normalised to 10 digits
     * starting with 0 (+233 / 233 / a missing leading 0 are repaired). Anything else is invalid.
     *
     * @return array{0: list<string>, 1: bool} the valid numbers (unique, in order) and whether any token was invalid
     */
    public static function phones(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [[], false];
        }

        $raw = is_int($value) || is_float($value) ? (string) (int) $value : (string) $value;
        $valid = [];
        $invalid = false;

        foreach (preg_split('/[\/,;|\n]+/u', $raw) ?: [] as $token) {
            $digits = preg_replace('/\D/', '', $token) ?? '';

            if ($digits === '') {
                continue;
            }

            if (str_starts_with($digits, '233') && strlen($digits) === 12) {
                $digits = '0'.substr($digits, 3);
            } elseif (strlen($digits) === 9) {
                $digits = '0'.$digits;
            }

            if (preg_match('/^0\d{9}$/', $digits)) {
                $valid[$digits] = $digits;
            } else {
                $invalid = true;
            }
        }

        return [array_values($valid), $invalid];
    }

    public static function email(mixed $value): ?string
    {
        $text = self::text($value, 191);

        return $text === null || ! str_contains($text, '@') ? null : $text;
    }

    /** Meter number: blank, "0" and similar placeholders are no meter number. */
    public static function meterNo(mixed $value): ?string
    {
        $text = self::text($value, 20);

        if ($text === null || preg_match('/^0+$/', $text) || in_array(mb_strtoupper($text), ['NONE', 'N/A', 'NA', '-'], true)) {
            return null;
        }

        return $text;
    }

    /** "024****567": the first three and last three digits only. */
    public static function maskPhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($digits) < 7 ? str_repeat('*', strlen($digits)) : substr($digits, 0, 3).str_repeat('*', strlen($digits) - 6).substr($digits, -3);
    }

    /** "a***@example.com". */
    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || $email === '' || ! str_contains($email, '@')) {
            return $email === null || $email === '' ? null : '***';
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
