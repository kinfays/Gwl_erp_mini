<?php

namespace Tests\Feature\GWater;

use App\Livewire\GWater\Damages;
use App\Livewire\GWater\Expenses;
use App\Livewire\GWater\Payments;
use App\Models\AuditLog;
use App\Models\GwDamage;
use App\Models\GwExpense;
use App\Models\GwPurchase;
use App\Models\GwStockMovement;
use App\Models\GwSupplierPayment;
use App\Models\VehicleExpense;
use App\Services\GWater\GwaterStockService;
use App\Services\GWater\GwaterSummaryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Damages, expenses, payments to G Water, the owed balance and the waybill-outstanding view. */
class GwaterPhase2Test extends GwaterTestCase
{
    private function onHand($district, $product = null): int
    {
        return app(GwaterStockService::class)->balance(['region_id' => $district->region_id, 'district_id' => $district->id], ($product ?? $this->bottle)->id);
    }

    private function damageData(int $packs, array $extra = []): array
    {
        return ['damage_date' => '2026-06-05', 'product_id' => $this->bottle->id, 'packs' => $packs, 'stage' => 'transit', 'reason' => 'Burst packs', ...$extra];
    }

    // ---------------------------------------------------------------- damages

    public function test_a_damage_takes_packs_out_of_stock_and_freezes_the_cost_in_force(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 100);

        $damage = $this->workflow()->recordDamage($user, $this->damageData(12));

        $this->assertSame(88, $this->onHand($this->sowutuom));
        $this->assertSame([2000, 12, 'transit'], [$damage->unit_cost, $damage->packs, $damage->stage]);
        $this->assertSame([-12], GwStockMovement::query()->where('type', 'damage')->pluck('packs')->all());

