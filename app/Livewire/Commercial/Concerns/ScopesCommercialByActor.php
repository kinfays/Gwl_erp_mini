<?php

namespace App\Livewire\Commercial\Concerns;

use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingStat;
use App\Models\CommercialReadingStrength;
use App\Models\Employee;
use App\Models\User;
use App\Services\Commercial\CommercialPeriods;
use App\Services\Commercial\CommercialScope;
use App\Services\Commercial\Customers\CustomerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Who sees which Commercial batches. A separate trait from Assets\Concerns\ScopesAssetsByActor on purpose: the rules
 * differ (Assets scopes ICT staff; this scopes everyone) and neither should change when the other does.
 *
 * The rule is CommercialScope's (Phase 8a); this trait only wraps it for a signed-in person. super_admin, Global Admin and Head
 * Office staff see every region and district; a holder of commercial.view_all_districts every district of their own region; anyone
 * else only the district on their employee record; no employee record or region, nothing. Head Office is a district whose staff
 * share the Head Office district's region_id, so "Head Office" is recognised by location_type, never by region alone.
 */
trait ScopesCommercialByActor
{
    protected function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function actorEmployee(): ?Employee
    {
        $user = $this->actor();

        return $user->employee ?? $user->employeeByStaffId;
    }

    protected function actorRegionId(): ?int
    {
        return $this->actorEmployee()?->region_id;
    }

    /** The actor's CommercialScope (asked afresh each call: a role change is seen at once). */
    protected function commercialScope(): CommercialScope
    {
        return CommercialScope::for(Auth::user());
    }

    protected function actorSeesAllRegions(): bool
    {
        return $this->commercialScope()->seesAllRegions();
    }

    /** True for a district user: no region-wide row, no other district's figure. */
    protected function actorDistrictLimited(): bool
    {
        return $this->commercialScope()->isDistrictLimited();
    }

    /** null = no district limit; otherwise the only district ids the actor may see (empty = none). @return list<int>|null */
    protected function actorDistrictIds(): ?array
    {
        return $this->commercialScope()->districtIds();
    }

    protected function scopeBatchesForActor(Builder $query): Builder
    {
        $regions = $this->commercialScope()->regions;

        if ($regions === null) {
            return $query;
        }

        return $regions === [] ? $query->whereRaw('1 = 0') : $query->whereIn($query->qualifyColumn('region_id'), $regions);
    }

    /**
     * Reading rows (and strengths) the actor may see: those whose batch is in a region they may see. The effective /
     * readers-only scopes are the caller's to add.
     */
    protected function scopeReadingStatsForActor(Builder $query): Builder
    {
        $query->whereHas('batch', fn (Builder $batch) => $this->scopeBatchesForActor($batch));

        // a district user sees only the CCAs whose home district is theirs (a row with no district is not theirs)
        return $this->commercialScope()->limitDistricts($query, $query->qualifyColumn('district_id'));
    }

    /**
     * Billing route rows the actor may see: a district user only their district's routes (a route with no district is not theirs).
     * The bands, the strengths and the upload freshness are region-wide figures: see actorDistrictLimited().
     */
    protected function scopeBillingRoutesForActor(Builder $query): Builder
    {
        return $this->commercialScope()->limitDistricts($query, $query->qualifyColumn('district_id'));
    }

    /** The verified strength is one figure for the whole region: a district user has no region-wide row to see. */
    protected function scopeStrengthsForActor(Builder $query): Builder
    {
        $query->whereHas('batch', fn (Builder $batch) => $this->scopeBatchesForActor($batch));

        return $this->actorDistrictLimited() ? $query->whereRaw('1 = 0') : $query;
    }

