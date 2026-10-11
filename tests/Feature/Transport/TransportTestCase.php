<?php

namespace Tests\Feature\Transport;

use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\MaintenanceRecord;
use App\Models\MileageLog;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TransportRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fixtures for the region-scoped Transport tests: two regions, Head Office as a district that shares Accra West's
 * region_id (the trap TransportScope must not fall into), and helpers for people and vehicles in a place.
 */
abstract class TransportTestCase extends TestCase
{
    use RefreshDatabase;

    protected Region $accraWest;

    protected Region $ashanti;

    protected District $sowutuom;

    protected District $odorkor;

    protected District $headOffice;

    protected District $kumasi;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating an Employee fires EmployeeObserver's user sync + invite mail.
        Notification::fake();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            TransportRolePermissionSeeder::class,
        ]);

        $this->accraWest = Region::query()->create(['region_name' => 'Accra West']);
        $this->ashanti = Region::query()->create(['region_name' => 'Ashanti']);
        $this->sowutuom = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Sowutuom']);
        $this->odorkor = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Odorkor']);
        $this->headOffice = District::query()->create(['region_id' => $this->accraWest->id, 'district_name' => 'Head Office']);
        $this->kumasi = District::query()->create(['region_id' => $this->ashanti->id, 'district_name' => 'Kumasi']);
    }

    protected function employee(string $staffId, District $district): Employee
    {
        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'Staff '.$staffId,
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => JobTitle::query()->firstOrCreate(['job_title_name' => 'General Staff'])->id,
            'department_id' => Department::query()->firstOrCreate(['department_name' => 'Distribution'])->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2020-01-01',
            'present_appointment' => '2020-01-01',
            'is_active' => true,
        ]);
    }

    /** A user with an employee record in the district, holding the roles (given by name). */
    protected function person(string $staffId, District $district, array $roles = ['employee'], array $overrides = []): User
    {
        // Asking again for the same staff id returns the same person.
        if (! User::query()->where('staff_id', $staffId)->exists()) {
            $this->employee($staffId, $district);
        }

        $user = User::query()->where('staff_id', $staffId)->firstOrFail();
        $user->forceFill(['must_change_password' => false, ...$overrides])->save();

        foreach ($roles as $role) {
            $user->roles()->syncWithoutDetaching(Role::query()->where('name', $role)->firstOrFail());
        }

        return $user->fresh();
    }

    protected function manager(string $staffId, District $district): User
    {
        return $this->person($staffId, $district, ['employee', 'transport_manager']);
    }

    protected function driver(string $staffId, District $district, array $overrides = []): User
    {
        return $this->person($staffId, $district, ['employee', 'driver'], $overrides);
    }

    /** A login with no employee record, so no place at all. */
    protected function homeless(string $staffId, array $roles): User
    {
        $user = User::query()->where('staff_id', $staffId)->first() ?? User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => 'password',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        foreach ($roles as $role) {
            $user->roles()->syncWithoutDetaching(Role::query()->where('name', $role)->firstOrFail());
        }

        return $user->fresh();
    }

    protected function superAdmin(string $staffId = 'SA0001'): User
    {
        return $this->homeless($staffId, ['super_admin']);
    }

    /**
     * The users table has no `name` column any more, so UserFactory cannot build the people the record factories ask
     * for by default: records are created with this one explicit person.
     *
     * @return array{recorded_by: int, reported_by: int, driver_id: int}
     */
    protected function recorderIds(): array
    {
        $id = (User::query()->where('staff_id', 'REC001')->first() ?? $this->homeless('REC001', ['employee']))->id;

        return ['recorded_by' => $id, 'reported_by' => $id, 'driver_id' => $id];
    }

    protected function expense(Vehicle $vehicle, array $attributes = []): VehicleExpense
    {
        return VehicleExpense::factory()->create(['vehicle_id' => $vehicle->id, 'recorded_by' => $this->recorderIds()['recorded_by'], ...$attributes]);
    }

    protected function issue(Vehicle $vehicle, array $attributes = []): VehicleIssue
    {
        return VehicleIssue::factory()->create(['vehicle_id' => $vehicle->id, 'reported_by' => $this->recorderIds()['reported_by'], ...$attributes]);
    }

    protected function mileageLog(Vehicle $vehicle, array $attributes = []): MileageLog
    {
        return MileageLog::factory()->create(['vehicle_id' => $vehicle->id, 'driver_id' => $this->recorderIds()['driver_id'], ...$attributes]);
    }

    protected function maintenanceRecord(Vehicle $vehicle, array $attributes = []): MaintenanceRecord
    {
        return MaintenanceRecord::factory()->create(['vehicle_id' => $vehicle->id, ...$attributes]);
    }

    protected function vehicleAt(District $district, string $plate, array $attributes = []): Vehicle
    {
        return Vehicle::factory()->placedIn($district)->create([
            'number_plate' => $plate,
            'status' => Vehicle::STATUS_ACTIVE,
            ...$attributes,
        ]);
    }

    protected function unplacedVehicle(string $plate, array $attributes = []): Vehicle
    {
        return Vehicle::factory()->create([
            'number_plate' => $plate,
            'status' => Vehicle::STATUS_ACTIVE,
            ...$attributes,
        ]);
    }

    /** @return list<int> */
    protected function idsOf($vehicles): array
    {
        return $vehicles->pluck('id')->sort()->values()->all();
    }
}
