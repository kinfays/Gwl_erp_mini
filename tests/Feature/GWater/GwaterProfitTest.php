<?php

namespace Tests\Feature\GWater;

use App\Models\User;
use App\Models\VehicleExpense;
use App\Services\GWater\GwaterProfitService;
use Illuminate\Support\Carbon;

/**
 * GwaterProfitService against a hand-worked month, in pesewas. The clock is fixed at 11 Oct 2026, so October is "in progress".
 *
 * October at Sowutuom (Accra West), with the location's own prices: bottle sells 25.00 / costs 20.00 until 7 Oct, then 27.00 / 24.00.
 *   1 Oct  trip T1: 100 bottle @ 20.00, 60 sachet @ 8.00; Transport fuel 100.00 (100 km run); loading 30.00; offloading 20.00
 *   3 Oct  sell 40 bottle @ 25.00 (cost 20.00 each)
 *   4 Oct  other expense 25.00
 *   5 Oct  sell 20 sachet @ 10.00 (cost 8.00 each)
 *   7 Oct  damage 10 bottle in transit (written off at 20.00)
 *   9 Oct  trip T2: 50 bottle @ 24.00; G-Water fuel 80.00; loading 10.00
 *  10 Oct  sell 60 bottle @ 27.00 (average cost (50 x 20.00 + 50 x 24.00) / 100 = 22.00 each)
 *
 *   Revenue 2,820.00 = 1,000 + 1,620 (bottle 2,620.00) + 200 (sachet)       Cost of water sold 2,280.00
 *   Gross profit 540.00     Damages 200.00     Fuel 180.00     Labour 60.00     Other 25.00     Net profit 75.00
 */
class GwaterProfitTest extends GwaterTestCase
{
    private User $user;

    private array $place;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->gw('G1', $this->sowutuom);
        $this->place = ['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id];
        $this->actingAs($this->user);
    }

    private function profit(): GwaterProfitService
    {
        return app(GwaterProfitService::class);
    }

    private function october(?User $actor = null, ?array $place = null): array
    {
        return $this->profit()->report($place ?? $this->place, '2026-10-01', '2026-10-31', $actor ?? $this->user);
    }

    /** Records the whole month above. */
    private function workedMonth(): array
    {
        $w = $this->workflow();
        $vehicle = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $fuel = $this->expense($vehicle, ['expense_type' => 'fuel', 'amount' => 100, 'expense_date' => '2026-10-01']);
        $log = $this->mileageLog($vehicle, ['mileage_before' => 1000, 'mileage_after' => 1100, 'trip_date' => '2026-10-01']);

        $w->addPrice($this->user, ['product_id' => $this->bottle->id, 'effective_from' => '2026-09-01', 'selling_price' => 2500, 'cost_price' => 2000]);
        $w->addPrice($this->user, ['product_id' => $this->sachet->id, 'effective_from' => '2026-09-01', 'selling_price' => 1000, 'cost_price' => 800]);

        $t1 = $w->recordPurchase($this->user, [
            'purchase_date' => '2026-10-01', 'waybill_no' => 'T1',
            'lines' => [['product_id' => $this->bottle->id, 'packs' => 100], ['product_id' => $this->sachet->id, 'packs' => 60]],
            'vehicle_id' => $vehicle->id, 'mileage_log_id' => $log->id, 'vehicle_expense_id' => $fuel->id,
            'loading_amount' => 3000, 'offloading_amount' => 2000,
        ]);
        $w->recordSale($this->user, $this->saleData(40, '2026-10-03'));
        $w->recordExpense($this->user, ['expense_date' => '2026-10-04', 'category' => 'other', 'amount' => 2500]);
        $w->recordSale($this->user, $this->saleData(20, '2026-10-05', $this->sachet));
        $w->recordDamage($this->user, ['damage_date' => '2026-10-07', 'product_id' => $this->bottle->id, 'packs' => 10, 'stage' => 'transit', 'reason' => 'Burst']);

        // The price change comes after the earlier sales were recorded and must not touch them.
        $w->addPrice($this->user, ['product_id' => $this->bottle->id, 'effective_from' => '2026-10-08', 'selling_price' => 2700, 'cost_price' => 2400]);

        $t2 = $w->recordPurchase($this->user, [
            'purchase_date' => '2026-10-09', 'waybill_no' => 'T2',
            'lines' => [['product_id' => $this->bottle->id, 'packs' => 50]],
            'fuel_amount' => 8000, 'loading_amount' => 1000,
        ]);
        $w->recordSale($this->user, $this->saleData(60, '2026-10-10'));

        return [$t1, $t2, $fuel];
    }

