<?php

namespace Tests\Feature\GWater;

use App\Models\GwPrice;
use App\Models\GwSale;
use App\Services\GWater\GwaterPricingService;
use App\Services\GWater\Money;
use Illuminate\Validation\ValidationException;

class GwaterPricingTest extends GwaterTestCase
{
    public function test_the_bottle_pack_sells_at_25_on_11_may_and_26_on_12_may_2026_with_cost_unchanged(): void
    {
        $pricing = app(GwaterPricingService::class);
        $place = ['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id];

        $this->assertSame(2500, $pricing->sellingPrice($this->bottle->id, '2026-05-11', $place));
        $this->assertSame(2600, $pricing->sellingPrice($this->bottle->id, '2026-05-12', $place));
        $this->assertSame(2600, $pricing->sellingPrice($this->bottle->id, '2026-12-31', $place));

        // The cost did not move, and the sachet pack kept its own prices throughout.
        $this->assertSame(2000, $pricing->costPrice($this->bottle->id, '2026-05-11', $place));
        $this->assertSame(2000, $pricing->costPrice($this->bottle->id, '2026-05-12', $place));
        $this->assertSame([1000, 800], [$pricing->sellingPrice($this->sachet->id, '2026-05-12', $place), $pricing->costPrice($this->sachet->id, '2026-05-12', $place)]);

        // Nothing is on the list before the first placeholder row.
        $this->assertNull($pricing->lookup($this->bottle->id, '2025-12-31', $place));
    }

    public function test_the_products_are_the_15_and_30_unit_packs(): void
    {
        $this->assertSame([15, 30], [$this->bottle->units_per_pack, $this->sachet->units_per_pack]);
    }

    public function test_each_sale_freezes_the_price_in_force_on_its_date(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $this->trip($user, 'WB-1', 500, 0, '2026-05-01');

        $before = $this->workflow()->recordSale($user, $this->saleData(10, '2026-05-11'));
        $after = $this->workflow()->recordSale($user, $this->saleData(10, '2026-05-12'));

        $this->assertSame([2500, 25000], [$before->unit_price, $before->amount]);
        $this->assertSame([2600, 26000], [$after->unit_price, $after->amount]);

        // The purchase line froze its cost the same way.
        $this->assertSame(2000, GwPrice::query()->where('product_id', $this->bottle->id)->whereNull('region_id')->orderBy('id')->value('cost_price'));
        $this->assertSame(2000, \App\Models\GwPurchaseLine::query()->value('unit_cost'));
    }

    public function test_a_locations_own_price_overrides_the_placeholder_from_its_date_only(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $pricing = app(GwaterPricingService::class);
        $place = ['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id];

        $this->workflow()->addPrice($user, [
            'product_id' => $this->bottle->id, 'effective_from' => '2026-07-01', 'selling_price' => 2800, 'cost_price' => 2200,
        ]);

        // The day before: still the placeholder. The day of: the location's own, both prices.
        $this->assertSame([2600, 2000], [$pricing->sellingPrice($this->bottle->id, '2026-06-30', $place), $pricing->costPrice($this->bottle->id, '2026-06-30', $place)]);
        $this->assertSame([2800, 2200], [$pricing->sellingPrice($this->bottle->id, '2026-07-01', $place), $pricing->costPrice($this->bottle->id, '2026-07-01', $place)]);

        // A product the location did not price still uses the placeholder.
        $this->assertSame(1000, $pricing->sellingPrice($this->sachet->id, '2026-07-01', $place));
    }

    public function test_locations_have_separate_price_lists_and_head_office_is_not_its_regions_list(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $this->actingAs($accra);
        $this->workflow()->addPrice($accra, ['product_id' => $this->bottle->id, 'effective_from' => '2026-07-01', 'selling_price' => 2800, 'cost_price' => 2200]);

        $pricing = app(GwaterPricingService::class);
        $at = fn ($district) => ['region_id' => $district->region_id, 'district_id' => $district->id];

        $this->assertSame(2800, $pricing->sellingPrice($this->bottle->id, '2026-07-02', $at($this->sowutuom)));
        // Another district of the same region shares the region's list...
        $this->assertSame(2800, $pricing->sellingPrice($this->bottle->id, '2026-07-02', $at($this->odorkor)));
        // ...Kumasi and Head Office (same region id as Accra West!) fall back to the placeholder.
        $this->assertSame(2600, $pricing->sellingPrice($this->bottle->id, '2026-07-02', $at($this->kumasi)));
        $this->assertSame(2600, $pricing->sellingPrice($this->bottle->id, '2026-07-02', $at($this->headOffice)));
    }

    public function test_a_price_dated_in_the_past_is_refused_when_posted_entries_would_be_re_priced(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $this->trip($user, 'WB-1', 100, 0, '2026-05-01');
        $this->workflow()->recordSale($user, $this->saleData(5, '2026-06-10'));

        try {
            $this->workflow()->addPrice($user, ['product_id' => $this->bottle->id, 'effective_from' => '2026-06-01', 'selling_price' => 3000, 'cost_price' => 2000]);
            $this->fail('A price that re-prices a posted sale must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('10 Jun 2026', $exception->errors()['effective_from'][0]);
        }

        // Dated after the entries, or equal to what they were priced at, it is fine.
        $this->workflow()->addPrice($user, ['product_id' => $this->bottle->id, 'effective_from' => '2026-06-11', 'selling_price' => 3000, 'cost_price' => 2000]);
        $this->workflow()->addPrice($user, ['product_id' => $this->bottle->id, 'effective_from' => '2026-05-15', 'selling_price' => 2600, 'cost_price' => 2000]);

        $this->assertSame(2, GwPrice::query()->whereNotNull('region_id')->count());
        $this->assertSame(2600, GwSale::query()->value('unit_price'));
    }

    public function test_only_super_admin_changes_the_placeholder_and_it_needs_manage_prices(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        try {
            $this->workflow()->addPrice($user, ['product_id' => $this->bottle->id, 'effective_from' => '2026-08-01', 'selling_price' => 2700, 'cost_price' => 2000, 'default' => true]);
            $this->fail('A location user must not change the system default.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        // No manage_prices permission (a plain employee) is refused outright.
        $plain = $this->person('E1', $this->sowutuom);
        $this->actingAs($plain);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->workflow()->addPrice($plain, ['product_id' => $this->bottle->id, 'effective_from' => '2026-08-01', 'selling_price' => 2700, 'cost_price' => 2000]);
    }

    public function test_super_admin_may_change_the_default_and_a_price_must_be_a_positive_amount(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super);

        $price = $this->workflow()->addPrice($super, ['product_id' => $this->sachet->id, 'effective_from' => '2026-09-01', 'selling_price' => 1200, 'cost_price' => 900, 'default' => true]);
        $this->assertTrue($price->isDefault());

        $this->expectException(ValidationException::class);
        $this->workflow()->addPrice($super, ['product_id' => $this->sachet->id, 'effective_from' => '2026-09-02', 'selling_price' => 0, 'cost_price' => 900, 'default' => true]);
    }

    public function test_money_is_typed_in_cedis_and_held_in_pesewas(): void
    {
        $this->assertSame(2600, Money::toPesewas('26'));
        $this->assertSame(2650, Money::toPesewas('26.5'));
        $this->assertSame(123450, Money::toPesewas('1,234.50'));
        $this->assertSame(5, Money::toPesewas('0.05'));

        foreach ([null, '', 'abc', '-5', '1.234', '1e3', true] as $bad) {
            $this->assertNull(Money::toPesewas($bad), var_export($bad, true));
        }

        $this->assertSame('1,234.50', Money::format(123450));
        $this->assertSame('26.00', Money::input(2600));
    }
}
