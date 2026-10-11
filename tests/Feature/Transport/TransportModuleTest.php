<?php

namespace Tests\Feature\Transport;

use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Vehicle;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Transport\TransportService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Transport basics. Since Transport became region-scoped (TransportScope) every vehicle, person and actor here has a
 * place; the scoping rules themselves are in TransportScopeTest and TransportScopedScreensTest.
 */
class TransportModuleTest extends TransportTestCase
{
    public function test_transport_roles_receive_transport_module_access(): void
    {
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
        $manager = $this->manager('TRM001', $this->sowutuom);
        $employee = $this->person('EMP001', $this->sowutuom);
        $this->vehicleAt($this->sowutuom, 'GV-1000-26', [
            'assigned_user_id' => $employee->id,
            'is_pool_car' => false,
        ]);

        try {
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
            ], null, null, $manager);

            $this->fail('A second active assignment for the same employee should be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame('This employee already has an active vehicle assignment.', $exception->errors()['form.assigned_user_id'][0]);
        }

        $this->assertFalse(Vehicle::query()->where('number_plate', 'GV-2000-26')->exists());
    }

    public function test_driver_api_can_log_mileage_and_update_vehicle_current_mileage(): void
    {
        $driver = $this->driver('DRV001', $this->sowutuom, ['api_token' => 'driver-token']);

        $vehicle = $this->vehicleAt($this->sowutuom, 'GV-3000-26', [
            'current_mileage' => 12000,
            'assigned_driver_id' => $driver->id,
            'driver_type' => Vehicle::DRIVER_ASSIGNED,
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
        $manager = $this->manager('TRM001', $this->sowutuom);

        $this->vehicleAt($this->sowutuom, 'GV-4000-26', [
            'insurance_expiry_date' => today()->addDays(10)->toDateString(),
            'road_worthiness_expiry_date' => today()->addDays(100)->toDateString(),
        ]);

        $this->artisan('transport:check-expiries')->assertSuccessful();

        Notification::assertSentTo($manager, GeneralDatabaseNotification::class);
    }

    public function test_reports_page_is_visible_to_transport_manager_only(): void
    {
        $manager = $this->manager('TRM002', $this->sowutuom);
        $driver = $this->driver('DRV002', $this->sowutuom);

        $this->actingAs($manager)
            ->get(route('transport.reports'))
            ->assertOk()
            ->assertSee('Transport Reports');

        $this->actingAs($driver)
            ->get(route('transport.reports'))
            ->assertForbidden();
    }
}
