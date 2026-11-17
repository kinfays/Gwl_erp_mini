<?php

namespace App\Services\Commercial\Trends;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Models\CommercialCustomerBatch;
use App\Models\CommercialImportBatch;
use App\Models\District;
use App\Services\Commercial\CommercialPeriods;
use App\Services\Commercial\Customers\CustomerScope;
use App\Services\Commercial\Customers\CustomerSnapshots;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * T8, COVERAGE AND TIMELINESS (Phase 8f, design 8.45): for each report type and each district that appears in its uploads, which days of a month the live
 * uploads cover (and the gaps), how LATE each upload was, and when each district last uploaded.
 *
 *   covered     a day is covered when a live upload that holds rows of the district has it inside its period (the customer list: its as-of date, one day).
 *   lateness    the day the upload was imported minus the last day of its period (the customer list: minus its as-of date), in days; 0 or less is on time.
 *   on time     lateness no greater than commercial_upload_deadline_days. The share on time is on time / uploads, per district and (not for a district
 *               user, who has no region-wide row) for the type as a whole.
 *   recency     per district the date of its newest import and how many days ago that was, so the districts that uploaded last and longest ago can be read
 *               off one table.
 *
 * A district is "present" in an upload when the upload holds a row of it (reading by route, the detailed billing rollups, the billing summary's routes,
 * the legacy reading stats) or, for the customer list, when it is the district of the batch. A district user sees only their district (CommercialScope).
 */
class UploadTimeliness
{
    use ScopesCommercialByActor;

