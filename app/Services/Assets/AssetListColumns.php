<?php

namespace App\Services\Assets;

use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;

/**
 * The columns of the four Assets lists (assets, phones, network, reports): which exist, which are locked or off by
 * default, and what each one says as plain text in an export. The Livewire lists and the export controllers both read
 * this, so the screen and the file cannot disagree about a column. Manifest shape: see App\Support\ListColumns.
 */
class AssetListColumns
{
    public const ASSETS = 'assets';

    public const PHONES = 'phones';

    public const NETWORK = 'network';

    public const REPORTS = 'reports';

    /** @return array<string, array<string, mixed>> */
    public static function manifest(string $list): array
    {
        return match ($list) {
            self::ASSETS => [
                'asset' => ['label' => 'Asset', 'default' => true, 'locked' => true],
                'type_model' => ['label' => 'Type / Model', 'default' => true],
                'processor' => ['label' => 'Processor', 'default' => true],
                'location' => ['label' => 'Location', 'default' => true],
                'assigned_to' => ['label' => 'Assigned To', 'default' => true],
                'status' => ['label' => 'Status', 'default' => true],
                'ups' => ['label' => 'UPS', 'default' => true],
                'actions' => ['label' => 'Actions', 'default' => true, 'locked' => true, 'export' => false],
            ],
            self::PHONES => [
                'device' => ['label' => 'Device', 'default' => true, 'locked' => true],
                'type_model' => ['label' => 'Type / Model', 'default' => true],
                'numbers' => ['label' => 'Numbers', 'default' => true],
                'assigned_to' => ['label' => 'Assigned To', 'default' => true],
                'status' => ['label' => 'Status', 'default' => true],
                'actions' => ['label' => 'Actions', 'default' => true, 'locked' => true, 'export' => false],
            ],
            self::NETWORK => [
                'device' => ['label' => 'Device', 'default' => true, 'locked' => true],
                'type_model' => ['label' => 'Type / Model', 'default' => true],
                'network' => ['label' => 'Network', 'default' => true],
                // Toggleable but OFF by default, and never written to a file: exported files are downloaded and forwarded.
                'credentials' => ['label' => 'Credentials', 'default' => false, 'export' => false],
                'location' => ['label' => 'Location', 'default' => true],
                'status' => ['label' => 'Status', 'default' => true],
                'actions' => ['label' => 'Actions', 'default' => true, 'locked' => true, 'export' => false],
            ],
            self::REPORTS => [
                'title' => ['label' => 'Title', 'default' => true, 'locked' => true],
                'type' => ['label' => 'Type', 'default' => true],
                'asset' => ['label' => 'Asset', 'default' => true],
                'location' => ['label' => 'Location', 'default' => true],
                'status' => ['label' => 'Status', 'default' => true],
                'date_solved' => ['label' => 'Date Solved', 'default' => true],
                'actions' => ['label' => 'Actions', 'default' => true, 'locked' => true, 'export' => false],
            ],
            default => throw new \InvalidArgumentException("Unknown list [{$list}]."),
        };
    }

    /** Relations an export must eager-load to build the cells of a list. */
    public static function relations(string $list): array
    {
        return match ($list) {
            self::ASSETS => ['assetModel', 'assignedTo', 'district', 'region'],
            self::PHONES => ['assetModel', 'assignedTo'],
            self::NETWORK => ['assetModel', 'district'],
            self::REPORTS => ['asset', 'district', 'region'],
        };
    }

