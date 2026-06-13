<?php

namespace Tests\Feature\Transport;

use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleIssue;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Transport\TransportService;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TransportRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransportModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_transport_roles_receive_transport_module_access(): void
    {
        $this->seedCoreTransportAccess();

        $transportManager = Role::query()->where('name', 'transport_manager')->firstOrFail();
        $driver = Role::query()->where('name', 'driver')->firstOrFail();
        $hrRegion = Role::query()->where('name', 'hr_region')->firstOrFail();

        $this->assertTrue(ModuleAccess::query()
            ->where('role_id', $transportManager->id)
            ->where('module', Permission::MODULE_TRANSPORT)
            ->where('can_access', true)
            ->exists());

        $this->assertTrue(ModuleAccess::query()
            ->where('role_id', $driver->id)
            ->where('module', Permission::MODULE_TRANSPORT)
            ->where('can_access', true)
            ->exists());

        $this->assertFalse(ModuleAccess::query()
            ->where('role_id', $hrRegion->id)
            ->where('module', Permission::MODULE_TRANSPORT)
            ->where('can_access', true)
            ->exists());
    }

    public function test_vehicle_assignment_prevents_duplicate_active_employee_assignment(): void
    {
        $employee = $this->user('EMP001');
        $vehicle = Vehicle::factory()->create([
            'number_plate' => 'GV-1000-26',
            'assigned_user_id' => $employee->id,
            'is_pool_car' => false,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        $this->expectException(ValidationException::class);

        app(TransportService::class)->saveVehicle([
            'type' => Vehicle::TYPE_SEDAN,
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'color' => 'White',
            'number_plate' => 'GV-2000-26',
            'year_purchased' => 2024,
            'is_pool_car' => false,
            'assigned_user_id' => $employee->id,
            'department_id' => null,
            'driver_type' => Vehicle::DRIVER_SELF_DRIVE,
            'assigned_driver_id' => null,
            'current_mileage' => 1000,
            'maintenance_interval_km' => 5000,
            'insurance_expiry_date' => now()->addYear()->toDateString(),
            'road_worthiness_expiry_date' => now()->addYear()->toDateString(),
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
    }

    public function test_driver_api_can_log_mileage_and_update_vehicle_current_mileage(): void
    {
        $this->seedCoreTransportAccess();

        $driver = $this->user('DRV001', ['api_token' => 'driver-token']);
        $driver->roles()->attach(Role::query()->where('name', 'driver')->firstOrFail());

        $vehicle = Vehicle::factory()->create([
            'number_plate' => 'GV-3000-26',
            'current_mileage' => 12000,
            'assigned_driver_id' => $driver->id,
            'driver_type' => Vehicle::DRIVER_ASSIGNED,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        $response = $this
            ->withToken('driver-token')
            ->postJson(route('api.transport.mileage-logs.store'), [
                'mileage_before' => 12000,
                'mileage_after' => 12125,
                'trip_date' => now()->toDateString(),
                'trip_purpose' => 'Field visit',
            ]);

        $response->assertCreated();
        $this->assertSame(12125, $vehicle->fresh()->current_mileage);
    }

    public function test_expiry_command_notifies_transport_managers(): void
    {
        Notification::fake();
        $this->seedCoreTransportAccess();

        $manager = $this->user('TRM001');
        $manager->roles()->attach(Role::query()->where('name', 'transport_manager')->firstOrFail());

        Vehicle::factory()->create([
            'number_plate' => 'GV-4000-26',
            'insurance_expiry_date' => today()->addDays(10)->toDateString(),
            'road_worthiness_expiry_date' => today()->addDays(100)->toDateString(),
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        $this->artisan('transport:check-expiries')->assertSuccessful();

        Notification::assertSentTo($manager, GeneralDatabaseNotification::class);
    }

    public function test_reports_page_is_visible_to_transport_manager_only(): void
    {
        $this->seedCoreTransportAccess();

        $manager = $this->user('TRM002');
        $manager->roles()->attach(Role::query()->where('name', 'transport_manager')->firstOrFail());

        $driver = $this->user('DRV002');
        $driver->roles()->attach(Role::query()->where('name', 'driver')->firstOrFail());

        $this->actingAs($manager)
            ->get(route('transport.reports'))
            ->assertOk()
            ->assertSee('Transport Reports');

        $this->actingAs($driver)
            ->get(route('transport.reports'))
            ->assertForbidden();
    }

    protected function seedCoreTransportAccess(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            TransportRolePermissionSeeder::class,
        ]);
    }

    protected function user(string $staffId, array $overrides = []): User
    {
        return User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
            ...$overrides,
        ]);
    }
}
