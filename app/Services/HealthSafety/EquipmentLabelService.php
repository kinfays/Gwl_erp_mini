<?php

namespace App\Services\HealthSafety;

use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\User;
use App\Services\HealthSafety\Concerns\RendersLabelPdf;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * QR label sheets for extinguishers and first aid kits (design 8.12). A label carries the scan link (numeric id, never
 * data), the asset code, the item type, where it is and "Scan to record monthly check": no dates, because a sticker
 * outlives them. Items are taken only from the actor's part of the register; one outside it is left out and counted,
 * never printed. label_printed_at is stamped for the items on a sheet only after the PDF has rendered.
 */
class EquipmentLabelService
{
    use RendersLabelPdf;

    public const TYPE_EXTINGUISHER = 'extinguisher';
    public const TYPE_KIT = 'kit';

    public const LAYOUT_STANDARD = 'standard';
    public const LAYOUT_LARGE = 'large';

    /** columns x rows per A4 page */
    public const LAYOUTS = [
        self::LAYOUT_STANDARD => ['label' => 'Standard (24 per page)', 'columns' => 3, 'rows' => 8],
        self::LAYOUT_LARGE => ['label' => 'Large (10 per page)', 'columns' => 2, 'rows' => 5],
    ];

    public function __construct(
        protected EquipmentScope $scope,
        protected QrCodeGenerator $qr,
        protected QrLinks $links,
    ) {}

    public static function max(): int
    {
        return max(1, (int) config('gwl.hs_labels_per_pdf_max'));
    }

    /** The ids in a query, up to one more than the maximum (enough to know it is too many). @return list<int> */
    public function idsFrom(Builder $query): array
    {
        $key = $query->qualifyColumn('id');

        return $query->reorder()->orderBy($key)->limit(self::max() + 1)->pluck($key)->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Render the sheet for these ids and record that they were printed.
     *
     * @param  list<int|string>  $ids
     * @return array{pdf: string, count: int, excluded: int, filename: string}
     */
    public function print(User $actor, string $type, array $ids, string $layout = self::LAYOUT_STANDARD): array
    {
        abort_unless($this->scope->can($actor, 'health_safety.manage_equipment'), 403, 'You may not print labels.');
        abort_unless(in_array($type, [self::TYPE_EXTINGUISHER, self::TYPE_KIT], true), 404);

        $layout = array_key_exists($layout, self::LAYOUTS) ? $layout : self::LAYOUT_STANDARD;
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['labels' => 'Choose at least one item to print a label for.']);
        }

        if ($ids->count() > self::max()) {
            throw ValidationException::withMessages(['labels' => 'A sheet holds at most '.self::max().' labels. Narrow the filter or select fewer items.']);
        }

        $decommissioned = $type === self::TYPE_KIT ? HsFirstAidKit::STATUS_DECOMMISSIONED : HsFireExtinguisher::STATUS_DECOMMISSIONED;

        $items = ($type === self::TYPE_KIT ? $this->scope->kits($actor) : $this->scope->extinguishers($actor))
            ->whereIn('id', $ids->all())
            ->where('status', '!=', $decommissioned)
            ->with(['site', 'vehicle', 'region'])
            ->orderBy('asset_code')
            ->get();

        $excluded = $ids->count() - $items->count();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['labels' => 'None of those items can be labelled from your part of the register.']);
        }

        $labels = $items->map(fn ($item) => $this->labelFor($type, $item))->all();
        $perPage = self::LAYOUTS[$layout]['columns'] * self::LAYOUTS[$layout]['rows'];

        $html = view('health_safety.labels', [
            'pages' => array_chunk($labels, $perPage),
            'layout' => $layout,
            'columns' => self::LAYOUTS[$layout]['columns'],
            'rows' => self::LAYOUTS[$layout]['rows'],
            'title' => $type === self::TYPE_KIT ? 'First aid kit labels' : 'Fire extinguisher labels',
        ])->render();

        $pdf = $this->renderPdf($html);

        // Only now, with a PDF in hand, is anything marked as printed.
        DB::transaction(function () use ($type, $items, $excluded, $layout) {
            $table = $items->first()->getTable();
            DB::table($table)->whereIn('id', $items->pluck('id')->all())->update(['label_printed_at' => now()]);

            Audit::log('health_safety.labels_printed', 'health_safety', $table, null, [
                'type' => $type,
                'count' => $items->count(),
                'excluded' => $excluded,
                'first' => $items->first()->asset_code,
                'last' => $items->last()->asset_code,
                'layout' => $layout,
                'base_url' => $this->links->baseUrl(),
            ]);
        });

        return [
            'pdf' => $pdf,
            'count' => $items->count(),
            'excluded' => $excluded,
            'filename' => ($type === self::TYPE_KIT ? 'kit-labels-' : 'extinguisher-labels-').now()->format('Ymd-His').'.pdf',
        ];
    }

    /** @return array{qr: string, url: string, code: string, kind: string, site: string, where: string, region: string, line: string} */
    public function labelFor(string $type, HsFireExtinguisher|HsFirstAidKit $item): array
    {
        $url = $this->links->scanUrl($type, $item->id);

        $site = $item->vehicle_id ? 'Vehicle '.($item->vehicle?->number_plate ?? '') : (string) $item->site?->name;

        $kind = $type === self::TYPE_KIT
            ? 'First aid kit - '.$item->typeLabel()
            : 'Fire extinguisher - '.$item->typeLabel().($item->capacity ? ' '.$item->capacity : '');

        return [
            'qr' => $this->qr->dataUri($url),
            'url' => $url,
            'code' => (string) $item->asset_code,
            'kind' => $kind,
            'site' => Str::limit(trim($site), 50),
            'where' => Str::limit((string) ($item->location_detail ?? ''), 60),
            'region' => (string) ($item->region?->region_name ?? ''),
            'line' => 'Scan to record monthly check',
        ];
    }
}
