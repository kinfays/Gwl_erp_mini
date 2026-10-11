<?php

namespace Tests\Feature\GWater;

use App\Models\GwPrice;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Models\GwStockMovement;
use App\Services\GWater\GwaterStockService;
use App\Services\GWater\GwaterVisibility;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every location keeps its own books: Head Office, or a region WITHOUT Head Office (Head Office shares Accra West's region id).
 */
class GwaterLocationTest extends GwaterTestCase
{
    private function stockOf($district, $product = null): int
    {
        return app(GwaterStockService::class)->balance(['region_id' => $district->region_id, 'district_id' => $district->id], ($product ?? $this->bottle)->id);
    }

    public function test_a_row_of_another_region_answers_not_found_for_every_action(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $kumasi = $this->gw('G2', $this->kumasi);
        $trip = $this->trip($accra, 'WB-1', 100);
        $sale = $this->workflow()->recordSale($accra, $this->saleData(10));
        $this->actingAs($kumasi);

        foreach ([
            fn () => $this->workflow()->voidSale($sale, $kumasi, 'Not mine to void'),
            fn () => $this->workflow()->voidPurchase($trip, $kumasi, 'Not mine to void'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Another region\'s record must not be reachable.');
            } catch (NotFoundHttpException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame('posted', $sale->fresh()->status);
        $this->assertSame(0, $this->stockOf($this->kumasi));
        $this->assertSame(90, $this->stockOf($this->sowutuom));

        $visibility = app(GwaterVisibility::class);
        $this->assertFalse($visibility->contains($kumasi, $sale));
        $this->assertTrue($visibility->contains($accra, $sale));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $visibility->find($kumasi, GwSale::class, $sale->id);
    }

    public function test_head_office_and_its_region_are_separate_books_although_they_share_a_region_id(): void
    {
        $regional = $this->gw('G1', $this->sowutuom);
        $headOffice = $this->gw('G3', $this->headOffice);
        $this->assertSame($this->accraWest->id, $headOffice->employee->region_id);

        $regionalTrip = $this->trip($regional, 'WB-1', 100);
        $hoTrip = $this->trip($headOffice, 'WB-9', 40);
        $hoSale = $this->workflow()->recordSale($headOffice, $this->saleData(10));

        // Stock is counted separately.
        $this->assertSame(100, $this->stockOf($this->sowutuom));
        $this->assertSame(30, $this->stockOf($this->headOffice));

        // Head Office cannot sell the region's packs, and the region cannot sell Head Office's.
        try {
            $this->workflow()->recordSale($headOffice, $this->saleData(31));
            $this->fail('Head Office has only 30 packs of its own.');
        } catch (ValidationException) {
            $this->assertSame(30, $this->stockOf($this->headOffice));
        }

        // Neither sees nor can void the other's records.
        $visibility = app(GwaterVisibility::class);
        $this->assertSame([$regionalTrip->id], $visibility->scope(GwPurchase::query(), $regional)->pluck('id')->all());
        $this->assertSame([$hoTrip->id], $visibility->scope(GwPurchase::query(), $headOffice)->pluck('id')->all());
        $this->assertFalse($visibility->contains($regional, $hoSale));

        $this->actingAs($regional);
        $this->expectException(NotFoundHttpException::class);
        $this->workflow()->voidSale($hoSale, $regional, 'Wrong books');
    }

    public function test_the_districts_of_one_region_share_their_books_and_stock(): void
    {
        $sowutuom = $this->gw('G1', $this->sowutuom);
        $odorkor = $this->gw('G4', $this->odorkor);

        $this->trip($sowutuom, 'WB-1', 100);
        $this->actingAs($odorkor);
        $sale = $this->workflow()->recordSale($odorkor, $this->saleData(20));

        $this->assertSame(80, $this->stockOf($this->sowutuom));
        $this->assertSame(80, $this->stockOf($this->odorkor));
        $this->workflow()->voidSale($sale, $sowutuom, 'Colleague corrected it');
        $this->assertSame(100, $this->stockOf($this->odorkor));
    }

    public function test_prices_of_one_location_are_not_visible_or_effective_in_another(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $this->actingAs($accra);
        $own = $this->workflow()->addPrice($accra, ['product_id' => $this->bottle->id, 'effective_from' => '2026-07-01', 'selling_price' => 2800, 'cost_price' => 2200]);

        $kumasi = $this->gw('G2', $this->kumasi);
        $visibility = app(GwaterVisibility::class);

        $this->assertTrue($visibility->contains($accra, $own));
        $this->assertFalse($visibility->contains($kumasi, $own));
        // Both can still see the placeholder rows? No: those belong to nobody, and are read through the pricing service.
        $this->assertSame(0, $visibility->scope(GwPrice::query()->whereNull('region_id'), $kumasi)->count());
    }

    public function test_a_login_without_an_employee_record_has_no_books(): void
    {
        $homeless = $this->homeless('H1', ['g_water']);
        $this->actingAs($homeless);

        $this->assertFalse(app(GwaterVisibility::class)->hasLocation($homeless));
        $this->expectException(NotFoundHttpException::class);
        $this->workflow()->recordSale($homeless, $this->saleData(1));
    }

    public function test_super_admin_chooses_the_books_to_record_into_and_reads_every_location(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $this->trip($accra, 'WB-1', 100);
        $super = $this->superAdmin();
        $this->actingAs($super);

        try {
            $this->workflow()->recordSale($super, $this->saleData(5));
            $this->fail('super_admin has no location of their own and must choose one.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('location', $exception->errors());
        }

        $sale = $this->workflow()->recordSale($super, $this->saleData(5) + ['location_district_id' => $this->odorkor->id]);
        $this->assertSame([$this->accraWest->id, $this->odorkor->id], [$sale->region_id, $sale->district_id]);
        $this->assertSame(95, $this->stockOf($this->sowutuom));

        $visibility = app(GwaterVisibility::class);
        $this->assertTrue($visibility->contains($super, $sale));
        $this->assertSame(1, $visibility->scope(GwSale::query(), $super)->count());
        $this->workflow()->voidSale($sale, $super, 'Checking the audit trail');
        $this->assertSame(100, $this->stockOf($this->sowutuom));
    }

    public function test_waybill_numbers_are_unique_per_location_among_posted_trips(): void
    {
        $accra = $this->gw('G1', $this->sowutuom);
        $kumasi = $this->gw('G2', $this->kumasi);
        $headOffice = $this->gw('G3', $this->headOffice);

        $first = $this->trip($accra, 'WB-100');

        try {
            $this->trip($accra, 'wb-100', 5);
            $this->fail('The same waybill twice in one location must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('01 Jun 2026', $exception->errors()['waybill_no'][0]);
        }

        // Another location, and Head Office, may use the same number.
        $this->trip($kumasi, 'WB-100');
        $this->trip($headOffice, 'WB-100');

        // A voided trip frees its number.
        $this->actingAs($accra);
        $this->workflow()->voidPurchase($first, $accra, 'Entered twice');
        $again = $this->trip($accra, 'WB-100', 7);

        $this->assertSame(7, $again->totalPacks());
        $this->assertSame(4, GwPurchase::query()->count());
        $this->assertSame(7, $this->stockOf($this->sowutuom));
    }

    public function test_a_waybill_is_required_and_at_least_one_product_must_be_bought(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);

        foreach ([
            ['waybill_no' => '  ', 'lines' => [['product_id' => $this->bottle->id, 'packs' => 5]]],
            ['waybill_no' => str_repeat('x', 31), 'lines' => [['product_id' => $this->bottle->id, 'packs' => 5]]],
            ['waybill_no' => 'WB-1', 'lines' => [['product_id' => $this->bottle->id, 'packs' => 0]]],
            ['waybill_no' => 'WB-1', 'lines' => []],
        ] as $bad) {
            try {
                $this->workflow()->recordPurchase($user, ['purchase_date' => '2026-06-01', ...$bad]);
                $this->fail('Expected a validation error.');
            } catch (ValidationException) {
                $this->assertSame(0, GwPurchase::query()->count());
            }
        }
    }

    public function test_permissions_decide_who_may_record_void_and_view(): void
    {
        $viewer = $this->person('E1', $this->sowutuom);
        $this->actingAs($viewer);

        foreach ([
            fn () => $this->workflow()->recordSale($viewer, $this->saleData(1)),
            fn () => $this->workflow()->recordPurchase($viewer, ['waybill_no' => 'X', 'lines' => []]),
            fn () => $this->workflow()->recordOpeningBalance($viewer, ['packs' => [$this->bottle->id => 1]]),
            fn () => $this->workflow()->adjustStock($viewer, ['product_id' => $this->bottle->id, 'packs' => 1, 'reason' => 'x y z']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A user without gwater.record must be refused.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertSame(0, GwStockMovement::query()->count());
    }
}
