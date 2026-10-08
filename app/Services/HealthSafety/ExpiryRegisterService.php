<?php

namespace App\Services\HealthSafety;

use App\Models\Employee;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\HsFirstAidKitItem;
use App\Models\HsPpeIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The ONE source of the dated items (design 8.18 A): one row per DUE DATE, not per unit, so an extinguisher with an expiry,
 * a service and a hydrostatic test date is three rows. Five row types: extinguisher expiry, service and hydrostatic test,
 * first aid kit item expiry, and the replacement date of an open PPE issue. Only in-service units (the same "evaluated"
 * statuses the Phase 2 states use) and open issues count; checks are NOT rows (a monthly check interval would put every
 * unit on the register every month, and overdue checks stay on the Overview and the list filters).
 *
 * Each type is built as a scoped, filtered query object (sources()); rows(), counts() and the alert command all read those
 * same queries, so the register, its counts, its exports and the alerts cannot disagree. The buckets use the settings:
 * overdue (before today), critical (within hs_expiry_critical_days), due soon (within hs_expiry_warning_days), later.
 */
class ExpiryRegisterService
{
    public const TYPE_EXTINGUISHER_EXPIRY = 'extinguisher_expiry';
    public const TYPE_EXTINGUISHER_SERVICE = 'extinguisher_service';
    public const TYPE_EXTINGUISHER_HYDRO = 'extinguisher_hydro';
    public const TYPE_KIT_ITEM = 'kit_item_expiry';
    public const TYPE_PPE = 'ppe_replacement';

    public const TYPES = [
        self::TYPE_EXTINGUISHER_EXPIRY => 'Extinguisher expiry',
        self::TYPE_EXTINGUISHER_SERVICE => 'Extinguisher service',
        self::TYPE_EXTINGUISHER_HYDRO => 'Hydrostatic test',
        self::TYPE_KIT_ITEM => 'First aid kit item',
        self::TYPE_PPE => 'PPE replacement',
    ];

    public const BUCKET_OVERDUE = 'overdue';
    public const BUCKET_CRITICAL = 'critical';
    public const BUCKET_DUE_SOON = 'due_soon';
    public const BUCKET_LATER = 'later';

    public const BUCKETS = [
        self::BUCKET_OVERDUE => 'Overdue',
        self::BUCKET_CRITICAL => 'Critical',
        self::BUCKET_DUE_SOON => 'Due soon',
        self::BUCKET_LATER => 'Later',
    ];

    /** The horizons the screen offers (days ahead); "overdue" is also accepted. */
    public const HORIZONS = [30, 60, 90, 180];

    public const DEFAULT_HORIZON = 90;

    public function __construct(protected EquipmentScope $scope) {}

    /**
     * Which bucket a number of days to the due date falls in (negative = overdue).
     */
    public static function bucketFor(int $days): string
    {
        return match (true) {
            $days < 0 => self::BUCKET_OVERDUE,
            $days <= (int) HealthSafetySettings::value('hs_expiry_critical_days') => self::BUCKET_CRITICAL,
            $days <= (int) HealthSafetySettings::value('hs_expiry_warning_days') => self::BUCKET_DUE_SOON,
            default => self::BUCKET_LATER,
        };
    }

    /**
     * The five scoped, filtered query objects, keyed by row type. Filters, all optional: type, bucket, horizon (days ahead,
     * or "overdue"; default 90, overdue items always included), region_id (only honoured for users who see every region),
     * district_id, site_id (PPE rows have no site, so a site filter leaves them out).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, Builder>
     */
    public function sources(?User $actor, array $filters = []): array
    {
        $sources = [];
        $type = $filters['type'] ?? null;
        $wanted = fn (string $name) => blank($type) || $type === $name;

        $extinguishers = fn (string $column) => $this->limit(
            $this->placeFilters($this->extinguisherQuery($actor)->evaluated()->whereNotNull($column), $actor, $filters),
            'hs_fire_extinguishers.'.$column,
            $filters
        );

        if ($wanted(self::TYPE_EXTINGUISHER_EXPIRY)) {
            $sources[self::TYPE_EXTINGUISHER_EXPIRY] = $extinguishers('expiry_date');
        }

        if ($wanted(self::TYPE_EXTINGUISHER_SERVICE)) {
            $sources[self::TYPE_EXTINGUISHER_SERVICE] = $extinguishers('next_service_due');
        }

        if ($wanted(self::TYPE_EXTINGUISHER_HYDRO)) {
            $sources[self::TYPE_EXTINGUISHER_HYDRO] = $extinguishers('next_hydro_test_due');
        }

        if ($wanted(self::TYPE_KIT_ITEM)) {
            $kits = $this->placeFilters($this->kitQuery($actor)->evaluated(), $actor, $filters)->select('hs_first_aid_kits.id');

            $sources[self::TYPE_KIT_ITEM] = $this->limit(
                HsFirstAidKitItem::query()->where('has_expiry', true)->whereNotNull('expiry_date')->whereIn('kit_id', $kits),
                'hs_first_aid_kit_items.expiry_date',
                $filters
            );
        }

        if ($wanted(self::TYPE_PPE)) {
            $issues = ($actor === null ? HsPpeIssue::query() : $this->scope->ppeIssues($actor))->open()->whereNotNull('replace_due_on');

            if (filled($filters['site_id'] ?? null)) {
                $issues->whereRaw('1 = 0');
            }

            if (filled($filters['district_id'] ?? null) || (filled($filters['region_id'] ?? null) && $this->seesAll($actor))) {
                $issues->whereIn('employee_id', Employee::query()
                    ->when(filled($filters['district_id'] ?? null), fn (Builder $q) => $q->where('district_id', (int) $filters['district_id']))
                    ->when(filled($filters['region_id'] ?? null) && $this->seesAll($actor), fn (Builder $q) => $q->where('region_id', (int) $filters['region_id']))
                    ->select('employees.id'));
            }

            $sources[self::TYPE_PPE] = $this->limit($issues, 'hs_ppe_issues.replace_due_on', $filters);
        }

        return $sources;
    }