    /**
     * What one on-screen column becomes in an export file: heading => the field that fills it. A column that shows
     * several things stacked (name over serial, type over model) is several file columns, so the spreadsheet can be
     * sorted and filtered on each. Order here is the order in the file.
     *
     * @return array<string, string> heading => field id understood by value()
     */
    public static function exportParts(string $list, string $key): array
    {
        return match ($list) {
            self::ASSETS => match ($key) {
                'asset' => ['Asset Name' => 'name', 'Serial Number' => 'serial'],
                'type_model' => ['Type' => 'type', 'Model' => 'model'],
                'processor' => ['Processor' => 'processor'],
                'location' => ['District' => 'district', 'Region' => 'region'],
                'assigned_to' => ['Assigned To' => 'assigned_to'],
                'status' => ['Status' => 'status'],
                'ups' => ['On UPS' => 'has_ups', 'UPS Condition' => 'ups_status'],
                default => [],
            },
            self::PHONES => match ($key) {
                'device' => ['Device Name' => 'name', 'Serial Number' => 'serial', 'IMEI' => 'imei'],
                'type_model' => ['Type' => 'type', 'Model' => 'model'],
                'numbers' => ['Device Number' => 'device_number', 'User Number' => 'user_number'],
                'assigned_to' => ['Assigned To' => 'assigned_to'],
                'status' => ['Status' => 'status'],
                default => [],
            },
            self::NETWORK => match ($key) {
                'device' => ['Device Name' => 'name', 'Serial Number' => 'serial'],
                'type_model' => ['Type' => 'type', 'Model' => 'model'],
                'network' => ['IP Address' => 'ip', 'SSID' => 'ssid'],
                'location' => ['District' => 'district', 'Location Note' => 'location_note'],
                'status' => ['Status' => 'status'],
                default => [],
            },
            self::REPORTS => match ($key) {
                'title' => ['Title' => 'title'],
                'type' => ['Issue Type' => 'issue_type', 'Issue Subtype' => 'issue_subtype'],
                'asset' => ['Linked Asset' => 'asset_name', 'Asset Serial Number' => 'asset_serial'],
                'location' => ['District' => 'district', 'Region' => 'region'],
                'status' => ['Status' => 'status'],
                'date_solved' => ['Date Solved' => 'date_solved'],
                default => [],
            },
            default => throw new \InvalidArgumentException("Unknown list [{$list}]."),
        };
    }

    /** The headings an export holds for these column keys, in order. */
    public static function exportHeadings(string $list, array $keys): array
    {
        return array_merge([], ...array_map(fn (string $key) => array_keys(self::exportParts($list, $key)), $keys));
    }

    /** One export row for these column keys, aligned with exportHeadings(). */
    public static function exportRow(string $list, array $keys, IctAsset|IctAssetIssueReport $row): array
    {
        $cells = [];

        foreach ($keys as $key) {
            foreach (self::exportParts($list, $key) as $field) {
                $cells[] = self::value($row, $field);
            }
        }

        return $cells;
    }

    /** One plain-text value; '' where it does not apply. */
    protected static function value(IctAsset|IctAssetIssueReport $row, string $field): string
    {
        if ($row instanceof IctAssetIssueReport) {
            return match ($field) {
                'title' => (string) $row->title,
                'issue_type' => (string) $row->issue_type,
                'issue_subtype' => (string) $row->issue_subtype,
                'asset_name' => $row->asset?->asset_name ?: 'Unlinked',
                'asset_serial' => (string) $row->asset?->serial_number,
                'district' => (string) $row->district?->district_name,
                'region' => (string) $row->region?->region_name,
                'status' => (string) $row->status,
                'date_solved' => $row->date_solved?->format('d M Y') ?? '',
                default => '',
            };
        }

        $supportsUps = IctAsset::supportsUps($row->device_category, $row->asset_type);

        return match ($field) {
            'name' => (string) $row->asset_name,
            'serial' => (string) $row->serial_number,
            'imei' => (string) $row->imei,
            'type' => IctAsset::ASSET_TYPES[$row->device_category][$row->asset_type] ?? (string) $row->asset_type,
            'model' => (string) $row->assetModel?->name,
            'processor' => IctAsset::supportsProcessor($row->device_category, $row->asset_type) ? (string) $row->cpu_name : '',
            'district' => (string) $row->district?->district_name,
            'region' => (string) $row->region?->region_name,
            'location_note' => (string) $row->actual_location,
            'assigned_to' => $row->assigneeLabel(),
            'status' => (string) $row->status,
            'has_ups' => $supportsUps ? ($row->has_ups ? 'Yes' : 'No') : '',
            'ups_status' => $supportsUps && $row->has_ups ? (string) $row->ups_status : '',
            'device_number' => (string) $row->device_phone_number,
            'user_number' => (string) $row->user_phone_number,
            'ip' => (string) $row->device_ip,
            'ssid' => (string) $row->ssid,
            default => '',
        };
    }
}
