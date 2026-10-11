<?php

namespace App\Services\GWater;

use App\Models\District;
use App\Models\User;
use App\Services\Transport\TransportScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The one place that decides which G-Water books a user may read, write to and record into. Every screen, the services and
 * the tests call it; never filter by region in Blade or in a screen.
 *
 * Every location keeps its own books (docs/17-module-g-water.md section 2.4). A location is Head Office OR a region WITHOUT
 * Head Office: Head Office is a district that shares a region id with its staff, so "region alone" is never the test. The
 * Head Office-or-region rule itself is TransportScope's (one rule for the whole ERP); this class only applies it to the G-Water
 * tables, whose rows carry region_id and district_id.
 *  - super_admin reads every location and records into one it chooses;
 *  - everyone else reads and records into their own location only, taken from their employee record;
 *  - another location's row answers 404 (not 403), the same as one that does not exist;
 *  - a login with no employee record or no region reaches nothing.
 */
class GwaterVisibility
{
    public function __construct(protected TransportScope $scope) {}

    public function can(User $user, string $permission): bool
    {
        return $this->scope->can($user, $permission);
    }

    public function seesAll(User $user): bool
    {
        return $this->scope->seesAll($user);
    }

    /** Whether the user has a location at all (super_admin always does: they pick). */
    public function hasLocation(User $user): bool
    {
        return $this->seesAll($user) || $this->scope->locationOf($user) !== null;
    }

    // ------------------------------------------------------------------ reading

    /** Restricts a query on any gw_ table with region_id + district_id to the user's reach. */
    public function scope(Builder $query, User $user): Builder
    {
        return $this->scope->apply($query, $user);
    }

    /** Restricts a query to the location (Head Office or region) of the given place. */
    public function atLocation(Builder $query, array $location): Builder
    {
        return $this->scope->applyLocation($query, $location['region_id'] ?? null, $location['district_id'] ?? null);
    }

    /** A row of a G-Water table, only if the user reaches it. */
    public function contains(User $user, Model $row): bool
    {
        return $this->scope(($row::class)::query(), $user)->whereKey($row->getKey())->exists();
    }

    /** 404 unless the user reaches the row. */
    public function authorizeRow(User $user, Model $row): Model
    {
        abort_unless($this->contains($user, $row), 404);

        return $row;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    public function find(User $user, string $model, int|string $id): Model
    {
        return $this->scope($model::query(), $user)->whereKey($id)->firstOrFail();
    }

    // ------------------------------------------------------------------ writing

    /**
     * Where a new entry goes. A member of staff records into their own location (the region and district of their employee
     * record); super_admin has none and must say which district's books ($districtId).
     *
     * @return array{region_id: int, district_id: int|null}
     */
    public function recordingLocation(User $actor, ?int $districtId = null): array
    {
        if ($this->seesAll($actor)) {
            $district = $districtId ? District::query()->find($districtId) : null;

            if (! $district) {
                throw ValidationException::withMessages(['location' => 'Choose the location whose books you are working in.']);
            }

            return ['region_id' => (int) $district->region_id, 'district_id' => (int) $district->id];
        }

        $own = $this->scope->locationOf($actor);

        if (! $own) {
            // Not a 403: nothing in the books is theirs to see, so nothing is theirs to write.
            abort(404);
        }

        return $own;
    }

    /** Whether two places belong to the same books (same Head Office / same region without it). */
    public function sameLocation(array $a, array $b): bool
    {
        $ho = fn (array $place) => $this->scope->isHeadOfficeDistrict($place['district_id'] ?? null);

        if ($ho($a) || $ho($b)) {
            return $ho($a) && $ho($b);
        }

        return ($a['region_id'] ?? null) !== null && (int) $a['region_id'] === (int) ($b['region_id'] ?? 0);
    }

    /** Staff based at a location, for the "paid by" and driver pickers (the same people TransportScope lets onto a vehicle there). */
    public function peopleAt(array $location): Builder
    {
        return $this->scope->peopleAt($location['region_id'] ?? null, $location['district_id'] ?? null)->active();
    }

    public function isHeadOffice(array $location): bool
    {
        return $this->scope->isHeadOfficeDistrict($location['district_id'] ?? null);
    }

    /** The user's own place (employee region and district), or null. */
    public function ownLocation(User $user): ?array
    {
        return $this->scope->locationOf($user);
    }

    /** A short stable key of the books a user reads, for cache keys. */
    public function keyFor(User $user): string
    {
        return $this->scope->keyFor($user);
    }
}
