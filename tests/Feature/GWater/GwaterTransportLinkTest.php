<?php

namespace Tests\Feature\GWater;

use App\Models\AuditLog;
use App\Models\GwExpense;
use App\Models\GwPurchase;
use App\Models\MileageLog;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Services\GWater\GwaterSummaryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The Transport link is read-only and goes through TransportScope. Reimbursement covers fuel only, and never by the person who
 * recorded the entry.
 */
class GwaterTransportLinkTest extends GwaterTestCase
{
    private function fuelFor(Vehicle $vehicle, int $cedis = 150, array $attributes = []): VehicleExpense
    {
        return $this->expense($vehicle, ['expense_type' => 'fuel', 'amount' => $cedis, 'expense_date' => '2026-06-01', ...$attributes]);
    }

    public function test_a_trip_can_read_the_vehicle_mileage_and_fuel_from_transport_without_changing_them(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $vehicle = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $fuel = $this->fuelFor($vehicle, 150);
        $log = $this->mileageLog($vehicle, ['driver_id' => $user->id]);
        $driver = $this->driver('D1', $this->sowutuom);
        $before = [$fuel->fresh()->toArray(), $log->fresh()->toArray(), $vehicle->fresh()->toArray()];
        $counts = [VehicleExpense::count(), MileageLog::count(), Vehicle::count()];

        $trip = $this->trip($user, 'WB-1', 100, 0, '2026-06-01', [
            'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'mileage_log_id' => $log->id, 'vehicle_expense_id' => $fuel->id,
        ]);

        $this->assertSame([$vehicle->id, $driver->id, $log->id, $fuel->id], [$trip->vehicle_id, $trip->driver_id, $trip->mileage_log_id, $trip->vehicle_expense_id]);
        // The fuel figure is the Transport row's amount, read live and never copied.
        $this->assertSame(15000, app(GwaterSummaryService::class)->tripFuel($trip->load('vehicleExpense', 'expenses')));
        $this->assertSame(0, GwExpense::query()->where('category', 'fuel')->count());

        // G-Water wrote nothing to Transport.
        $this->assertSame($before, [$fuel->fresh()->toArray(), $log->fresh()->toArray(), $vehicle->fresh()->toArray()]);
        $this->assertSame($counts, [VehicleExpense::count(), MileageLog::count(), Vehicle::count()]);
    }

    public function test_a_vehicle_outside_the_actors_reach_is_not_found(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        $kumasi = $this->vehicleAt($this->kumasi, 'KUM-1');
        $headOffice = $this->vehicleAt($this->headOffice, 'HO-1');
        $unplaced = $this->unplacedVehicle('NOPL-1');

        foreach ([$kumasi, $headOffice, $unplaced] as $vehicle) {
            try {
                $this->trip($user, 'WB-'.$vehicle->id, 10, 0, '2026-06-01', ['vehicle_id' => $vehicle->id]);
                $this->fail("{$vehicle->number_plate} is outside this user's reach.");
            } catch (ModelNotFoundException) {
                $this->assertSame(0, GwPurchase::query()->count());
            }
        }
    }

    public function test_the_fuel_and_mileage_of_a_vehicle_outside_reach_are_not_found_either(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $own = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $foreign = $this->vehicleAt($this->kumasi, 'KUM-1');
        $foreignFuel = $this->fuelFor($foreign);
        $foreignLog = $this->mileageLog($foreign);

        foreach ([['vehicle_expense_id' => $foreignFuel->id], ['mileage_log_id' => $foreignLog->id]] as $link) {
            try {
                $this->trip($user, 'WB-1', 10, 0, '2026-06-01', ['vehicle_id' => $own->id, ...$link]);
                $this->fail('A Transport row outside reach must not be linkable.');
            } catch (ModelNotFoundException) {
                $this->assertSame(0, GwPurchase::query()->count());
            }
        }
    }

