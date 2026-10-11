<?php

namespace Tests\Feature\Transport;

use App\Events\Transport\MaintenanceDue;
use App\Models\MaintenanceRecord;
use App\Models\MileageLog;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Transport\TransportScope;
use App\Services\Transport\TransportService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * TransportScope itself, the policy, the service's location rules, the notifications and the migration backfill.
 * Screens, exports and the API are in TransportScopedScreensTest.
 */
class TransportScopeTest extends TransportTestCase
{
    // ---------------------------------------------------------------- who sees which vehicles

    public function test_each_kind_of_user_reaches_only_their_own_vehicles(): void
    {
        $acc = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $odo = $this->vehicleAt($this->odorkor, 'ODO-1');
        $ho = $this->vehicleAt($this->headOffice, 'HO-1');
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $nowhere = $this->unplacedVehicle('NOPL-1');
        $scope = app(TransportScope::class);

        // A regional (or district) user: their region, EXCLUDING Head Office, which shares the region id.
        $this->assertSame($this->idsOf(collect([$acc, $odo])), $this->idsOf($scope->vehicles($this->manager('M1', $this->sowutuom))->get()));
        $this->assertSame($this->idsOf(collect([$acc, $odo])), $this->idsOf($scope->vehicles($this->person('E1', $this->odorkor))->get()));
        $this->assertSame([$kum->id], $this->idsOf($scope->vehicles($this->manager('M2', $this->kumasi))->get()));

        // Head Office staff: Head Office vehicles only, not their region's.
        $this->assertSame([$ho->id], $this->idsOf($scope->vehicles($this->manager('M3', $this->headOffice))->get()));

        // super_admin: everything, including a vehicle nobody placed.
        $this->assertSame($this->idsOf(collect([$acc, $odo, $ho, $kum, $nowhere])), $this->idsOf($scope->vehicles($this->superAdmin())->get()));

        // No employee record: nothing, whatever the role.
        $this->assertSame([], $scope->vehicles($this->homeless('H1', ['transport_manager']))->pluck('id')->all());
    }

