<?php

namespace App\Services\Assets\Mdm;

use Illuminate\Support\Str;

/**
 * Display text for Google's values. AMAPI enums arrive as SHOUTING_SNAKE_CASE, which Str::headline() spells out one
 * letter at a time ("A C T I V E"), so they are lower-cased first; camelCase field names (settingName) are already fine.
 */
class Labels
{
    public static function enum(?string $value, string $fallback = '—'): string
    {
        $value = trim((string) $value);

        return $value === '' ? $fallback : Str::headline(Str::lower($value));
    }

    /** "screenCaptureDisabled" → "Screen Capture Disabled"; also copes with SCREEN_CAPTURE_DISABLED. */
    public static function setting(?string $value, string $fallback = 'Setting'): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $fallback;
        }

        return $value === Str::upper($value) ? self::enum($value) : Str::headline($value);
    }
}
