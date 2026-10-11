<?php

namespace App\Services\Transport;

use App\Models\District;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The one place that decides which vehicles (and so which expenses, mileage logs, issues, maintenance records and
 * assignment history) a user may reach, which people may be put on a vehicle, and which managers hear about it. Every
 * Transport read and write goes through it; never filter by region in Blade or in a screen.
 *
 * The rule is the Head Office-or-region rule used by ActorScope / ICT scoping, applied to where a vehicle is based
 * (`vehicles.region_id` + `vehicles.district_id`):
 *  - super_admin sees every vehicle, including the ones that were never placed (NULL region);
 *  - Head Office staff see ONLY vehicles based in the Head Office district;
 *  - every other member of staff sees the vehicles of THEIR region, EXCLUDING the Head Office district;
 *  - a user with no employee record or no region sees nothing; an unplaced vehicle is visible to super_admin only.
 * Head Office is a district whose staff and vehicles carry the Head Office district's region_id, so it is recognised by
 * the district (vehicles) or location_type (people), never by region alone. A role such as transport_manager gives
 * permissions, not reach: managers are bound by location like everyone else.
 *
 * Anything outside the scope answers 404 (not 403), so the existence of another region's vehicle is not revealed.
 */
class TransportScope
{
    public const ALL = 'all';
    public const HEAD_OFFICE = 'head_office';
    public const REGION = 'region';
    public const NONE = 'none';

    /**
     * @return array{level: string, region_id: int|null}
     */
    public function resolve(User $user): array
    {
        if ($user->hasRoles('super_admin')) {
            return ['level' => self::ALL, 'region_id' => null];
        }

        $employee = $this->employeeOf($user);

        if ($employee?->location_type === 'HeadOffice') {
            return ['level' => self::HEAD_OFFICE, 'region_id' => null];
        }

        return $employee?->region_id
            ? ['level' => self::REGION, 'region_id' => (int) $employee->region_id]
            : ['level' => self::NONE, 'region_id' => null];
    }

    public function seesAll(User $user): bool
    {
        return $user->hasRoles('super_admin');
    }

    /** Roles never widen what a user can reach, but super_admin holds every permission. */
    public function can(User $user, string $permission): bool
    {
        return $user->hasRoles('super_admin') || $user->hasPermission($permission);
    }

    public function employeeOf(User $user): ?Employee
    {
        return $user->employee ?? $user->employeeByStaffId;
    }

    /** Short, stable label of the user's reach, for cache keys (two users with the same reach share cached figures). */
    public function keyFor(User $user): string
    {
        $scope = $this->resolve($user);

        return $scope['level'].($scope['region_id'] !== null ? ':'.$scope['region_id'] : '');
    }

    // ------------------------------------------------------------------ vehicles

    /** Vehicles the user may reach (soft-deleted ones excluded, as everywhere). */
    public function vehicles(User $user): Builder
    {
        return $this->apply(Vehicle::query(), $user);
    }