    public function test_the_profit_and_loss_matches_the_hand_worked_month(): void
    {
        $this->workedMonth();
        $report = $this->october();
        $all = $report['combined'];

        $this->assertSame([282000, 228000, 54000], [$all['revenue'], $all['cost_of_sales'], $all['gross_profit']]);
        $this->assertSame([20000, 18000, 4000, 2000, 6000, 2500], [$all['damages'], $all['fuel'], $all['loading'], $all['offloading'], $all['labour'], $all['other']]);
        $this->assertSame(7500, $all['net_profit']);

        $bottle = $report['products']['bottle'];
        $sachet = $report['products']['sachet'];
        $this->assertSame([262000, 212000, 50000, 100], [$bottle['revenue'], $bottle['cost_of_sales'], $bottle['gross_profit'], $bottle['packs_sold']]);
        $this->assertSame([20000, 16000, 4000, 20], [$sachet['revenue'], $sachet['cost_of_sales'], $sachet['gross_profit'], $sachet['packs_sold']]);
        $this->assertSame([20000, 10], [$bottle['damages'], $bottle['damaged_packs']]);
    }

    public function test_the_cost_of_water_sold_is_the_rolling_weighted_average_across_purchases_at_different_costs(): void
    {
        $this->workedMonth();
        $bottle = $this->october()['products']['bottle'];

        // 40 sold at 20.00; after the damage 50 packs at 20.00 remain; 50 more arrive at 24.00: average 22.00 for the next 60.
        $this->assertSame(40 * 2000 + 60 * 2200, $bottle['cost_of_sales']);
        $this->assertSame(2120, $bottle['average_cost']);
    }

    public function test_the_average_is_carried_over_from_earlier_periods_not_restarted(): void
    {
        $this->workedMonth();

        // Selling only on the 10th: the period starts after the first trip, but the cost is still the carried average.
        $report = $this->profit()->report($this->place, '2026-10-10', '2026-10-31', $this->user);

        $this->assertSame([162000, 132000], [$report['products']['bottle']['revenue'], $report['products']['bottle']['cost_of_sales']]);
    }

    public function test_a_price_change_in_the_month_never_rewrites_earlier_lines(): void
    {
        $this->workedMonth();
        $sales = \App\Models\GwSale::query()->where('product_id', $this->bottle->id)->orderBy('sale_date')->get();

        $this->assertSame([2500, 2700], $sales->pluck('unit_price')->all());
        $this->assertSame([100000, 162000], $sales->pluck('amount')->all());
        // The cost frozen on the second trip's line is the new one; the first trip's is untouched.
        $this->assertSame([2000, 2400], \App\Models\GwPurchaseLine::query()->where('product_id', $this->bottle->id)->orderBy('id')->pluck('unit_cost')->all());
    }

    public function test_the_ratios_and_measures_are_worked_from_the_sums(): void
    {
        $this->workedMonth();
        $report = $this->october();
        $all = $report['combined'];

        $this->assertSame([19.1, 2.7], [$all['gross_margin_pct'], $all['net_margin_pct']]);
        // Fixed costs 200 + 180 + 60 + 25 = 465.00 over 120 packs sold at 4.50 average profit: 104 packs to break even.
        $this->assertSame(104, $all['break_even_packs']);
        $this->assertSame([6.7, 0.0], [$report['products']['bottle']['damage_rate_pct'], $report['products']['sachet']['damage_rate_pct']]);
        $this->assertSame([2620, 1000], [$report['products']['bottle']['average_price'], $report['products']['sachet']['average_price']]);
    }

