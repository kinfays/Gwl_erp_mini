<?php

namespace App\Services\Assets;

use App\Models\IctAsset;

/**
 * What differs between the three asset uploads (Assets, Phones, Network): the columns, which are required, which cells
 * must stay text, where the staged file lives, the audit action and the screens. It holds NO row rules: those live
 * only in AssetImportService::analyze() and judge(), which read the profile.
 */
final class AssetImportProfile
{
    public const ASSETS = 'assets';

    public const PHONES = 'phones';

    public const NETWORK = 'network';

    public const TYPES = [self::ASSETS, self::PHONES, self::NETWORK];

    /** A first cell starting like this marks the template's example row: it is skipped, never imported. */
    public const EXAMPLE_PREFIX = 'EXAMPLE - ';

    /** @param  array<string, array{0: string, 1: bool, 2: string}>  $columns key => [heading, required, example] */
    private function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly string $noun,
        public readonly string $category,
        public readonly array $columns,
        /** Columns whose cells are identifiers or numbers with leading zeros: a number cell there is reported. */
        public readonly array $textColumns,
        public readonly string $auditAction,
        public readonly string $directory,
        public readonly string $sessionKey,
        public readonly string $routeBase,
        public readonly string $listRoute,
        public readonly string $sheetName,
    ) {}

    public static function exists(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** An unknown type is a 404: nothing is guessed. */
    public static function for(string $type): self
    {
        $directory = AssetImportService::DIRECTORY;

        return match ($type) {
            self::ASSETS => new self(
                type: self::ASSETS,
                label: 'Assets',
                noun: 'asset',
                category: IctAsset::DEVICE_CATEGORY_ASSET,
                columns: AssetImportService::COLUMNS,
                textColumns: ['serial_number', 'asset_tag'],
                auditAction: 'import_assets',
                // The original upload kept its files in the folder itself; the new types get a folder each, so a staged
                // Phones file can never be opened as a Network (or Assets) import.
                directory: $directory,
                sessionKey: 'asset_import_preview',
                routeBase: 'assets.import',
                listRoute: 'assets.assets',
                sheetName: 'Assets',
            ),
            self::PHONES => new self(
                type: self::PHONES,
                label: 'Phones',
                noun: 'phone device',
                category: IctAsset::DEVICE_CATEGORY_PHONE,
                columns: AssetImportService::PHONE_COLUMNS,
                textColumns: ['serial_number', 'imei', 'user_phone_number', 'device_phone_number'],
                auditAction: 'import_phones',
                directory: $directory.'/'.self::PHONES,
                sessionKey: 'asset_import_preview_phones',
                routeBase: 'assets.import.phones',
                listRoute: 'assets.phones',
                sheetName: 'Phones',
            ),
            self::NETWORK => new self(
                type: self::NETWORK,
                label: 'Network',
                noun: 'network device',
                category: IctAsset::DEVICE_CATEGORY_NETWORK,
                columns: AssetImportService::NETWORK_COLUMNS,
                textColumns: ['serial_number', 'mac_address'],
                auditAction: 'import_network',
                directory: $directory.'/'.self::NETWORK,
                sessionKey: 'asset_import_preview_network',
                routeBase: 'assets.import.network',
                listRoute: 'assets.network',
                sheetName: 'Network',
            ),
            default => abort(404, 'Unknown upload type.'),
        };
    }

    /** @return array<string, string> asset_type code => label, for this profile's category only */
    public function types(): array
    {
        return IctAsset::ASSET_TYPES[$this->category];
    }

    public function has(string $column): bool
    {
        return array_key_exists($column, $this->columns);
    }

    public function heading(string $column): string
    {
        return $this->columns[$column][0] ?? $column;
    }

    /** The heading texts in file order. @return list<string> */
    public function headings(): array
    {
        return array_values(array_map(fn (array $column) => $column[0], $this->columns));
    }
}
