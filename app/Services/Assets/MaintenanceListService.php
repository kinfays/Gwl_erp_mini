<?php

namespace App\Services\Assets;

use App\Models\IctAsset;
use App\Models\IctAssetMaintenance;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Maintenance log's filters and its file columns, written once so the screen and the Excel / PDF export cannot
 * disagree. Scoping is NOT done here: callers pass a query that already went through scopeAssetsForViewing().
 */
class MaintenanceListService
{
    /**
     * @param  array{search?: string, status?: string, type?: string, due?: bool|string|int|null}  $filters
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? '');
        $type = (string) ($filters['type'] ?? '');
        $due = filter_var($filters['due'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $query
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

                $query->where(function (Builder $inner) use ($like) {
                    $inner->whereRaw("maintenance_type LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("technician LIKE ? ESCAPE '!'", [$like])
                        ->orWhereHas('asset', function (Builder $asset) use ($like) {
                            $asset->where(function (Builder $match) use ($like) {
                                $match->whereRaw("asset_name LIKE ? ESCAPE '!'", [$like])
                                    ->orWhereRaw("serial_number LIKE ? ESCAPE '!'", [$like])
                                    ->orWhereHas('district', fn (Builder $district) => $district->whereRaw("district_name LIKE ? ESCAPE '!'", [$like]));
                            });
                        });
                });
            })
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($type !== '', fn (Builder $query) => $query->where('maintenance_type', $type))
            ->when($due, fn (Builder $query) => $query->where('status', IctAssetMaintenance::STATUS_OPEN));
    }

    /** The location of a maintenance record is the linked asset's: its district, and the region it belongs to. */
    public static function location(?IctAsset $asset): string
    {
        if (! $asset) {
            return '';
        }

        return collect([$asset->district?->district_name, $asset->region?->region_name])->filter()->unique()->implode(', ');
    }

    public static function assetTypeLabel(?string $code): string
    {
        foreach (IctAsset::ASSET_TYPES as $types) {
            if (isset($types[$code])) {
                return $types[$code];
            }
        }

        return (string) $code;
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'Asset name',
            'Asset type',
            'Serial number',
            'Maintenance type',
            'Status',
            'Reported date',
            'Completed date',
            'Technician',
            'Location',
            'Notes',
        ];
    }

    /** One file row, in heading order. Every value is plain text: the export classes keep it text. @return array<int, string> */
    public function row(IctAssetMaintenance $record): array
    {
        $asset = $record->asset;

        return [
            (string) $asset?->asset_name,
            self::assetTypeLabel($asset?->asset_type),
            (string) $asset?->serial_number,
            (string) $record->maintenance_type,
            (string) $record->status,
            $record->reported_date?->format('d M Y') ?? '',
            $record->completion_date?->format('d M Y') ?? '',
            (string) $record->technician,
            self::location($asset),
            (string) $record->notes,
        ];
    }
}