    /**
     * Restricts a query on `vehicles` (qualified, so it is safe next to joins). Works for any table with `region_id` and
     * `district_id` columns: G-Water's books use it through GwaterVisibility, so the Head Office-or-region rule exists once.
     */
    public function apply(Builder $query, User $user): Builder
    {
        $scope = $this->resolve($user);

        return match ($scope['level']) {
            self::ALL => $query,
            self::HEAD_OFFICE => $this->applyUnit($query, true, null),
            self::REGION => $this->applyUnit($query, false, $scope['region_id']),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Restricts a query to the "unit" a place belongs to: Head Office, or its region without Head Office. */
    public function applyLocation(Builder $query, ?int $regionId, ?int $districtId): Builder
    {
        $headOffice = $this->isHeadOfficeDistrict($districtId);

        if (! $headOffice && $regionId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $this->applyUnit($query, $headOffice, $regionId);
    }

    /** One unit: Head Office (its district) or a region EXCLUDING the Head Office district. */
    public function applyUnit(Builder $query, bool $headOffice, ?int $regionId): Builder
    {
        $table = $query->getModel()->getTable();

        if ($headOffice) {
            return $query->whereIn("{$table}.district_id", $this->headOfficeDistricts());
        }

        return $query
            ->where("{$table}.region_id", $regionId)
            ->where(function (Builder $inner) use ($table): void {
                $inner->whereNull("{$table}.district_id")
                    ->orWhereNotIn("{$table}.district_id", $this->headOfficeDistricts());
            });
    }

    /**
     * Restricts a query on a table that hangs off a vehicle (expenses, mileage logs, issues, maintenance records,
     * assignment history) to the vehicles the user may reach. Soft-deleted vehicles keep their history visible.
     */
    public function records(Builder $query, User $user, string $column = 'vehicle_id'): Builder
    {
        if ($this->seesAll($user)) {
            return $query;
        }

        return $query->whereIn(
            $query->qualifyColumn($column),
            $this->apply(Vehicle::withTrashed()->select('vehicles.id'), $user)
        );
    }

    public function contains(User $user, Vehicle $vehicle): bool
    {
        return $this->vehicles($user)->whereKey($vehicle->getKey())->exists();
    }

    /** @throws ModelNotFoundException answered as 404 */
    public function findVehicle(User $user, int|string $id): Vehicle
    {
        return $this->vehicles($user)->whereKey($id)->firstOrFail();
    }

    /** 404 unless the vehicle is within reach. Returns the vehicle for chaining. */
    public function authorizeVehicle(User $user, Vehicle $vehicle): Vehicle
    {
        abort_unless($this->contains($user, $vehicle), 404);

        return $vehicle;
    }

    // ------------------------------------------------------------------ location

    /**
     * The user's own place, used to prefill the vehicle form: their employee region and district. Null when they have
     * none (they can then neither see nor create vehicles).
     *
     * @return array{region_id: int, district_id: int|null}|null
     */
    public function locationOf(User $user): ?array
    {
        $employee = $this->employeeOf($user);

        if (! $employee?->region_id) {
            return null;
        }

        return [
            'region_id' => (int) $employee->region_id,
            'district_id' => $employee->district_id ? (int) $employee->district_id : null,
        ];
    }

    public function isHeadOfficeDistrict(?int $districtId): bool
    {
        return $districtId !== null
            && $this->headOfficeDistricts()->whereKey($districtId)->exists();
    }

    /** Whether a person (employee-linked user) belongs to the place a vehicle is based in. */
    public function personFits(User $person, ?int $regionId, ?int $districtId): bool
    {
        return $this->wherePersonAt(User::query()->whereKey($person->getKey()), $regionId, $districtId)->exists();
    }

    /** Users based in the same place as a vehicle at this region / district (Head Office, or the region without it). */
    public function peopleAt(?int $regionId, ?int $districtId): Builder
    {
        return $this->wherePersonAt(User::query(), $regionId, $districtId);
    }

    /** @param Builder<User> $users */
    public function wherePersonAt(Builder $users, ?int $regionId, ?int $districtId): Builder
    {
        $headOffice = $this->isHeadOfficeDistrict($districtId);

        if (! $headOffice && $regionId === null) {
            return $users->whereRaw('1 = 0');
        }

        $match = function (Builder $employee) use ($headOffice, $regionId): void {
            if ($headOffice) {
                $employee->where('location_type', 'HeadOffice');

                return;
            }

            $employee->where('region_id', $regionId)
                ->where(function (Builder $inner): void {
                    $inner->whereNull('location_type')->orWhere('location_type', '!=', 'HeadOffice');
                });
        };

        // Same resolution as User::employee ?? User::employeeByStaffId.
        return $users->where(function (Builder $outer) use ($match): void {
            $outer->whereHas('employee', $match)
                ->orWhere(function (Builder $byStaffId) use ($match): void {
                    $byStaffId->whereNull('employee_id')->whereHas('employeeByStaffId', $match);
                });
        });
    }

    /**
     * Who is told about a vehicle: every super_admin (they reach every vehicle) and the transport managers based where
     * the vehicle is. An unplaced vehicle therefore notifies super_admin only.
     */
    public function managersFor(Vehicle $vehicle): Builder
    {
        return User::query()->where(function (Builder $query) use ($vehicle): void {
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'super_admin'))
                ->orWhere(function (Builder $managers) use ($vehicle): void {
                    $managers->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'transport_manager'));
                    $this->wherePersonAt($managers, $vehicle->region_id, $vehicle->district_id);
                });
        });
    }

    /** Subquery of the Head Office districts (Employee::locationTypeFor: the name says "head office"). */
    private function headOfficeDistricts(): Builder
    {
        return District::query()
            ->select('districts.id')
            ->whereRaw('LOWER(districts.district_name) LIKE ?', ['%head office%']);
    }
}