    /** key => label, the batch type (null for the customer list) and the permission its data needs. */
    public const TYPES = [
        'reading_route' => ['label' => 'Reading by route and CCA', 'type' => CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY, 'needs' => 'reading', 'table' => 'commercial_reading_route_stats'],
        'billing_details' => ['label' => 'Detailed billing', 'type' => CommercialImportBatch::TYPE_BILLING_DETAILS, 'needs' => 'billing', 'table' => 'commercial_billing_detail_rollups'],
        'billing_summary' => ['label' => 'Billing summary', 'type' => CommercialImportBatch::TYPE_BILLING_SUMMARY, 'needs' => 'billing', 'table' => 'commercial_billing_routes', 'source' => CommercialImportBatch::SOURCE_UPLOAD],
        // Phase 8h: the summary built from the Detailed Billing Report is counted on its own line (it is an upload of the detailed report, with that report's own timing)
        'billing_summary_derived' => ['label' => 'Billing summary (from the detailed report)', 'type' => CommercialImportBatch::TYPE_BILLING_SUMMARY, 'needs' => 'billing', 'table' => 'commercial_billing_routes', 'source' => CommercialImportBatch::SOURCE_DERIVED],
        'reading_legacy' => ['label' => 'Meter reading by month (older report)', 'type' => CommercialImportBatch::TYPE_READING_SUMMARY, 'needs' => 'reading', 'table' => 'commercial_reading_stats'],
        'customers' => ['label' => 'Customer list', 'type' => null, 'needs' => 'customers', 'table' => null],
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(string $key, int $regionId, string $month, ?int $districtId = null): array
    {
        $def = self::TYPES[$key];
        $first = Carbon::parse($month.'-01')->startOfMonth();
        $last = $first->copy()->endOfMonth();
        $deadline = max(0, (int) config('gwl.commercial_upload_deadline_days', 3));
        $uploads = $key === 'customers' ? $this->customerUploads($regionId, $districtId) : $this->routeUploads($def, $regionId, $districtId);
        $names = District::query()->pluck('district_name', 'id');

        $byDistrict = [];

        foreach ($uploads as $u) {
            foreach ($u['districts'] as $id) {
                $byDistrict[$id][] = $u;
            }
        }

        $districts = [];

        foreach ($byDistrict as $id => $list) {
            $covered = [];

            foreach ($list as $u) {
                $from = Carbon::parse($u['from'])->startOfDay();
                $to = Carbon::parse($u['to'])->startOfDay();

                for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                    if ($d->betweenIncluded($first, $last)) {
                        $covered[(int) $d->day] = true;
                    }
                }
            }

            $days = [];
            $gaps = [];
            $open = null;

            for ($day = 1; $day <= $last->day; $day++) {
                $days[$day] = isset($covered[$day]);

                if (! $days[$day] && $open === null) {
                    $open = $day;
                }

                if (($days[$day] || $day === $last->day) && $open !== null) {
                    $end = $days[$day] ? $day - 1 : $day;
                    $gaps[] = [$first->copy()->day($open)->toDateString(), $first->copy()->day($end)->toDateString()];
                    $open = null;
                }
            }

            $onTime = count(array_filter($list, fn (array $u) => $u['lateness'] <= $deadline));
            $newest = collect($list)->sortByDesc(fn (array $u) => [$u['imported'], $u['id']])->first();

            $districts[] = [
                'id' => (int) $id, 'name' => (string) ($names[$id] ?? 'District '.$id), 'days' => $days, 'covered_days' => count($covered), 'days_in_month' => $last->day, 'gaps' => $gaps,
                'uploads' => count($list), 'on_time' => $onTime, 'late' => count($list) - $onTime, 'on_time_share' => round($onTime / count($list) * 100, 2),
                'last_import' => $newest['imported'], 'last_label' => $newest['label'], 'days_ago' => (int) Carbon::parse($newest['imported'])->startOfDay()->diffInDays(now()->startOfDay()),
            ];
        }

        usort($districts, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        $total = count($uploads);
        $onTimeAll = count(array_filter($uploads, fn (array $u) => $u['lateness'] <= $deadline));

        return [
            'key' => $key, 'label' => $def['label'], 'needs' => $def['needs'], 'month' => $first->format('Y-m'), 'month_label' => $first->format('F Y'), 'days_in_month' => $last->day,
            'deadline_days' => $deadline, 'districts' => $districts,
            'uploads' => array_map(fn (array $u) => $u + ['late' => $u['lateness'] > $deadline], $uploads),
            // a district user has no region-wide row: the share of the whole type is only for those who see every district of the region
            'on_time_share' => $this->actorDistrictLimited() || $total === 0 ? null : round($onTimeAll / $total * 100, 2),
            'on_time' => $onTimeAll, 'total' => $total,
            'recency' => collect($districts)->sortBy([['days_ago', 'asc'], ['name', 'asc']])->map(fn (array $d) => ['id' => $d['id'], 'name' => $d['name'], 'last_import' => $d['last_import'], 'days_ago' => $d['days_ago'], 'last_label' => $d['last_label']])->values()->all(),
        ];
    }

    // ---------------------------------------------------------------- the uploads of a report type

    /**
     * @param  array{type: string|null, table: string|null}  $def
     * @return list<array<string, mixed>> newest first
     */
    protected function routeUploads(array $def, int $regionId, ?int $districtId): array
    {
        $batches = $this->scopeBatchesForActor(CommercialImportBatch::query())->ofType($def['type'])->live()->where('region_id', $regionId)->when(isset($def['source']), fn ($q) => $q->where('source', $def['source']))->get();

        if ($batches->isEmpty()) {
            return [];
        }

        $query = DB::table($def['table'])->whereIn('batch_id', $batches->pluck('id')->all())->whereNotNull('district_id');
        $this->commercialScope()->limitDistricts($query, 'district_id');
        $present = $query->select('batch_id', 'district_id')->distinct()->get()->groupBy('batch_id')->map(fn ($rows) => $rows->pluck('district_id')->map(fn ($id) => (int) $id)->all());

        $out = [];

        foreach ($batches as $b) {
            $in = $present[$b->id] ?? [];

            if ($districtId !== null) {
                $in = array_values(array_intersect($in, [$districtId]));
            }

            if ($in === []) {
                continue;
            }

            $imported = Carbon::parse($b->imported_at ?? $b->created_at)->startOfDay();
            // the older by-month reading report keeps the first day of each month as its dates: its period is the WHOLE of those months
            $from = $def['type'] === CommercialImportBatch::TYPE_READING_SUMMARY ? $b->period_from->copy()->startOfMonth() : $b->period_from->copy()->startOfDay();
            $to = $def['type'] === CommercialImportBatch::TYPE_READING_SUMMARY ? $b->period_to->copy()->endOfMonth()->startOfDay() : $b->period_to->copy()->startOfDay();
            $out[] = [
                'id' => (int) $b->id, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => CommercialPeriods::describe($from, $to),
                'imported' => $imported->toDateString(), 'lateness' => (int) $to->diffInDays($imported, false), 'districts' => $in,
            ];
        }

        usort($out, fn (array $a, array $b) => [$b['to'], $b['id']] <=> [$a['to'], $a['id']]);

        return $out;
    }

    /**
     * The customer list: one batch per district and file; a snapshot covers its as-of date, and a daily one is labelled by it.
     *
     * @return list<array<string, mixed>>
     */
    protected function customerUploads(int $regionId, ?int $districtId): array
    {
        if (! config('gwl.commercial_customer_list_enabled')) {
            return [];
        }

        $restriction = CustomerScope::regionRestriction($this->actor());
        $districtIds = CustomerScope::districtIds($this->actor());

        $batches = app(CustomerSnapshots::class)->scoped($restriction, $districtIds)
            ->where('region_id', $regionId)->whereNotNull('district_id')->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId))->get();

        $out = [];

        foreach ($batches as $b) {
            $asOf = Carbon::parse($b->as_of_date)->startOfDay();
            $imported = Carbon::parse($b->imported_at ?? $b->created_at)->startOfDay();
            $out[] = [
                'id' => (int) $b->id, 'from' => $asOf->toDateString(), 'to' => $asOf->toDateString(), 'label' => self::snapshotLabel($b),
                'imported' => $imported->toDateString(), 'lateness' => (int) $asOf->diffInDays($imported, false), 'districts' => [(int) $b->district_id],
            ];
        }

        usort($out, fn (array $a, array $b) => [$b['to'], $b['id']] <=> [$a['to'], $a['id']]);

        return $out;
    }

    /** A snapshot's label: a daily one by its date, a weekly one by its week, a monthly one by its month; always with the as-of date. */
    public static function snapshotLabel(CommercialCustomerBatch $b): string
    {
        $asOf = Carbon::parse($b->as_of_date);

        return match ($b->period_type) {
            CommercialCustomerBatch::PERIOD_DAILY => $asOf->format('j M Y'),
            CommercialCustomerBatch::PERIOD_WEEKLY => 'week '.$b->period_key.' (as at '.$asOf->format('j M Y').')',
            default => $asOf->format('M Y').' (as at '.$asOf->format('j M Y').')',
        };
    }
}