        // A later price change does not touch it.
        $this->workflow()->addPrice($user, ['product_id' => $this->bottle->id, 'effective_from' => '2026-08-01', 'selling_price' => 3000, 'cost_price' => 2500]);
        $this->assertSame(2000, $damage->fresh()->unit_cost);
    }

    public function test_a_damage_that_would_make_stock_negative_is_refused_and_leaves_nothing(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 10);

        try {
            $this->workflow()->recordDamage($user, $this->damageData(11));
            $this->fail('More damaged than in stock must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Not enough', $exception->errors()['packs'][0]);
        }

        $this->assertSame(0, GwDamage::query()->count());
        $this->assertSame(10, $this->onHand($this->sowutuom));
    }

    public function test_voiding_a_damage_puts_the_packs_back(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 100);
        $damage = $this->workflow()->recordDamage($user, $this->damageData(12));

        $this->workflow()->voidDamage($damage, $user, 'Counted twice');

        $this->assertSame(100, $this->onHand($this->sowutuom));
        $this->assertSame('voided', $damage->fresh()->status);
        $this->assertSame(1, GwDamage::query()->count(), 'nothing is deleted');

        $this->expectException(ValidationException::class);
        $this->workflow()->voidDamage($damage, $user, 'Again');
    }

    public function test_a_damage_tied_to_a_trip_cannot_exceed_what_the_trip_brought_and_stage_and_reason_are_required(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $trip = $this->trip($user, 'WB-1', 20);
        $this->trip($user, 'WB-2', 100);

        $this->workflow()->recordDamage($user, $this->damageData(15, ['purchase_id' => $trip->id]));

        foreach ([
            $this->damageData(6, ['purchase_id' => $trip->id]),         // 15 + 6 > 20
            $this->damageData(1, ['stage' => 'kitchen']),
            $this->damageData(1, ['reason' => ' ']),
            $this->damageData(1, ['product_id' => $this->sachet->id, 'purchase_id' => $trip->id]), // the trip brought no sachet
        ] as $bad) {
            try {
                $this->workflow()->recordDamage($user, $bad);
                $this->fail('Expected a refusal for '.json_encode($bad));
            } catch (ValidationException) {
                $this->assertSame(1, GwDamage::query()->count());
            }
        }
    }

    public function test_a_trip_of_another_location_cannot_be_named_on_a_damage_or_expense(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $kumasi = $this->gw('G2', $this->kumasi);
        $this->trip($kumasi, 'WB-K', 50);
        $this->trip($accra, 'WB-A', 50);
        $foreignTrip = GwPurchase::query()->where('waybill_no', 'WB-K')->firstOrFail();
        $headOffice = $this->gw('G3', $this->headOffice);
        $this->trip($headOffice, 'WB-H', 50);
        $hoTrip = GwPurchase::query()->where('waybill_no', 'WB-H')->firstOrFail();

        foreach ([$foreignTrip, $hoTrip] as $trip) {
            foreach ([
                fn () => $this->workflow()->recordDamage($accra, $this->damageData(1, ['purchase_id' => $trip->id])),
                fn () => $this->workflow()->recordExpense($accra, ['expense_date' => '2026-06-02', 'category' => 'loading', 'amount' => 1000, 'purchase_id' => $trip->id]),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Another location\'s trip must not be found.');
                } catch (NotFoundHttpException) {
                    $this->assertTrue(true);
                }
            }
        }

        $this->assertSame(0, GwDamage::query()->count() + GwExpense::query()->count());
    }

    // ---------------------------------------------------------------- expenses

    public function test_fuel_is_counted_once_per_trip_whether_it_comes_from_transport_or_from_g_water(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $vehicle = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $fuel = $this->expense($vehicle, ['expense_type' => 'fuel', 'amount' => 150, 'expense_date' => '2026-06-01']);

        $linked = $this->trip($user, 'WB-1', 10, 0, '2026-06-01', ['vehicle_id' => $vehicle->id, 'vehicle_expense_id' => $fuel->id]);
        $own = $this->trip($user, 'WB-2', 10);

        // A G-Water fuel expense cannot be added to the trip that already takes Transport fuel...
        try {
            $this->workflow()->recordExpense($user, ['expense_date' => '2026-06-01', 'category' => 'fuel', 'amount' => 5000, 'purchase_id' => $linked->id]);
            $this->fail('Transport fuel plus G-Water fuel is double counting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purchase_id', $exception->errors());
        }

        // ...one can be added to a trip with no fuel yet, but not a second one.
        $first = $this->workflow()->recordExpense($user, ['expense_date' => '2026-06-01', 'category' => 'fuel', 'amount' => 5000, 'purchase_id' => $own->id]);

        try {
            $this->workflow()->recordExpense($user, ['expense_date' => '2026-06-01', 'category' => 'fuel', 'amount' => 3000, 'purchase_id' => $own->id]);
            $this->fail('Fuel is counted once per trip.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purchase_id', $exception->errors());
        }

        // Voiding the first frees the trip for the right one; labour on the same trip is never a second fuel.
        $this->workflow()->voidExpense($first, $user, 'Wrong amount');
        $this->workflow()->recordExpense($user, ['expense_date' => '2026-06-01', 'category' => 'fuel', 'amount' => 4500, 'purchase_id' => $own->id]);
        $this->workflow()->recordExpense($user, ['expense_date' => '2026-06-01', 'category' => 'loading', 'amount' => 2000, 'purchase_id' => $own->id]);

        $this->assertSame(1, GwExpense::query()->posted()->where('purchase_id', $own->id)->where('category', 'fuel')->count());

        // And the Transport expense still cannot be linked to a second trip.
        try {
            $this->trip($user, 'WB-3', 10, 0, '2026-06-01', ['vehicle_id' => $vehicle->id, 'vehicle_expense_id' => $fuel->id]);
            $this->fail('A fuel expense links to one trip only.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vehicle_expense_id', $exception->errors());
        }
    }

    public function test_only_fuel_can_be_reimbursable_and_labour_cannot_name_a_payer(): void
    {
        $recorder = $this->gw('G1', $this->sowutuom);
        $payer = $this->person('P1', $this->sowutuom);

        foreach (['loading', 'offloading', 'other'] as $category) {
            try {
                $this->workflow()->recordExpense($recorder, ['expense_date' => '2026-06-01', 'category' => $category, 'amount' => 3000, 'paid_by_user_id' => $payer->id]);
                $this->fail("{$category} is not reimbursable.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('paid_by_user_id', $exception->errors());
            }

            $row = $this->workflow()->recordExpense($recorder, ['expense_date' => '2026-06-01', 'category' => $category, 'amount' => 3000]);
            $this->assertSame('not_applicable', $row->reimbursement_status);
        }

        $fuel = $this->workflow()->recordExpense($recorder, ['expense_date' => '2026-06-01', 'category' => 'fuel', 'amount' => 8000, 'paid_by_user_id' => $payer->id]);
        $this->assertSame(['pending', $payer->id], [$fuel->reimbursement_status, $fuel->paid_by_user_id]);

        // The recorder cannot reimburse their own entry; another G-Water user can, with a reference and date.
        $this->actingAs($recorder);
        try {
            $this->workflow()->markReimbursed($recorder, 'expense', $fuel->id, 'CHQ-1');
            $this->fail('The recorder cannot reimburse.');
        } catch (ValidationException) {
            $this->assertSame('pending', $fuel->fresh()->reimbursement_status);
        }

        // Labour rows are never reimbursable, even by someone else.
        $approver = $this->gw('G2', $this->odorkor);
        $this->actingAs($approver);
        $labour = GwExpense::query()->where('category', 'loading')->firstOrFail();

        try {
            $this->workflow()->markReimbursed($approver, 'expense', $labour->id, 'CHQ-2');
            $this->fail('Loading cannot be reimbursed.');
        } catch (ValidationException) {
            $this->assertSame('not_applicable', $labour->fresh()->reimbursement_status);
        }

        $done = $this->workflow()->markReimbursed($approver, 'expense', $fuel->id, 'CHQ-3', '2026-06-20');
        $this->assertSame(['reimbursed', 'CHQ-3'], [$done->reimbursement_status, $done->reimbursement_ref]);

        // Paid back fuel can no longer be voided.
        $this->expectException(ValidationException::class);
        $this->workflow()->voidExpense($done, $recorder, 'Changed my mind');
    }

    public function test_an_expense_validates_category_amount_payer_and_vehicle(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $elsewhere = $this->person('P9', $this->kumasi);
        $foreignVehicle = $this->vehicleAt($this->kumasi, 'KUM-1');
        $hoVehicle = $this->vehicleAt($this->headOffice, 'HO-1');
        $own = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $base = ['expense_date' => '2026-06-01', 'category' => 'fuel', 'amount' => 5000];

        foreach ([
            ['category' => 'party'], ['amount' => 0], ['amount' => -5], ['paid_by_user_id' => $elsewhere->id], ['expense_date' => '2026-12-31'],
        ] as $bad) {
            try {
                $this->workflow()->recordExpense($user, [...$base, ...$bad]);
                $this->fail('Expected a refusal for '.json_encode($bad));
            } catch (ValidationException) {
                $this->assertSame(0, GwExpense::query()->count());
            }
        }

        // A vehicle outside the user's reach is not found; the user's own is accepted.
        foreach ([$foreignVehicle, $hoVehicle] as $vehicle) {
            try {
                $this->workflow()->recordExpense($user, [...$base, 'vehicle_id' => $vehicle->id]);
                $this->fail('Another location\'s vehicle must not be found.');
            } catch (ModelNotFoundException) {
                $this->assertSame(0, GwExpense::query()->count());
            }
        }

        $this->assertSame($own->id, $this->workflow()->recordExpense($user, [...$base, 'vehicle_id' => $own->id])->vehicle_id);
    }

    // ---------------------------------------------------------------- payments and the owed balance

    /** The hand-worked account used below. */
    private function workedExample(): array
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        // Brought forward 2026-01-01: 100 bottle packs, 50 more paid for but not delivered, GH¢500.00 owed.
        $this->workflow()->recordOpeningBalance($user, [
            'as_of_date' => '2026-01-01', 'packs' => [$this->bottle->id => 100], 'outstanding' => [$this->bottle->id => 50], 'owed_amount' => 50000,
        ]);
        // Trip A: 40 bottle @ 20.00 = 800.00. Trip B: 10 bottle @ 20.00 + 20 sachet @ 8.00 = 200.00 + 160.00.
        $this->trip($user, 'WB-A', 40, 0, '2026-06-01');
        $this->trip($user, 'WB-B', 10, 20, '2026-06-10');
        // P1: 50 bottle packs @ 20.00 = 1,000.00, paid on 5 June. P2: 30 sachet packs @ 8.00 = 240.00, still only requested.
        $this->workflow()->recordPayment($user, [
            'request_date' => '2026-06-04', 'reference' => 'PR-1', 'paid_on' => '2026-06-05',
            'lines' => [['product_id' => $this->bottle->id, 'packs' => 50, 'unit_price' => 2000]],
        ]);
        $this->workflow()->recordPayment($user, [
            'request_date' => '2026-06-12', 'reference' => 'PR-2', 'lines' => [['product_id' => $this->sachet->id, 'packs' => 30]],
        ]);

        return [$user, ['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id]];
    }

    public function test_the_owed_balance_matches_a_hand_worked_account(): void
    {
        [, $place] = $this->workedExample();
        $summary = app(GwaterSummaryService::class);
        $statement = $summary->owedStatement($place);

        // 500.00 + 800.00 + 360.00 - 1,000.00 = 660.00; the 240.00 request is not paid yet.
        $this->assertSame(66000, $statement['owed']);
        $this->assertSame(66000, $summary->owedToGwater($place));
        $this->assertSame([50000, 116000, 100000, 24000], [$statement['opening'], $statement['purchases'], $statement['paid'], $statement['requested']]);

        // Oldest first, with the running balance after each line.
        $this->assertSame(
            [['opening', 50000], ['trip', 130000], ['payment', 30000], ['trip', 66000]],
            collect($statement['lines'])->map(fn ($line) => [$line['kind'], $line['balance']])->all()
        );

        // As of the day the payment was made, before trip B.
        $this->assertSame(30000, $summary->owedToGwater($place, \Illuminate\Support\Carbon::parse('2026-06-09')));
    }

    public function test_marking_a_request_paid_and_voiding_a_payment_move_the_balance(): void
    {
        [$user, $place] = $this->workedExample();
        $summary = app(GwaterSummaryService::class);
        $request = GwSupplierPayment::query()->where('reference', 'PR-2')->firstOrFail();

        $this->workflow()->markPaymentPaid($request, $user, '2026-06-15');
        $this->assertSame(66000 - 24000, $summary->owedToGwater($place));
        $this->assertSame('paid', $request->fresh()->status);

        // Paid twice, or paid before it was requested, is refused.
        foreach (['2026-06-16', '2026-06-01'] as $date) {
            try {
                $this->workflow()->markPaymentPaid($request->fresh(), $user, $date);
                $this->fail('Expected a refusal.');
            } catch (ValidationException) {
                $this->assertSame('paid', $request->fresh()->status);
            }
        }

        // Voiding a paid payment makes the amount owed again and keeps the row.
        $this->workflow()->voidPayment($request, $user, 'Bank reversed it');
        $this->assertSame(66000, $summary->owedToGwater($place));
        $this->assertSame(2, GwSupplierPayment::query()->count());
    }

    public function test_the_waybill_outstanding_view_sets_packs_paid_for_against_packs_delivered(): void
    {
        [, $place] = $this->workedExample();
        $rows = app(GwaterSummaryService::class)->waybillOutstanding($place)->keyBy(fn ($row) => $row['product']->code);

        // Bottle: 50 brought forward + 50 on PR-1 = 100 paid for; 40 + 10 delivered; 50 still to come.
        $this->assertSame([50, 50, 100, 50, 50], [$rows['bottle']['brought_forward'], $rows['bottle']['requested'], $rows['bottle']['ordered'], $rows['bottle']['delivered'], $rows['bottle']['outstanding']]);
        $this->assertSame(['WB-A', 'WB-B'], collect($rows['bottle']['waybills'])->pluck('waybill')->all());
        // Sachet: 30 on PR-2 (requested), 20 delivered on WB-B; 10 to come.
        $this->assertSame([0, 30, 30, 20, 10], [$rows['sachet']['brought_forward'], $rows['sachet']['requested'], $rows['sachet']['ordered'], $rows['sachet']['delivered'], $rows['sachet']['outstanding']]);

        // A voided request stops counting.
        $this->workflow()->voidPayment(GwSupplierPayment::query()->where('reference', 'PR-2')->firstOrFail(), $this->gw('G2', $this->odorkor), 'Cancelled order');
        $this->assertSame(-20, app(GwaterSummaryService::class)->waybillOutstanding($place)->firstWhere(fn ($row) => $row['product']->code === 'sachet')['outstanding']);
    }

    public function test_payment_rules_amount_reference_and_packs(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $line = ['product_id' => $this->bottle->id, 'packs' => 10, 'unit_price' => 2600];

        $ok = $this->workflow()->recordPayment($user, ['request_date' => '2026-06-04', 'reference' => 'PR-1', 'lines' => [$line]]);
        $this->assertSame([26000, 'requested'], [$ok->amount, $ok->status], 'the amount is worked out from the packs');

        // A plain amount with no packs is fine.
        $this->assertSame(5000, $this->workflow()->recordPayment($user, ['request_date' => '2026-06-04', 'reference' => 'PR-3', 'amount' => 5000])->amount);

        foreach ([
            ['reference' => 'pr-1', 'lines' => [$line]],                              // duplicate reference, any case
            ['reference' => 'PR-4', 'lines' => [$line], 'amount' => 25000],           // amount disagrees with the packs
            ['reference' => 'PR-5'],                                                  // no amount, no packs
            ['reference' => ' '],                                                     // no reference
            ['reference' => 'PR-6', 'amount' => 100, 'paid_on' => '2026-06-01'],      // paid before it was requested
        ] as $bad) {
            try {
                $this->workflow()->recordPayment($user, ['request_date' => '2026-06-04', ...$bad]);
                $this->fail('Expected a refusal for '.json_encode($bad));
            } catch (ValidationException) {
                $this->assertSame(2, GwSupplierPayment::query()->count());
            }
        }

        // The same reference in another location is fine.
        $other = $this->gw('G2', $this->kumasi);
        $this->workflow()->recordPayment($other, ['request_date' => '2026-06-04', 'reference' => 'PR-1', 'amount' => 100]);
        $this->assertSame(3, GwSupplierPayment::query()->count());
    }

    public function test_payments_of_another_location_cannot_be_paid_or_voided(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $payment = $this->workflow()->recordPayment($accra, ['request_date' => '2026-06-04', 'reference' => 'PR-1', 'amount' => 5000]);
        $headOffice = $this->gw('G3', $this->headOffice);
        $kumasi = $this->gw('G2', $this->kumasi);

        foreach ([$kumasi, $headOffice] as $stranger) {
            $this->actingAs($stranger);

            foreach ([
                fn () => $this->workflow()->markPaymentPaid($payment, $stranger, '2026-06-05'),
                fn () => $this->workflow()->voidPayment($payment, $stranger, 'Not mine'),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Another location\'s payment must not be found.');
                } catch (NotFoundHttpException) {
                    $this->assertSame('requested', $payment->fresh()->status);
                }
            }
        }
    }

    public function test_the_opening_balance_keeps_a_valuation_and_outstanding_per_product(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $opening = $this->workflow()->recordOpeningBalance($user, [
            'as_of_date' => '2026-01-01', 'packs' => [$this->bottle->id => 960], 'outstanding' => [$this->bottle->id => 710, $this->sachet->id => 845],
        ]);

        $lines = $opening->lines->keyBy('product_id');
        $this->assertSame([960, 2000, 710], [$lines[$this->bottle->id]->packs, $lines[$this->bottle->id]->unit_cost, $lines[$this->bottle->id]->outstanding_packs]);
        $this->assertSame([0, 0, 845], [$lines[$this->sachet->id]->packs, $lines[$this->sachet->id]->unit_cost, $lines[$this->sachet->id]->outstanding_packs]);
        $this->assertSame(1, GwStockMovement::query()->count(), 'only packs brought forward enter the ledger');
    }

    public function test_every_new_write_leaves_an_audit_row(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $this->trip($user, 'WB-1', 50);

        $damage = $this->workflow()->recordDamage($user, $this->damageData(2));
        $expense = $this->workflow()->recordExpense($user, ['expense_date' => '2026-06-01', 'category' => 'other', 'amount' => 1000]);
        $payment = $this->workflow()->recordPayment($user, ['request_date' => '2026-06-04', 'reference' => 'PR-1', 'amount' => 5000]);
        $this->workflow()->markPaymentPaid($payment, $user, '2026-06-05');
        $this->workflow()->voidDamage($damage, $user, 'Mistake');
        $this->workflow()->voidExpense($expense, $user, 'Mistake');
        $this->workflow()->voidPayment($payment, $user, 'Mistake');

        $this->assertSame([
            'gwater.purchase_recorded', 'gwater.damage_recorded', 'gwater.expense_recorded', 'gwater.payment_recorded', 'gwater.payment_paid',
            'gwater.damage_voided', 'gwater.expense_voided', 'gwater.payment_voided',
        ], AuditLog::query()->where('module', 'gwater')->orderBy('id')->pluck('action')->all());

        $row = AuditLog::query()->where('action', 'gwater.damage_recorded')->firstOrFail();
        $this->assertSame([$user->id, 'gw_damages', $damage->id, 4000], [$row->user_id, $row->target_type, $row->target_id, $row->metadata['write_off']]);
    }

    public function test_the_new_writes_need_their_permissions(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 50);
        $damage = $this->workflow()->recordDamage($user, $this->damageData(2));
        $viewer = $this->person('E1', $this->sowutuom);
        $this->actingAs($viewer);

        foreach ([
            fn () => $this->workflow()->recordDamage($viewer, $this->damageData(1)),
            fn () => $this->workflow()->recordExpense($viewer, ['expense_date' => '2026-06-01', 'category' => 'other', 'amount' => 100]),
            fn () => $this->workflow()->recordPayment($viewer, ['request_date' => '2026-06-04', 'reference' => 'X', 'amount' => 100]),
            fn () => $this->workflow()->voidDamage($damage, $viewer, 'No permission'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a 403.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    // ---------------------------------------------------------------- screens

    public function test_the_new_screens_record_list_void_and_stay_inside_the_users_location(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $kumasi = $this->gw('G2', $this->kumasi);
        $this->trip($accra, 'WB-ACC', 100, 0, '2026-10-01');
        $this->trip($kumasi, 'WB-KUM', 100, 0, '2026-10-01');

        Livewire::actingAs($accra)->test(Damages::class)
            ->set('form.damage_date', '2026-10-02')->set('form.packs', '4')->set('form.reason', 'Burst in the truck')
            ->call('save')->assertHasNoErrors()->assertSee('Burst in the truck');
        $damage = GwDamage::query()->firstOrFail();

        Livewire::actingAs($kumasi)->test(Damages::class)->assertDontSee('Burst in the truck');
        Livewire::actingAs($kumasi)->test(Damages::class)->call('openVoid', $damage->id)->assertNotFound();
        Livewire::actingAs($this->gw('G3', $this->headOffice))->test(Damages::class)->assertDontSee('Burst in the truck');

        Livewire::actingAs($accra)->test(Expenses::class)
            ->set('form.expense_date', '2026-10-02')->set('form.category', 'loading')->set('form.amount', '35.50')->set('form.receipt_ref', 'RCPT-77')
            ->call('save')->assertHasNoErrors()->assertSee('RCPT-77')->assertSee('35.50');
        Livewire::actingAs($kumasi)->test(Expenses::class)->assertDontSee('RCPT-77');
        $this->assertSame(3550, GwExpense::query()->firstOrFail()->amount);

        Livewire::actingAs($accra)->test(Payments::class)
            ->set('form.request_date', '2026-10-03')->set('form.reference', 'PR-77')->set('form.amount', '1430.00')
            ->call('save')->assertHasNoErrors()->assertSee('PR-77')->assertSee('1,430.00');
        Livewire::actingAs($kumasi)->test(Payments::class)->assertDontSee('PR-77');

        $payment = GwSupplierPayment::query()->firstOrFail();
        Livewire::actingAs($accra)->test(Payments::class)->call('openPay', $payment->id)->set('paidOn', '2026-10-04')->call('pay')->assertHasNoErrors();
        $this->assertSame('paid', $payment->fresh()->status);
        Livewire::actingAs($kumasi)->test(Payments::class)->call('openVoid', $payment->id)->assertNotFound();

        Livewire::actingAs($accra)->test(Damages::class)->call('openVoid', $damage->id)->set('voidReason', 'Counted twice')->call('confirmVoid')->assertHasNoErrors();
        $this->assertSame('voided', $damage->fresh()->status);
    }

    public function test_the_new_routes_need_the_module_and_the_view_permission(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $plain = $this->person('E1', $this->sowutuom);

        foreach (['gwater.damages', 'gwater.expenses', 'gwater.payments'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
            $this->assertContains($this->actingAs($plain)->get(route($route))->getStatusCode(), [302, 403], $route);
        }
    }
}