    public function test_the_links_must_belong_to_the_chosen_vehicle_and_the_right_kind(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $one = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $two = $this->vehicleAt($this->sowutuom, 'ACC-2');
        $notFuel = $this->expense($one, ['expense_type' => 'parking', 'amount' => 20, 'expense_date' => '2026-06-01']);

        $cases = [
            'vehicle_expense_id' => ['vehicle_id' => $one->id, 'vehicle_expense_id' => $this->fuelFor($two)->id],
            'mileage_log_id' => ['vehicle_id' => $one->id, 'mileage_log_id' => $this->mileageLog($two)->id],
            'not fuel' => ['vehicle_id' => $one->id, 'vehicle_expense_id' => $notFuel->id],
            'no vehicle' => ['vehicle_expense_id' => $this->fuelFor($one)->id],
        ];

        foreach ($cases as $name => $links) {
            try {
                $this->trip($user, 'WB-1', 10, 0, '2026-06-01', $links);
                $this->fail("{$name} should have been refused.");
            } catch (ValidationException) {
                $this->assertSame(0, GwPurchase::query()->count(), $name);
            }
        }
    }

    public function test_a_vehicle_of_another_g_water_location_is_refused_even_for_super_admin(): void
    {
        $regional = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $super = $this->superAdmin();
        $this->actingAs($super);

        // super_admin reaches every vehicle, but a Head Office trip cannot use a regional vehicle.
        try {
            $this->workflow()->recordPurchase($super, [
                'location_district_id' => $this->headOffice->id, 'purchase_date' => '2026-06-01', 'waybill_no' => 'WB-1',
                'lines' => [['product_id' => $this->bottle->id, 'packs' => 5]], 'vehicle_id' => $regional->id,
            ]);
            $this->fail('A regional vehicle cannot be used on a Head Office trip.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vehicle_id', $exception->errors());
        }

        $trip = $this->workflow()->recordPurchase($super, [
            'location_district_id' => $this->odorkor->id, 'purchase_date' => '2026-06-01', 'waybill_no' => 'WB-1',
            'lines' => [['product_id' => $this->bottle->id, 'packs' => 5]], 'vehicle_id' => $regional->id,
        ]);
        $this->assertSame($regional->id, $trip->vehicle_id);
    }