    public function test_each_trip_has_its_landed_cost_and_cost_per_pack_and_per_km(): void
    {
        $this->workedMonth();
        $trips = collect($this->october()['trips'])->keyBy('waybill');

        // T1: 160 packs bought for 2,480.00; fuel 100.00 (Transport), loading 30.00, offloading 20.00; 100 km run.
        $this->assertSame([160, 248000, 10000, 'Transport', 15000], [$trips['T1']['packs'], $trips['T1']['purchase_cost'], $trips['T1']['fuel'], $trips['T1']['fuel_source'], $trips['T1']['trip_cost']]);
        $this->assertSame([1644, 94, 100, 100, 150], [$trips['T1']['landed_cost_per_pack'], $trips['T1']['trip_cost_per_pack'], $trips['T1']['km'], $trips['T1']['fuel_per_km'], $trips['T1']['trip_cost_per_km']]);
        $this->assertSame('ACC-1', $trips['T1']['vehicle']);

        // T2: 50 packs for 1,200.00; G-Water fuel 80.00 and loading 10.00; no mileage entry linked.
        $this->assertSame([50, 120000, 8000, 'G-Water', 9000, 2580], [$trips['T2']['packs'], $trips['T2']['purchase_cost'], $trips['T2']['fuel'], $trips['T2']['fuel_source'], $trips['T2']['trip_cost'], $trips['T2']['landed_cost_per_pack']]);
        $this->assertSame([null, null, null], [$trips['T2']['km'], $trips['T2']['fuel_per_km'], $trips['T2']['trip_cost_per_km']]);
    }

    public function test_damages_and_trip_costs_reduce_profit(): void
    {
        $this->workedMonth();
        $report = $this->october();
        $combined = $report['combined'];

        $this->assertSame($combined['gross_profit'] - $combined['damages'] - $combined['fuel'] - $combined['labour'] - $combined['other'], $combined['net_profit']);

        // A further damage of 5 packs lowers net profit by exactly 5 x the cost frozen on it.
        $this->workflow()->recordDamage($this->user, ['damage_date' => '2026-10-10', 'product_id' => $this->bottle->id, 'packs' => 5, 'stage' => 'storage', 'reason' => 'Cracked']);
        $after = $this->october()['combined'];

        $this->assertSame($combined['net_profit'] - 5 * 2400, $after['net_profit']);
    }

    public function test_ratios_are_null_when_the_denominator_is_zero(): void
    {
        // A location with nothing recorded at all.
        $empty = $this->gw('G2', $this->kumasi);
        $report = $this->profit()->report(['region_id' => $this->ashanti->id, 'district_id' => $this->kumasi->id], '2026-10-01', '2026-10-31', $empty);
        $all = $report['combined'];

        $this->assertSame([0, 0, 0], [$all['revenue'], $all['net_profit'], $all['packs_sold']]);
        foreach (['gross_margin_pct', 'net_margin_pct', 'damage_rate_pct', 'average_price', 'average_cost'] as $key) {
            $this->assertNull($all[$key], $key);
        }
        // Nothing sold and no costs: nothing to break even on; with costs and no sales it cannot be worked out at all.
        $this->assertSame(0, $all['break_even_packs']);

        $this->actingAs($empty);
        $this->workflow()->recordExpense($empty, ['expense_date' => '2026-10-05', 'category' => 'other', 'amount' => 1000]);
        $this->assertNull($this->profit()->report(['region_id' => $this->ashanti->id, 'district_id' => $this->kumasi->id], '2026-10-01', '2026-10-31', $empty)['combined']['break_even_packs']);
    }