    /**
     * The effective, readers-only reading rows the actor may see, narrowed by the screen's filters. The Reading screen,
     * the Summary and every export build their rows here, so they cannot disagree.
     *
     * @param  string|null  $from  Y-m (first month shown)
     * @param  string|null  $to  Y-m (last month shown)
     */
    protected function filteredReadingStats(?int $regionId = null, ?int $districtId = null, ?string $from = null, ?string $to = null): Builder
    {
        return $this->scopeReadingStatsForActor(CommercialReadingStat::query()->effective()->readers())
            ->when($regionId !== null && $this->actorSeesAllRegions(), fn (Builder $query) => $query->whereHas('batch', fn (Builder $batch) => $batch->where('region_id', $regionId)))
            ->when($districtId, fn (Builder $query, int $district) => $query->where('district_id', $district))
            ->when(is_array(self::monthRange($from, $to)), fn (Builder $query) => $query->whereDate('month', '>=', self::monthRange($from, $to)[0]->toDateString())->whereDate('month', '<=', self::monthRange($from, $to)[1]->toDateString()))
            ->when(self::monthRange($from, $to) === false, fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    protected function filteredStrengths(?int $regionId = null, ?string $from = null, ?string $to = null): Builder
    {
        return $this->scopeStrengthsForActor(CommercialReadingStrength::query()->effective())
            ->when($regionId !== null && $this->actorSeesAllRegions(), fn (Builder $query) => $query->whereHas('batch', fn (Builder $batch) => $batch->where('region_id', $regionId)))
            ->when(is_array(self::monthRange($from, $to)), fn (Builder $query) => $query->whereDate('month', '>=', self::monthRange($from, $to)[0]->toDateString())->whereDate('month', '<=', self::monthRange($from, $to)[1]->toDateString()))
            ->when(self::monthRange($from, $to) === false, fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    /**
     * The month rows a typed range counts (Phase 8a, CommercialPeriods rule 5): a month counts only when the WHOLE month lies inside the
     * range, so a typed month ("2026-07") is that month exactly (as it always was) and a typed date range counts the whole months inside
     * it. Returns [first month start, last month start] (Carbon), null for no range at all, and false when the range holds no whole month.
     *
     * @return array{0: Carbon, 1: Carbon}|false|null
     */
    public static function monthRange(?string $from, ?string $to): array|false|null
    {
        $start = CommercialPeriods::bound($from, false);
        $end = CommercialPeriods::bound($to, true);

        if ($start === null && $end === null) {
            return null;
        }

        $first = $start === null ? Carbon::create(1970, 1, 1) : ($start->day === 1 ? $start->copy() : $start->copy()->addMonthNoOverflow()->startOfMonth());
        $last = $end === null ? Carbon::create(2999, 12, 1) : ($end->equalTo($end->copy()->endOfMonth()->startOfDay()) ? $end->copy()->startOfMonth() : $end->copy()->subMonthNoOverflow()->startOfMonth());

        return $first->greaterThan($last) ? false : [$first, $last];
    }

    /** "2026-09" (or a full date) as the first / last moment of that month; null for anything else. */
    public static function monthBound(string $value, bool $end): ?Carbon
    {
        if (! preg_match('/^(\d{4})-(\d{2})/', $value, $matches) || (int) $matches[2] < 1 || (int) $matches[2] > 12) {
            return null;
        }

        $month = Carbon::create((int) $matches[1], (int) $matches[2], 1)->startOfDay();

        return $end ? $month->endOfMonth() : $month;
    }

    /**
     * The billing snapshot asked for, if it is one the actor may see; otherwise the default. A snapshot id from another
     * region falls back silently, so its existence is never revealed.
     *
     * @param  list<array<string, mixed>>  $snapshots  the actor's own snapshots
     * @param  array<string, mixed>|null  $default
     * @return array<string, mixed>|null
     */
    protected function chooseSnapshot(array $snapshots, ?string $requestedId, ?array $default): ?array
    {
        foreach ($snapshots as $snapshot) {
            if ((string) $snapshot['id'] === (string) $requestedId) {
                return $snapshot;
            }
        }

        return $default;
    }

    /**
     * Upload history (the uploads list and a batch page). A reading / billing batch is a region-wide file, so a district user sees
     * only the batches they uploaded themselves (their file holds only their district's rows); everyone else sees their region's.
     */
    protected function scopeUploadsForActor(Builder $query): Builder
    {
        $this->scopeBatchesForActor($query);

        return $this->actorDistrictLimited() ? $query->where($query->qualifyColumn('imported_by'), Auth::id() ?? 0) : $query;
    }

    protected function actorCanAccessBatch(CommercialImportBatch $batch): bool
    {
        if (! $this->commercialScope()->seesRegion($batch->region_id !== null ? (int) $batch->region_id : null)) {
            return false;
        }

        return ! $this->actorDistrictLimited() || ((int) $batch->imported_by === (int) Auth::id());
    }

    /** District rows the actor may list (a picker, a filter): a district user's own district(s) only. */
    protected function scopeDistrictsForActor(Builder $query): Builder
    {
        return $this->commercialScope()->limitDistricts($query, $query->qualifyColumn('id'));
    }

    protected function abortUnlessBatchAccessible(CommercialImportBatch $batch): void
    {
        abort_unless($this->actorCanAccessBatch($batch), 403, 'This batch belongs to another region.');
    }

    /** Regions the actor may file or resolve reports for: null means every region. */
    protected function uploadRegionRestriction(): ?int
    {
        return $this->commercialScope()->regionRestriction();
    }

    /** Passes for super_admin or a user holding ANY of the permissions. */
    protected function guardCommercialPermission(string ...$slugs): void
    {
        $user = Auth::user();

        if (! $user) {
            abort(403);
        }

        if ($user->hasRoles('super_admin')) {
            return;
        }

        foreach ($slugs as $slug) {
            if ($user->hasPermission($slug)) {
                return;
            }
        }

        abort(403, 'You do not have permission to do that.');
    }

    /**
     * Whether reading figures grouped by home district must be held back from small groups. Only people who may see
     * individual readers (commercial.view_reader_performance) see a district of one reader's figures.
     */
    protected function hideSmallGroups(): bool
    {
        return ! $this->actorCan('commercial.view_reader_performance');
    }

    /**
     * The small-group rule for a district user: their district IS the group, so when it has fewer than the minimum of distinct active
     * CCAs, a person who may not see individual CCAs must not be shown figures that would be those people's own. (Someone with the
     * all-districts permission asks the same question per district, where the district filter and the scorecard already do.)
     *
     * @param  \Illuminate\Support\Collection<int, CommercialReadingStat>  $stats  the actor's already-scoped rows
     */
    protected function districtGroupTooSmall($stats): bool
    {
        if (! $this->actorDistrictLimited() || ! $this->hideSmallGroups()) {
            return false;
        }

        return $stats->where('visited_count', '>', 0)->pluck('reader_staff_id')->unique()->count() < max(1, (int) config('gwl.commercial_min_readers_for_district_figures', 3));
    }

    /**
     * The customer list's region restriction for the actor: null = every region, 0 = none, otherwise the one region id. The
     * service-level twin is CustomerScope (a test pins the two together); every customer query, list and export takes this.
     */
    protected function customerRestriction(): ?int
    {
        return CustomerScope::regionRestriction($this->actor());
    }

    /** The customer list's district limit for the actor: null = none, otherwise the only districts they may see (empty = none). @return list<int>|null */
    protected function customerDistrictIds(): ?array
    {
        return CustomerScope::districtIds($this->actor());
    }

    /** Whether the actor may see names, addresses and (masked) contact details. */
    protected function customerDetails(): bool
    {
        return $this->actorCan('commercial.view_customer_details');
    }

    /** The customer list is switched on (a 404 otherwise: with the flag off the routes do not even exist). */
    protected function guardCustomerList(): void
    {
        abort_unless(config('gwl.commercial_customer_list_enabled'), 404);
    }

    /** Anyone who works with uploaded batches: uploads, resolves matches or voids. */
    protected function guardBatchWork(): void
    {
        $this->guardCommercialPermission('commercial.upload_reports', 'commercial.resolve_matches', 'commercial.void_batches');
    }

    protected function actorCan(string $slug): bool
    {
        $user = Auth::user();

        return (bool) $user && ($user->hasRoles('super_admin') || $user->hasPermission($slug));
    }
}