    public function test_a_single_vehicle_check_matches_the_query_scope_and_unknown_ids_look_the_same(): void
    {
        $acc = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $scope = app(TransportScope::class);
        $manager = $this->manager('M1', $this->sowutuom);

        $this->assertTrue($scope->contains($manager, $acc));
        $this->assertFalse($scope->contains($manager, $kum));
        $this->assertSame($acc->id, $scope->findVehicle($manager, $acc->id)->id);

        foreach ([$kum->id, 999999] as $id) {
            try {
                $scope->findVehicle($manager, $id);
                $this->fail('Expected a not-found for '.$id);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(NotFoundHttpException::class);
        $scope->authorizeVehicle($manager, $kum);
    }

    public function test_records_reached_through_a_vehicle_follow_the_same_scope(): void
    {
        $acc = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $ho = $this->vehicleAt($this->headOffice, 'HO-1');
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $manager = $this->manager('M1', $this->sowutuom);

        foreach ([$acc, $ho, $kum] as $vehicle) {
            $this->expense($vehicle, []);
            $this->issue($vehicle);
            $this->mileageLog($vehicle);
            $this->maintenanceRecord($vehicle);
        }

        foreach ([VehicleExpense::class, VehicleIssue::class, MileageLog::class, MaintenanceRecord::class] as $model) {
            $this->assertSame([$acc->id], $model::query()->visibleTo($manager)->pluck('vehicle_id')->all(), $model);
            $this->assertSame([$ho->id], $model::query()->visibleTo($this->manager('M3', $this->headOffice))->pluck('vehicle_id')->all(), $model);
            $this->assertSame(3, $model::query()->visibleTo($this->superAdmin())->count(), $model);
            $this->assertSame(0, $model::query()->visibleTo($this->homeless('H1', ['transport_manager']))->count(), $model);
        }
    }

    // ---------------------------------------------------------------- the policy

    public function test_transport_manager_has_no_bypass_in_the_policy_and_is_bound_by_location(): void
    {
        $acc = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $unplaced = $this->unplacedVehicle('NOPL-1');
        $manager = $this->manager('M1', $this->sowutuom);
        $gate = Gate::forUser($manager);

        foreach (['view', 'update', 'assign'] as $ability) {
            $this->assertTrue($gate->allows($ability, $acc), $ability.' own');
            $this->assertFalse($gate->allows($ability, $kum), $ability.' other region');
            $this->assertFalse($gate->allows($ability, $unplaced), $ability.' unplaced');
        }

        $this->assertTrue($gate->allows('create', Vehicle::class));
        // No employee record, no place to put a vehicle: the manager role alone is not enough.
        $this->assertFalse(Gate::forUser($this->homeless('H1', ['transport_manager']))->allows('create', Vehicle::class));

        $super = Gate::forUser($this->superAdmin());
        $this->assertTrue($super->allows('view', $unplaced));
        $this->assertTrue($super->allows('update', $kum));
    }

    // ---------------------------------------------------------------- the service: location and assignees

    public function test_a_new_vehicle_takes_the_actors_own_location_and_ignores_a_posted_one(): void
    {
        $manager = $this->manager('M1', $this->sowutuom);
        $this->actingAs($manager);

        $vehicle = app(TransportService::class)->saveVehicle($this->payload('NEW-1', [
            'region_id' => $this->ashanti->id,
            'district_id' => $this->kumasi->id,
        ]));

        $this->assertSame($this->accraWest->id, $vehicle->region_id);
        $this->assertSame($this->sowutuom->id, $vehicle->district_id);

        $hoVehicle = app(TransportService::class)->saveVehicle($this->payload('NEW-2'), null, null, $this->manager('M3', $this->headOffice));
        $this->assertSame($this->headOffice->id, $hoVehicle->district_id);
    }

    public function test_only_super_admin_picks_a_location_and_it_must_be_consistent(): void
    {
        $service = app(TransportService::class);
        $super = $this->superAdmin();

        $vehicle = $service->saveVehicle($this->payload('SA-1', ['region_id' => $this->ashanti->id, 'district_id' => $this->kumasi->id]), null, null, $super);
        $this->assertSame([$this->ashanti->id, $this->kumasi->id], [$vehicle->region_id, $vehicle->district_id]);

        // A district alone decides its region.
        $byDistrict = $service->saveVehicle($this->payload('SA-2', ['district_id' => $this->headOffice->id]), null, null, $super);
        $this->assertSame($this->accraWest->id, $byDistrict->region_id);

        foreach ([
            ['region_id' => null, 'district_id' => null],                                          // a location is required
            ['region_id' => $this->ashanti->id, 'district_id' => $this->sowutuom->id],             // district not in that region
        ] as $location) {
            try {
                $service->saveVehicle($this->payload('SA-BAD', $location), null, null, $super);
                $this->fail('Expected a validation error.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertFalse(Vehicle::query()->where('number_plate', 'SA-BAD')->exists());
    }

    public function test_a_user_without_a_region_cannot_create_a_vehicle(): void
    {
        $this->expectException(ValidationException::class);

        app(TransportService::class)->saveVehicle($this->payload('NOREGION-1'), null, null, $this->homeless('H1', ['transport_manager']));
    }

    public function test_assigning_a_user_or_driver_outside_the_vehicles_location_is_refused(): void
    {
        $service = app(TransportService::class);
        $manager = $this->manager('M1', $this->sowutuom);
        $local = $this->person('LOCAL1', $this->odorkor);
        $other = $this->person('OTHER1', $this->kumasi);
        $headOfficer = $this->person('HOFF1', $this->headOffice);
        $otherDriver = $this->driver('DRVK1', $this->kumasi);

        // Creating: an employee of another region, and a Head Office employee on a regional vehicle, are both refused.
        foreach ([$other, $headOfficer] as $outsider) {
            try {
                $service->saveVehicle($this->payload('ASSIGN-1', ['is_pool_car' => false, 'assigned_user_id' => $outsider->id]), null, null, $manager);
                $this->fail('Expected the assignee to be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('form.assigned_user_id', $exception->errors());
            }
        }

        try {
            $service->saveVehicle($this->payload('ASSIGN-1', ['driver_type' => Vehicle::DRIVER_ASSIGNED, 'assigned_driver_id' => $otherDriver->id]), null, null, $manager);
            $this->fail('Expected the driver to be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form.assigned_driver_id', $exception->errors());
        }

        $this->assertFalse(Vehicle::query()->where('number_plate', 'ASSIGN-1')->exists());

        // A colleague of the same region (another district) is fine.
        $vehicle = $service->saveVehicle($this->payload('ASSIGN-2', ['is_pool_car' => false, 'assigned_user_id' => $local->id]), null, null, $manager);
        $this->assertSame($local->id, $vehicle->assigned_user_id);

        // The assign action is bound the same way, and a Head Office vehicle only takes Head Office people.
        $this->expectException(ValidationException::class);
        $service->assignVehicle($vehicle, $other->id, null, $manager->id);
    }

    public function test_a_head_office_vehicle_takes_only_head_office_people(): void
    {
        $service = app(TransportService::class);
        $hoManager = $this->manager('M3', $this->headOffice);
        $regional = $this->person('REG1', $this->sowutuom);
        // Same region id as Head Office (the trap), yet not Head Office staff.
        $this->assertSame($this->accraWest->id, $regional->employee->region_id);
        $hoPerson = $this->person('HOFF1', $this->headOffice);

        $vehicle = $service->saveVehicle($this->payload('HO-A', ['is_pool_car' => false, 'assigned_user_id' => $hoPerson->id]), null, null, $hoManager);
        $this->assertSame($hoPerson->id, $vehicle->assigned_user_id);

        // Same region id, but not Head Office: refused.
        $this->expectException(ValidationException::class);
        $service->assignVehicle($vehicle, $regional->id, null, $hoManager->id);
    }

    public function test_every_service_write_on_another_regions_vehicle_answers_not_found(): void
    {
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $outsider = $this->manager('M1', $this->sowutuom);
        $service = app(TransportService::class);

        $attempts = [
            'save' => fn () => $service->saveVehicle($this->payload('KUM-1'), $kum, null, $outsider),
            'assign' => fn () => $service->assignVehicle($kum, null, null, $outsider->id),
            'unassign' => fn () => $service->unassignVehicle($kum, $outsider->id),
            'mileage' => fn () => $service->logMileage($kum, $outsider, ['mileage_after' => $kum->current_mileage + 10]),
            'issue' => fn () => $service->reportIssue($kum, $outsider, ['issue_types' => ['engine'], 'description' => 'x', 'severity' => 'low']),
            'maintenance' => fn () => $service->recordMaintenance($kum, ['maintenance_type' => 'repair', 'cost' => 10, 'service_date' => today()->toDateString()], null, $outsider),
            'expense' => fn () => $service->recordExpense($kum, ['expense_type' => 'fuel', 'amount' => 10, 'expense_date' => today()->toDateString()], null, $outsider->id, $outsider),
            'renewal' => fn () => $service->renewDocument($kum, 'insurance', today()->addYear()->toDateString(), 100, $outsider->id, $outsider),
            'issue status' => fn () => $service->updateIssueStatus($this->issue($kum), 'resolved', $outsider),
        ];

        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                $this->fail("{$name} should have been refused.");
            } catch (NotFoundHttpException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, MileageLog::query()->where('vehicle_id', $kum->id)->count());
        $this->assertSame(0, VehicleExpense::query()->where('vehicle_id', $kum->id)->count());
    }

    public function test_nobody_acting_writes_nothing(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(TransportService::class)->saveVehicle($this->payload('GHOST-1'));
    }

    // ---------------------------------------------------------------- notifications

    public function test_expiry_alerts_go_to_the_managers_of_the_vehicles_location_and_super_admin(): void
    {
        $this->vehicleAt($this->sowutuom, 'ACC-1', ['insurance_expiry_date' => today()->addDays(10)->toDateString()]);

        $local = $this->manager('M1', $this->sowutuom);
        $sameRegion = $this->manager('M2', $this->odorkor);
        $headOffice = $this->manager('M3', $this->headOffice);
        $otherRegion = $this->manager('M4', $this->kumasi);
        $homeless = $this->homeless('H1', ['transport_manager']);
        $super = $this->superAdmin();
        $driver = $this->driver('D1', $this->sowutuom);

        $this->artisan('transport:check-expiries')->assertSuccessful();

        foreach ([$local, $sameRegion, $super] as $recipient) {
            Notification::assertSentTo($recipient, GeneralDatabaseNotification::class);
        }

        foreach ([$headOffice, $otherRegion, $homeless, $driver] as $bystander) {
            Notification::assertNotSentTo($bystander, GeneralDatabaseNotification::class);
        }
    }

    public function test_head_office_vehicle_alerts_reach_head_office_managers_not_regional_ones(): void
    {
        $this->vehicleAt($this->headOffice, 'HO-1', ['insurance_expiry_date' => today()->addDays(10)->toDateString()]);
        $headOffice = $this->manager('M3', $this->headOffice);
        $regional = $this->manager('M1', $this->sowutuom);

        $this->artisan('transport:check-expiries')->assertSuccessful();

        Notification::assertSentTo($headOffice, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($regional, GeneralDatabaseNotification::class);
    }

    public function test_an_unplaced_vehicle_alerts_super_admin_only(): void
    {
        $this->unplacedVehicle('NOPL-1', ['insurance_expiry_date' => today()->addDays(10)->toDateString()]);
        $manager = $this->manager('M1', $this->sowutuom);
        $super = $this->superAdmin();

        $this->artisan('transport:check-expiries')->assertSuccessful();

        Notification::assertSentTo($super, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($manager, GeneralDatabaseNotification::class);
    }

    public function test_issue_and_maintenance_alerts_follow_the_same_rule(): void
    {
        $acc = $this->vehicleAt($this->sowutuom, 'ACC-1', ['current_mileage' => 4800, 'maintenance_interval_km' => 5000]);
        $driver = $this->driver('D1', $this->sowutuom);
        $local = $this->manager('M1', $this->odorkor);
        $other = $this->manager('M4', $this->kumasi);
        $headOffice = $this->manager('M3', $this->headOffice);

        app(TransportService::class)->reportIssue($acc, $driver, ['issue_types' => ['engine'], 'description' => 'Knocking', 'severity' => 'high']);

        Notification::assertSentTo($local, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($other, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($headOffice, GeneralDatabaseNotification::class);

        Notification::fake();
        event(new MaintenanceDue($acc));

        Notification::assertSentTo($local, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($other, GeneralDatabaseNotification::class);
    }

    // ---------------------------------------------------------------- the migration backfill

    public function test_backfill_places_a_vehicle_from_its_assigned_user_else_its_driver_and_leaves_the_rest_unplaced(): void
    {
        $user = $this->person('BF1', $this->kumasi);
        $driver = $this->driver('BF2', $this->headOffice);

        $fromUser = $this->unplacedVehicle('BF-USER', ['is_pool_car' => false, 'assigned_user_id' => $user->id]);
        $fromDriver = $this->unplacedVehicle('BF-DRIVER', ['driver_type' => Vehicle::DRIVER_ASSIGNED, 'assigned_driver_id' => $driver->id]);
        $userBeforeDriver = $this->unplacedVehicle('BF-BOTH', ['is_pool_car' => false, 'assigned_user_id' => $user->id, 'assigned_driver_id' => $driver->id]);
        $pool = $this->unplacedVehicle('BF-POOL');
        $homelessAssignee = $this->unplacedVehicle('BF-HOMELESS', ['is_pool_car' => false, 'assigned_user_id' => $this->homeless('H1', ['employee'])->id]);
        $alreadyPlaced = $this->vehicleAt($this->sowutuom, 'BF-PLACED', ['is_pool_car' => false, 'assigned_user_id' => $user->id]);

        (include database_path('migrations/2026_10_17_000001_add_location_to_vehicles_table.php'))->up();

        $this->assertSame([$this->ashanti->id, $this->kumasi->id], [$fromUser->fresh()->region_id, $fromUser->fresh()->district_id]);
        $this->assertSame([$this->accraWest->id, $this->headOffice->id], [$fromDriver->fresh()->region_id, $fromDriver->fresh()->district_id]);
        $this->assertSame($this->kumasi->id, $userBeforeDriver->fresh()->district_id);

        foreach ([$pool, $homelessAssignee] as $vehicle) {
            $this->assertNull($vehicle->fresh()->region_id);
            $this->assertNull($vehicle->fresh()->district_id);
        }

        // A vehicle that already has a place is never overwritten.
        $this->assertSame($this->sowutuom->id, $alreadyPlaced->fresh()->district_id);

        // And the placed ones now behave: Head Office driver's vehicle belongs to Head Office, not to Accra West.
        $scope = app(TransportScope::class);
        $this->assertTrue($scope->contains($this->manager('M3', $this->headOffice), $fromDriver));
        $this->assertFalse($scope->contains($this->manager('M1', $this->sowutuom), $fromDriver));
        $this->assertFalse($scope->contains($this->manager('M1', $this->sowutuom), $pool));
        $this->assertTrue($scope->contains($this->superAdmin(), $pool));
    }

    /** @param array<string, mixed> $overrides */
    protected function payload(string $plate, array $overrides = []): array
    {
        return [
            'type' => Vehicle::TYPE_SEDAN,
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'color' => 'White',
            'number_plate' => $plate,
            'year_purchased' => 2024,
            'is_pool_car' => true,
            'assigned_user_id' => null,
            'department_id' => null,
            'driver_type' => Vehicle::DRIVER_SELF_DRIVE,
            'assigned_driver_id' => null,
            'current_mileage' => 1000,
            'maintenance_interval_km' => 5000,
            'insurance_expiry_date' => now()->addYear()->toDateString(),
            'road_worthiness_expiry_date' => now()->addYear()->toDateString(),
            'status' => Vehicle::STATUS_ACTIVE,
            ...$overrides,
        ];
    }
}
