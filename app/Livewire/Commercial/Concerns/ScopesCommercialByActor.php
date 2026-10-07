<?php

namespace App\Livewire\Commercial\Concerns;

use App\Models\CommercialImportBatch;
use App\Models\CommercialReadingStat;
use App\Models\CommercialReadingStrength;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Who sees which Commercial batches. A separate trait from Assets\Concerns\ScopesAssetsByActor on purpose: the rules
 * differ (Assets scopes ICT staff; this scopes everyone) and neither should change when the other does.
 *
 * super_admin, Global Admin and Head Office staff see every region. Everyone else sees their own region only, and a
 * user with no employee record or no region sees nothing. Head Office is a district whose staff share the Head Office
 * district's region_id, so "Head Office" is recognised by location_type, never by region alone.
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

    protected function actorSeesAllRegions(): bool
    {
        $user = $this->actor();

        return $user->hasRoles('super_admin', 'admin') || $this->actorEmployee()?->location_type === 'HeadOffice';
    }

    protected function scopeBatchesForActor(Builder $query): Builder
    {
        if ($this->actorSeesAllRegions()) {
            return $query;
        }

        $regionId = $this->actorRegionId();

        return $regionId
            ? $query->where($query->qualifyColumn('region_id'), $regionId)
            : $query->whereRaw('1 = 0');
    }

    /**
     * Reading rows (and strengths) the actor may see: those whose batch is in a region they may see. The effective /
     * readers-only scopes are the caller's to add.
     */
    protected function scopeReadingStatsForActor(Builder $query): Builder
    {
        return $query->whereHas('batch', fn (Builder $batch) => $this->scopeBatchesForActor($batch));
    }

    protected function scopeStrengthsForActor(Builder $query): Builder
    {
        return $query->whereHas('batch', fn (Builder $batch) => $this->scopeBatchesForActor($batch));
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
            ->when(self::monthBound((string) $from, false), fn (Builder $query, Carbon $date) => $query->whereDate('month', '>=', $date->toDateString()))
            ->when(self::monthBound((string) $to, true), fn (Builder $query, Carbon $date) => $query->whereDate('month', '<=', $date->toDateString()));
    }

    protected function filteredStrengths(?int $regionId = null, ?string $from = null, ?string $to = null): Builder
    {
        return $this->scopeStrengthsForActor(CommercialReadingStrength::query()->effective())
            ->when($regionId !== null && $this->actorSeesAllRegions(), fn (Builder $query) => $query->whereHas('batch', fn (Builder $batch) => $batch->where('region_id', $regionId)))
            ->when(self::monthBound((string) $from, false), fn (Builder $query, Carbon $date) => $query->whereDate('month', '>=', $date->toDateString()))
            ->when(self::monthBound((string) $to, true), fn (Builder $query, Carbon $date) => $query->whereDate('month', '<=', $date->toDateString()));
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

    protected function actorCanAccessBatch(CommercialImportBatch $batch): bool
    {
        if ($this->actorSeesAllRegions()) {
            return true;
        }

        $regionId = $this->actorRegionId();

        return $regionId !== null && $batch->region_id !== null && (int) $batch->region_id === (int) $regionId;
    }

    protected function abortUnlessBatchAccessible(CommercialImportBatch $batch): void
    {
        abort_unless($this->actorCanAccessBatch($batch), 403, 'This batch belongs to another region.');
    }

    /** Regions the actor may file or resolve reports for: null means every region. */
    protected function uploadRegionRestriction(): ?int
    {
        return $this->actorSeesAllRegions() ? null : ($this->actorRegionId() ?? 0);
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
