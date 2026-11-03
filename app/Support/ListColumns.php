<?php

namespace App\Support;

/**
 * Which columns of a list are shown, decided in one place for both the Livewire list (HasSelectableColumns) and the
 * export controllers, so the screen and the file can never read the ?cols= parameter differently.
 *
 * A manifest is an ORDERED map: key => ['label' => string, 'default' => bool (default true), 'locked' => bool
 * (default false, never hidden), 'export' => bool (default true, false = never written to a file)].
 */
class ListColumns
{
    /**
     * The keys to show, in manifest order. Unknown keys in the parameter are ignored (it comes from the query string),
     * a parameter with no valid key means "the defaults", and locked columns are always included.
     *
     * @param  array<string, array<string, mixed>>  $manifest
     * @return array<int, string>
     */
    public static function resolve(array $manifest, ?string $param): array
    {
        $requested = array_filter(array_map('trim', explode(',', (string) $param)), fn (string $key) => array_key_exists($key, $manifest));

        $chosen = $requested === []
            ? array_keys(array_filter($manifest, fn (array $column) => $column['default'] ?? true))
            : array_values($requested);

        return array_values(array_filter(
            array_keys($manifest),
            fn (string $key) => ($manifest[$key]['locked'] ?? false) || in_array($key, $chosen, true)
        ));
    }

    /**
     * The visible keys that may be written to an export file: not actions, and never credentials (their manifest entry
     * says `export => false`).
     *
     * @param  array<string, array<string, mixed>>  $manifest
     * @return array<int, string>
     */
    public static function exportable(array $manifest, ?string $param): array
    {
        return array_values(array_filter(
            self::resolve($manifest, $param),
            fn (string $key) => $manifest[$key]['export'] ?? true
        ));
    }
}