    /**
     * Every matching row, most urgent first (days to due, then type, then what it is).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?User $actor, array $filters = []): Collection
    {
        $rows = collect();

        foreach ($this->sources($actor, $filters) as $type => $query) {
            $rows = $rows->concat($this->map($type, $query));
        }

        $order = array_flip(array_keys(self::TYPES));

        return $rows
            ->sort(fn (array $a, array $b) => [$a['days'], $order[$a['type']], $a['what']] <=> [$b['days'], $order[$b['type']], $b['what']])
            ->values();
    }

    /** @param  array<string, mixed>  $filters */
    public function paginate(User $actor, array $filters, int $perPage, int $page, string $path): LengthAwarePaginator
    {
        $rows = $this->rows($actor, $filters);

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => $path]);
    }

    /**
     * Counts from the very query objects the rows come from. by_bucket ignores the bucket filter and by_type ignores the
     * type filter (so each chip shows what choosing it would list); total honours every filter.
     *
     * @param  array<string, mixed>  $filters
     * @return array{total: int, by_bucket: array<string, int>, by_type: array<string, int>}
     */
    public function counts(?User $actor, array $filters = []): array
    {
        $total = 0;

        foreach ($this->sources($actor, $filters) as $query) {
            $total += (clone $query)->count();
        }

        $byBucket = array_fill_keys(array_keys(self::BUCKETS), 0);

        foreach (array_keys(self::BUCKETS) as $bucket) {
            foreach ($this->sources($actor, ['bucket' => $bucket] + array_diff_key($filters, ['bucket' => 1])) as $query) {
                $byBucket[$bucket] += (clone $query)->count();
            }
        }

        $byType = array_fill_keys(array_keys(self::TYPES), 0);

        foreach ($this->sources($actor, array_diff_key($filters, ['type' => 1])) as $type => $query) {
            $byType[$type] = (clone $query)->count();
        }

        return ['total' => $total, 'by_bucket' => $byBucket, 'by_type' => $byType];
    }

    // ------------------------------------------------------------------ internals

    /** A null actor is the scheduled alert command: no signed-in user, so the whole register. */
    protected function extinguisherQuery(?User $actor): Builder
    {
        return $actor === null ? HsFireExtinguisher::query() : $this->scope->extinguishers($actor);
    }

    protected function kitQuery(?User $actor): Builder
    {
        return $actor === null ? HsFirstAidKit::query() : $this->scope->kits($actor);
    }

    protected function seesAll(?User $actor): bool
    {
        return $actor === null || $this->scope->seesAllRegions($actor);
    }

    /**
     * Narrow an equipment query by place. The region filter only counts for users who see every region: everyone else is
     * already confined to theirs by the scope.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function placeFilters(Builder $query, ?User $actor, array $filters): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->when(filled($filters['district_id'] ?? null), fn (Builder $q) => $q->where($table.'.district_id', (int) $filters['district_id']))
            ->when(filled($filters['site_id'] ?? null), fn (Builder $q) => $q->where($table.'.site_id', (int) $filters['site_id']))
            ->when(filled($filters['region_id'] ?? null) && $this->seesAll($actor), fn (Builder $q) => $q->where($table.'.region_id', (int) $filters['region_id']));
    }

    /**
     * The horizon and bucket conditions on the date column. The bucket's own range wins over the horizon where it is
     * narrower.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function limit(Builder $query, string $column, array $filters): Builder
    {
        $today = today();
        $critical = (int) HealthSafetySettings::value('hs_expiry_critical_days');
        $warning = (int) HealthSafetySettings::value('hs_expiry_warning_days');
        $horizon = $filters['horizon'] ?? null;

        if ($horizon === 'overdue') {
            $query->whereDate($column, '<', $today->toDateString());
        } else {
            $days = ctype_digit((string) $horizon) && (int) $horizon > 0 ? (int) $horizon : self::DEFAULT_HORIZON;
            $query->whereDate($column, '<=', $today->copy()->addDays($days)->toDateString());
        }

        match ($filters['bucket'] ?? null) {
            self::BUCKET_OVERDUE => $query->whereDate($column, '<', $today->toDateString()),
            self::BUCKET_CRITICAL => $query->whereDate($column, '>=', $today->toDateString())->whereDate($column, '<=', $today->copy()->addDays($critical)->toDateString()),
            self::BUCKET_DUE_SOON => $query->whereDate($column, '>', $today->copy()->addDays($critical)->toDateString())->whereDate($column, '<=', $today->copy()->addDays($warning)->toDateString()),
            self::BUCKET_LATER => $query->whereDate($column, '>', $today->copy()->addDays($warning)->toDateString()),
            default => null,
        };

        return $query;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function map(string $type, Builder $query): Collection
    {
        return match ($type) {
            self::TYPE_KIT_ITEM => (clone $query)->with(['kit.site', 'kit.vehicle', 'kit.responsible'])->get()->map(fn (HsFirstAidKitItem $item) => $this->row(
                $type,
                $item->id,
                'Kit '.$item->kit->asset_code.': '.$item->item_name,
                $item->kit->locationLabel(),
                $item->expiry_date,
                $item->kit->responsible?->full_name,
                route('health_safety.kits.show', $item->kit),
                $item->kit->region_id,
                $item->kit->district_id,
                $item->kit->responsible_employee_id,
                'kit',
                $item->kit->id,
                $item->kit->vehicle_id ? 'Vehicle '.($item->kit->vehicle?->number_plate ?? '') : (string) ($item->kit->site?->name ?? 'Unknown site'),
            )),
            self::TYPE_PPE => (clone $query)->with(['employee:id,staff_id,full_name,region_id,district_id', 'type:id,name'])->get()->map(fn (HsPpeIssue $issue) => $this->row(
                $type,
                $issue->id,
                'PPE: '.$issue->type->name.($issue->size ? ' ('.$issue->size.')' : '').($issue->quantity > 1 ? ' x'.$issue->quantity : ''),
                $issue->employee->full_name.' ('.$issue->employee->staff_id.')',
                $issue->replace_due_on,
                null,
                route('health_safety.ppe.issues', ['search' => $issue->employee->staff_id]),
                $issue->employee->region_id,
                $issue->employee->district_id,
                null,
                'ppe',
                $issue->id,
                'Staff PPE',
            )),
            default => (clone $query)->with(['site', 'vehicle', 'responsible'])->get()->map(function (HsFireExtinguisher $unit) use ($type) {
                $date = match ($type) {
                    self::TYPE_EXTINGUISHER_EXPIRY => $unit->expiry_date,
                    self::TYPE_EXTINGUISHER_SERVICE => $unit->next_service_due,
                    default => $unit->next_hydro_test_due,
                };

                return $this->row(
                    $type,
                    $unit->id,
                    'Extinguisher '.$unit->asset_code,
                    $unit->locationLabel(),
                    $date,
                    $unit->responsible?->full_name,
                    route('health_safety.extinguishers.show', $unit),
                    $unit->region_id,
                    $unit->district_id,
                    $unit->responsible_employee_id,
                    'extinguisher',
                    $unit->id,
                    $unit->vehicle_id ? 'Vehicle '.($unit->vehicle?->number_plate ?? '') : (string) ($unit->site?->name ?? 'Unknown site'),
                );
            }),
        };
    }

    /** @return array<string, mixed> */
    protected function row(string $type, int $id, string $what, string $where, Carbon $due, ?string $responsible, string $url, ?int $regionId, ?int $districtId, ?int $responsibleEmployeeId, string $kind, int $itemPageId, string $site = ''): array
    {
        $due = $due->copy()->startOfDay();
        $days = (int) today()->diffInDays($due, false);

        return [
            'key' => $type.'|'.$id,
            'type' => $type,
            'type_label' => self::TYPES[$type],
            'item_id' => $id,
            'kind' => $kind,
            'what' => $what,
            'where' => $where,
            'due_on' => $due,
            'days' => $days,
            'bucket' => self::bucketFor($days),
            'responsible' => $responsible,
            'responsible_employee_id' => $responsibleEmployeeId,
            'url' => $url,
            'region_id' => $regionId,
            'district_id' => $districtId,
            'page_id' => $itemPageId,
            'site' => $site,
        ];
    }
}
