<?php

namespace Tests\Feature\GWater;

use App\Models\AuditLog;
use App\Models\GwOpeningBalance;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Models\GwStockMovement;
use App\Services\GWater\GwaterStockService;
use Illuminate\Validation\ValidationException;

class GwaterStockTest extends GwaterTestCase
{
    private function onHand($user, $product = null): int
    {
        $place = app(\App\Services\GWater\GwaterVisibility::class)->ownLocation($user);

        return app(GwaterStockService::class)->balance($place, ($product ?? $this->bottle)->id);
    }

    public function test_a_purchase_adds_packs_and_a_sale_takes_them_away(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 100, 40);
        $this->workflow()->recordSale($user, $this->saleData(30));

        $this->assertSame(70, $this->onHand($user));
        $this->assertSame(40, $this->onHand($user, $this->sachet));
        $this->assertSame([100, -30], GwStockMovement::query()->where('product_id', $this->bottle->id)->orderBy('id')->pluck('packs')->all());
    }

    public function test_stock_can_never_go_negative_and_a_refused_sale_leaves_nothing_behind(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 10);

        try {
            $this->workflow()->recordSale($user, $this->saleData(11));
            $this->fail('Selling more than is in stock must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Not enough', $exception->errors()['packs'][0]);
        }

        $this->assertSame(0, GwSale::query()->count(), 'the refused sale must roll back with its stock line');
        $this->assertSame(10, $this->onHand($user));
        $this->assertSame(0, AuditLog::query()->where('action', 'gwater.sale_recorded')->count());

        // Exactly the stock on hand is fine.
        $this->workflow()->recordSale($user, $this->saleData(10));
        $this->assertSame(0, $this->onHand($user));
    }

    public function test_a_sale_cannot_be_backdated_to_a_day_the_shelf_was_empty(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 50, 0, '2026-06-10');

        // 50 packs exist today, but none existed on 1 June.
        $this->expectException(ValidationException::class);
        $this->workflow()->recordSale($user, $this->saleData(5, '2026-06-01'));
    }

    public function test_voiding_a_sale_puts_the_packs_back_and_keeps_the_row(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 100);
        $sale = $this->workflow()->recordSale($user, $this->saleData(30));

        $voided = $this->workflow()->voidSale($sale, $user, 'Entered on the wrong day');

        $this->assertSame(100, $this->onHand($user));
        $this->assertSame(['voided', 'Entered on the wrong day', $user->id], [$voided->status, $voided->void_reason, $voided->voided_by]);
        $this->assertSame(1, GwSale::query()->count(), 'nothing is deleted');
        // The ledger kept both lines and they cancel: it is reversed, never edited.
        $this->assertSame(0, (int) GwStockMovement::query()->where('source_type', 'gw_sale')->where('source_id', $sale->id)->sum('packs'));
        $this->assertSame(2, GwStockMovement::query()->where('source_type', 'gw_sale')->count());

        // Voiding twice is refused.
        $this->expectException(ValidationException::class);
        $this->workflow()->voidSale($sale, $user, 'Again');
    }

    public function test_a_void_needs_a_reason_and_the_void_permission(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 100);
        $sale = $this->workflow()->recordSale($user, $this->saleData(5));

        try {
            $this->workflow()->voidSale($sale, $user, '  ');
            $this->fail('A reason is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $plain = $this->person('E1', $this->sowutuom);
        $this->actingAs($plain);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->workflow()->voidSale($sale, $plain, 'No permission');
    }

    public function test_voiding_a_trip_takes_its_packs_back_out_unless_they_are_already_sold(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $trip = $this->trip($user, 'WB-1', 100, 20, '2026-06-01', ['loading_amount' => 5000]);
        $this->workflow()->recordSale($user, $this->saleData(70));

        try {
            $this->workflow()->voidPurchase($trip, $user, 'Wrong waybill');
            $this->fail('Packs that were already sold cannot be taken back out.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Not enough', $exception->errors()['packs'][0]);
        }

        $this->assertSame('posted', $trip->fresh()->status);
        $this->assertSame(30, $this->onHand($user));

        // Void the sale first, then the trip: stock is back to zero and its cash costs are voided with it.
        $this->workflow()->voidSale(GwSale::query()->firstOrFail(), $user, 'Wrong waybill');
        $this->workflow()->voidPurchase($trip, $user, 'Wrong waybill');

        $this->assertSame([0, 0], [$this->onHand($user), $this->onHand($user, $this->sachet)]);
        $this->assertSame(['voided'], \App\Models\GwExpense::query()->pluck('status')->unique()->all());
    }

    public function test_corrections_are_adjustment_rows_with_a_reason_and_never_go_below_zero(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 10);

        $this->workflow()->adjustStock($user, ['product_id' => $this->bottle->id, 'packs' => 5, 'movement_date' => '2026-06-05', 'reason' => 'Found in the store']);
        $this->workflow()->adjustStock($user, ['product_id' => $this->bottle->id, 'packs' => -3, 'movement_date' => '2026-06-06', 'reason' => 'Counted short']);
        $this->assertSame(12, $this->onHand($user));
        $this->assertSame(['adjustment', 'adjustment'], GwStockMovement::query()->where('type', 'adjustment')->pluck('type')->all());

        foreach ([['packs' => -13, 'reason' => 'Too many'], ['packs' => 4, 'reason' => ''], ['packs' => 0, 'reason' => 'Nothing']] as $bad) {
            try {
                $this->workflow()->adjustStock($user, ['product_id' => $this->bottle->id, 'movement_date' => '2026-06-07', ...$bad]);
                $this->fail('Expected a refusal for '.json_encode($bad));
            } catch (ValidationException) {
                $this->assertSame(12, $this->onHand($user));
            }
        }
    }

    public function test_the_ledger_is_immutable(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->trip($user, 'WB-1', 10);
        $movement = GwStockMovement::query()->firstOrFail();

        foreach ([fn () => $movement->update(['packs' => 99]), fn () => $movement->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('A ledger row must not be changed or deleted.');
            } catch (\LogicException) {
                $this->assertSame(10, $movement->fresh()->packs);
            }
        }
    }

    public function test_the_opening_balance_posts_once_and_its_stock_is_in_the_ledger(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        $opening = $this->workflow()->recordOpeningBalance($user, [
            'as_of_date' => '2026-01-01',
            'packs' => [$this->bottle->id => 960, $this->sachet->id => 1365],
            'owed_amount' => 1430000,
        ]);

        $this->assertSame([960, 1365], [$this->onHand($user), $this->onHand($user, $this->sachet)]);
        $this->assertSame(['opening', 'opening'], GwStockMovement::query()->pluck('type')->all());
        $this->assertSame(1430000, $opening->owed_amount);

        // Posting it again, even from a colleague of the same location, is refused.
        $colleague = $this->gw('G2', $this->odorkor);
        $this->actingAs($colleague);

        try {
            $this->workflow()->recordOpeningBalance($colleague, ['as_of_date' => '2026-01-02', 'packs' => [$this->bottle->id => 5]]);
            $this->fail('The opening balance posts once.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('as_of_date', $exception->errors());
        }

        $this->assertSame(1, GwOpeningBalance::query()->count());
        $this->assertSame(960, $this->onHand($user));

        // Another location has its own, even Head Office, which shares the region id.
        $headOffice = $this->gw('G3', $this->headOffice);
        $this->actingAs($headOffice);
        $this->workflow()->recordOpeningBalance($headOffice, ['as_of_date' => '2026-01-01', 'packs' => [$this->bottle->id => 7]]);
        $this->assertSame(2, GwOpeningBalance::query()->count());
        $this->assertSame(960, $this->onHand($user));
    }

    public function test_a_voided_opening_balance_can_be_posted_again_unless_its_stock_was_used(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $opening = $this->workflow()->recordOpeningBalance($user, ['as_of_date' => '2026-01-01', 'packs' => [$this->bottle->id => 100]]);
        $this->workflow()->recordSale($user, $this->saleData(60, '2026-02-01'));

        try {
            $this->workflow()->voidOpeningBalance($opening, $user, 'Wrong count');
            $this->fail('Stock that was sold cannot be reversed away.');
        } catch (ValidationException) {
            $this->assertSame(40, $this->onHand($user));
        }

        $this->workflow()->voidSale(GwSale::query()->firstOrFail(), $user, 'Wrong count');
        $this->workflow()->voidOpeningBalance($opening, $user, 'Wrong count');
        $this->assertSame(0, $this->onHand($user));

        $again = $this->workflow()->recordOpeningBalance($user, ['as_of_date' => '2026-01-01', 'packs' => [$this->bottle->id => 90]]);
        $this->assertSame(90, $this->onHand($user));
        $this->assertSame(2, GwOpeningBalance::query()->count());
        $this->assertSame('posted', $again->status);
    }

    public function test_every_write_leaves_an_audit_row(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        $this->workflow()->recordOpeningBalance($user, ['as_of_date' => '2026-01-01', 'packs' => [$this->bottle->id => 10]]);
        $trip = $this->trip($user, 'WB-1', 100);
        $sale = $this->workflow()->recordSale($user, $this->saleData(5));
        $this->workflow()->addPrice($user, ['product_id' => $this->bottle->id, 'effective_from' => '2026-10-01', 'selling_price' => 2900, 'cost_price' => 2100]);
        $this->workflow()->adjustStock($user, ['product_id' => $this->bottle->id, 'packs' => 1, 'movement_date' => '2026-06-05', 'reason' => 'Found']);
        $this->workflow()->voidSale($sale, $user, 'Mistake');
        $this->workflow()->voidPurchase($trip, $user, 'Mistake');

        $actions = AuditLog::query()->where('module', 'gwater')->orderBy('id')->pluck('action')->all();

        $this->assertSame([
            'gwater.opening_balance_recorded', 'gwater.purchase_recorded', 'gwater.sale_recorded', 'gwater.price_added',
            'gwater.stock_adjusted', 'gwater.sale_voided', 'gwater.purchase_voided',
        ], $actions);

        $row = AuditLog::query()->where('action', 'gwater.sale_voided')->firstOrFail();
        $this->assertSame([$user->id, 'gw_sales', $sale->id], [$row->user_id, $row->target_type, $row->target_id]);
        $this->assertSame('Mistake', $row->metadata['reason']);
        $this->assertSame($this->accraWest->id, $row->metadata['region_id']);
    }
}
