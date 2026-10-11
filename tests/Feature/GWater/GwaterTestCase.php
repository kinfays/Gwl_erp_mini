<?php

namespace Tests\Feature\GWater;

use App\Models\GwProduct;
use App\Models\GwPurchase;
use App\Models\User;
use App\Services\GWater\GwaterWorkflowService;
use Database\Seeders\GwaterRolePermissionSeeder;
use Illuminate\Support\Carbon;
use Tests\Feature\Transport\TransportTestCase;

/**
 * Fixtures for the G-Water tests. Reuses the Transport ones (two regions; Head Office a district that SHARES Accra West's
 * region id; people and vehicles in a place), plus the G-Water role/permission seed. The module's products and the placeholder
 * price list come from the migration, exactly as on a real install. The clock is fixed so "today" is the same in every run.
 */
abstract class GwaterTestCase extends TransportTestCase
{
    protected GwProduct $bottle;

    protected GwProduct $sachet;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-11 09:00:00');
        $this->seed(GwaterRolePermissionSeeder::class);

        $this->bottle = GwProduct::query()->where('code', 'bottle')->firstOrFail();
        $this->sachet = GwProduct::query()->where('code', 'sachet')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function workflow(): GwaterWorkflowService
    {
        return app(GwaterWorkflowService::class);
    }

    /** A G-Water user (role g_water) based in the district. */
    protected function gw(string $staffId, \App\Models\District $district): User
    {
        return $this->person($staffId, $district, ['employee', 'g_water']);
    }

    /**
     * Records a trip of bottle (and sachet) packs as the user.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function trip(User $user, string $waybill = 'WB-1', int $bottle = 100, int $sachet = 0, string $date = '2026-06-01', array $extra = []): GwPurchase
    {
        $this->actingAs($user);

        return $this->workflow()->recordPurchase($user, [
            'purchase_date' => $date,
            'waybill_no' => $waybill,
            'lines' => [
                ['product_id' => $this->bottle->id, 'packs' => $bottle],
                ['product_id' => $this->sachet->id, 'packs' => $sachet],
            ],
            ...$extra,
        ]);
    }

    /** @return array<string, mixed> */
    protected function saleData(int $packs, string $date = '2026-06-02', ?GwProduct $product = null): array
    {
        return ['sale_date' => $date, 'product_id' => ($product ?? $this->bottle)->id, 'packs' => $packs];
    }
}