    public function test_the_current_period_is_labelled_in_progress_and_a_past_one_complete(): void
    {
        $this->assertTrue($this->october()['period']['in_progress']);
        $this->assertStringContainsString('In progress', $this->october()['period']['status']);

        $september = $this->profit()->report($this->place, '2026-09-01', '2026-09-30', $this->user);
        $this->assertFalse($september['period']['in_progress']);
        $this->assertSame('Complete', $september['period']['status']);
    }

    public function test_voided_entries_do_not_count(): void
    {
        $this->workedMonth();
        $sale = \App\Models\GwSale::query()->whereDate('sale_date', '2026-10-05')->firstOrFail();
        $this->workflow()->voidSale($sale, $this->user, 'Wrong day');

        $report = $this->october();

        $this->assertSame(262000, $report['combined']['revenue']);
        $this->assertSame(212000, $report['combined']['cost_of_sales']);
        $this->assertSame(0, $report['products']['sachet']['packs_sold']);
    }

    public function test_the_figures_are_never_stale_after_a_new_entry_or_a_change_in_transport(): void
    {
        [, , $fuel] = $this->workedMonth();
        $before = $this->october();

        // Cached: asking again gives the same payload.
        $this->assertSame($before, $this->october());

        // A new entry changes the next answer straight away.
        $this->workflow()->recordExpense($this->user, ['expense_date' => '2026-10-10', 'category' => 'other', 'amount' => 1000]);
        $this->assertSame($before['combined']['net_profit'] - 1000, $this->october()['combined']['net_profit']);

        // So does a change to the Transport fuel row a trip reads.
        $current = $this->october()['combined']['net_profit'];
        Carbon::setTestNow('2026-10-11 10:00:00');
        VehicleExpense::query()->whereKey($fuel->id)->update(['amount' => 120, 'updated_at' => now()]);
        $this->assertSame($current - 2000, $this->october()['combined']['net_profit']);
        $this->assertTrue(collect($this->october()['trips'])->firstWhere('waybill', 'T1')['changed_in_transport']);
    }

    public function test_months_series_adds_up_to_the_period(): void
    {
        $this->workedMonth();
        $report = $this->profit()->report($this->place, '2026-09-15', '2026-10-31', $this->user);

        $this->assertSame(['Sep 2026', 'Oct 2026'], $report['months']['labels']);
        $this->assertEquals(2820.00, array_sum($report['months']['revenue']));
        $this->assertEquals(75.00, array_sum($report['months']['net_profit']));
    }

    public function test_head_office_and_its_region_never_see_each_others_figures(): void
    {
        $this->workedMonth();
        $headOffice = $this->gw('G3', $this->headOffice);
        $hoPlace = ['region_id' => $this->headOffice->region_id, 'district_id' => $this->headOffice->id];
        $this->actingAs($headOffice);
        $this->trip($headOffice, 'HO-1', 20, 0, '2026-10-02');
        $this->workflow()->recordSale($headOffice, $this->saleData(5, '2026-10-03'));

        $region = $this->october($this->user, $this->place);
        $ho = $this->october($headOffice, $hoPlace);

        $this->assertSame(282000, $region['combined']['revenue']);
        $this->assertSame(5 * 2600, $ho['combined']['revenue'], 'Head Office sells at the placeholder price and has only its own sale');
        $this->assertNotSame($region['combined']['revenue'], $ho['combined']['revenue']);
        $this->assertSame(['HO-1'], array_column($ho['trips'], 'waybill'));
    }

    public function test_owed_to_g_water_is_the_one_in_the_report(): void
    {
        $this->workedMonth();

        // Both trips at their frozen cost: 2,480.00 + 1,200.00.
        $this->assertSame(368000, $this->october()['owed']);
        $this->assertSame(248000, $this->profit()->report($this->place, '2026-10-01', '2026-10-05', $this->user)['owed']);
    }
}