    public function test_a_driver_must_be_based_at_the_location(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $elsewhere = $this->driver('D9', $this->kumasi);
        $headOfficeDriver = $this->driver('D8', $this->headOffice);

        foreach ([$elsewhere, $headOfficeDriver] as $driver) {
            try {
                $this->trip($user, 'WB-1', 10, 0, '2026-06-01', ['driver_id' => $driver->id]);
                $this->fail('A driver of another location must be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('driver_id', $exception->errors());
            }
        }
    }

    public function test_a_fuel_expense_belongs_to_one_trip_only_until_that_trip_is_voided(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $vehicle = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $fuel = $this->fuelFor($vehicle);
        $link = ['vehicle_id' => $vehicle->id, 'vehicle_expense_id' => $fuel->id];

        $first = $this->trip($user, 'WB-1', 10, 0, '2026-06-01', $link);

        try {
            $this->trip($user, 'WB-2', 10, 0, '2026-06-01', $link);
            $this->fail('Fuel must never be counted twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vehicle_expense_id', $exception->errors());
        }

        $this->workflow()->voidPurchase($first, $user, 'Wrong trip');
        $this->assertNull($first->fresh()->vehicle_expense_id);
        $audit = AuditLog::query()->where('action', 'gwater.purchase_voided')->firstOrFail();
        $this->assertSame($fuel->id, $audit->metadata['vehicle_expense_id'], 'the old link is kept in the audit row');

        $second = $this->trip($user, 'WB-2', 10, 0, '2026-06-01', $link);
        $this->assertSame($fuel->id, $second->vehicle_expense_id);
    }

    public function test_fuel_has_one_source_per_trip_either_transport_or_g_water(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $vehicle = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $fuel = $this->fuelFor($vehicle);

        try {
            $this->trip($user, 'WB-1', 10, 0, '2026-06-01', ['vehicle_id' => $vehicle->id, 'vehicle_expense_id' => $fuel->id, 'fuel_amount' => 5000]);
            $this->fail('Fuel from Transport and from G-Water together is double counting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fuel_amount', $exception->errors());
        }

        $own = $this->trip($user, 'WB-2', 10, 0, '2026-06-01', ['fuel_amount' => 5000]);
        $this->assertSame(5000, app(GwaterSummaryService::class)->tripFuel($own->load('expenses')));
    }

    // ---------------------------------------------------------------- reimbursement

    public function test_only_fuel_paid_out_of_pocket_becomes_reimbursable(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $payer = $this->person('P1', $this->sowutuom);

        $trip = $this->trip($user, 'WB-1', 10, 0, '2026-06-01', [
            'fuel_amount' => 12000, 'fuel_paid_by_user_id' => $payer->id, 'loading_amount' => 3000, 'offloading_amount' => 2000,
        ]);

        $byCategory = GwExpense::query()->where('purchase_id', $trip->id)->get()->keyBy('category');
        $this->assertSame(['pending', 'not_applicable', 'not_applicable'], [
            $byCategory['fuel']->reimbursement_status, $byCategory['loading']->reimbursement_status, $byCategory['offloading']->reimbursement_status,
        ]);
        $this->assertSame($payer->id, $byCategory['fuel']->paid_by_user_id);
        $this->assertNull($byCategory['loading']->paid_by_user_id);

        // Fuel the company paid for is not reimbursable.
        $company = $this->trip($user, 'WB-2', 10, 0, '2026-06-01', ['fuel_amount' => 4000]);
        $this->assertSame('not_applicable', GwExpense::query()->where('purchase_id', $company->id)->value('reimbursement_status'));

        // A payer who is not based at the location, or without any fuel, is refused.
        foreach ([['fuel_amount' => 100, 'fuel_paid_by_user_id' => $this->person('P2', $this->kumasi)->id], ['fuel_paid_by_user_id' => $payer->id]] as $bad) {
            try {
                $this->trip($user, 'WB-3', 10, 0, '2026-06-01', $bad);
                $this->fail('Expected a refusal.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('fuel_paid_by_user_id', $exception->errors());
            }
        }
    }

    public function test_transport_fuel_paid_out_of_pocket_is_reimbursed_from_the_trip_never_from_a_copy(): void
    {
        $recorder = $this->gw('G1', $this->sowutuom);
        $approver = $this->gw('G2', $this->odorkor);
        $payer = $this->person('P1', $this->sowutuom);
        $vehicle = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $fuel = $this->fuelFor($vehicle, 150);

        $trip = $this->trip($recorder, 'WB-1', 10, 0, '2026-06-01', ['vehicle_id' => $vehicle->id, 'vehicle_expense_id' => $fuel->id, 'fuel_paid_by_user_id' => $payer->id]);
        $this->assertSame('pending', $trip->fuel_reimbursement_status);
        $this->assertSame(0, GwExpense::count(), 'no copy of the Transport amount is ever created');

        $pending = app(GwaterSummaryService::class)->pendingReimbursements(['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id]);
        $this->assertSame([['trip', $trip->id, 15000, $payer->id]], $pending->map(fn ($item) => [$item['kind'], $item['id'], $item['amount'], $item['person_id']])->all());

        // The recorder may not mark it; someone else can.
        $this->actingAs($recorder);
        try {
            $this->workflow()->markReimbursed($recorder, 'trip', $trip->id, 'CHQ-1');
            $this->fail('The person who recorded the entry cannot reimburse it.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reference', $exception->errors());
        }

        $this->actingAs($approver);
        $done = $this->workflow()->markReimbursed($approver, 'trip', $trip->id, 'CHQ-1', '2026-06-20');
        $this->assertSame(['reimbursed', 'CHQ-1', $approver->id], [$done->fuel_reimbursement_status, $done->fuel_reimbursement_ref, $done->fuel_reimbursed_by_user_id]);
        $this->assertSame(1, AuditLog::query()->where('action', 'gwater.fuel_reimbursed')->count());

        // Paid once only.
        $this->expectException(ValidationException::class);
        $this->workflow()->markReimbursed($approver, 'trip', $trip->id, 'CHQ-2');
    }

    public function test_the_person_who_paid_the_fuel_cannot_reimburse_themselves_and_needs_the_permission(): void
    {
        $recorder = $this->gw('G1', $this->sowutuom);
        $payer = $this->gw('G5', $this->sowutuom); // holds gwater.reimburse, but paid the fuel
        $trip = $this->trip($recorder, 'WB-1', 10, 0, '2026-06-01', ['fuel_amount' => 12000, 'fuel_paid_by_user_id' => $payer->id]);
        $expense = GwExpense::query()->where('purchase_id', $trip->id)->firstOrFail();

        $this->actingAs($payer);
        try {
            $this->workflow()->markReimbursed($payer, 'expense', $expense->id, 'CHQ-1');
            $this->fail('Nobody reimburses their own claim.');
        } catch (ValidationException) {
            $this->assertSame('pending', $expense->fresh()->reimbursement_status);
        }

        // Without gwater.reimburse (a plain employee) it is a 403 before anything else.
        $plain = $this->person('E1', $this->sowutuom);
        $this->actingAs($plain);
        try {
            $this->workflow()->markReimbursed($plain, 'expense', $expense->id, 'CHQ-1');
            $this->fail('gwater.reimburse is required.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        // Someone else with the permission can; a different location cannot even find it.
        $other = $this->gw('G6', $this->odorkor);
        $stranger = $this->gw('G7', $this->kumasi);
        $this->actingAs($stranger);
        try {
            $this->workflow()->markReimbursed($stranger, 'expense', $expense->id, 'CHQ-1');
            $this->fail('Another location\'s entry must not be found.');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $this->assertSame('pending', $expense->fresh()->reimbursement_status);
        }

        $this->actingAs($other);
        $this->assertSame('reimbursed', $this->workflow()->markReimbursed($other, 'expense', $expense->id, 'CHQ-1')->reimbursement_status);
    }

    public function test_labour_and_company_paid_fuel_cannot_be_marked_reimbursed(): void
    {
        $recorder = $this->gw('G1', $this->sowutuom);
        $approver = $this->gw('G2', $this->odorkor);
        $trip = $this->trip($recorder, 'WB-1', 10, 0, '2026-06-01', ['fuel_amount' => 4000, 'loading_amount' => 3000]);
        $this->actingAs($approver);

        foreach (GwExpense::query()->where('purchase_id', $trip->id)->get() as $expense) {
            try {
                $this->workflow()->markReimbursed($approver, 'expense', $expense->id, 'CHQ-1');
                $this->fail("{$expense->category} is not reimbursable here.");
            } catch (ValidationException) {
                $this->assertSame('not_applicable', $expense->fresh()->reimbursement_status);
            }
        }
    }

    public function test_a_voided_trip_drops_off_the_pending_list_and_its_costs_are_voided(): void
    {
        $recorder = $this->gw('G1', $this->sowutuom);
        $payer = $this->person('P1', $this->sowutuom);
        $trip = $this->trip($recorder, 'WB-1', 10, 0, '2026-06-01', ['fuel_amount' => 12000, 'fuel_paid_by_user_id' => $payer->id, 'loading_amount' => 3000]);
        $place = ['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id];
        $summary = app(GwaterSummaryService::class);

        $this->assertCount(1, $summary->pendingReimbursements($place));

        $this->workflow()->voidPurchase($trip, $recorder, 'Never happened');

        $this->assertCount(0, $summary->pendingReimbursements($place));
        $this->assertSame(['voided'], GwExpense::query()->pluck('status')->unique()->all());
        $this->assertSame(2, AuditLog::query()->where('action', 'gwater.expense_voided')->count());
    }

    public function test_the_trip_shows_a_note_when_the_transport_fuel_row_was_changed_afterwards(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $vehicle = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $fuel = $this->fuelFor($vehicle, 150);
        $trip = $this->trip($user, 'WB-1', 10, 0, '2026-06-01', ['vehicle_id' => $vehicle->id, 'vehicle_expense_id' => $fuel->id]);
        $summary = app(GwaterSummaryService::class);

        $this->assertFalse($summary->changedInTransport($trip->fresh()));

        Carbon::setTestNow('2026-10-12 09:00:00');
        $fuel->update(['amount' => 180]);

        $this->assertTrue($summary->changedInTransport($trip->fresh()));
        $this->assertSame(18000, $summary->tripFuel($trip->fresh()->load('vehicleExpense', 'expenses')), 'the P&L follows Transport');
    }
}
