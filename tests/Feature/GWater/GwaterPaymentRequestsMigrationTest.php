<?php

namespace Tests\Feature\GWater;

use App\Models\GwReminderState;
use App\Models\GwSetting;
use App\Models\GwSupplierPayment;
use App\Services\GWater\GwaterSummaryService;
use Illuminate\Support\Facades\DB;

/** The migration that retires payment requests leaves the owed balance exactly where it was. */
class GwaterPaymentRequestsMigrationTest extends GwaterTestCase
{
    public function test_requested_payments_are_voided_and_the_balance_does_not_move(): void
    {
        $user = $this->gw('G1', $this->sowutuom);
        $this->actingAs($user);
        $place = ['region_id' => $this->accraWest->id, 'district_id' => $this->sowutuom->id];
        $this->trip($user, 'WB-1', 40, 0, '2026-06-01');                     // 40 bottle packs @ 20.00 = 800.00 owed
        $paid = $this->workflow()->recordPayment($user, ['paid_on' => '2026-06-05', 'reference' => 'PAY-1', 'amount' => 30000]);

        // What the old flow could leave behind: a request nobody paid, an overdue setting and its reminder row.
        $requested = GwSupplierPayment::query()->create([
            ...$place, 'request_date' => '2026-06-02', 'reference' => 'PR-OLD', 'amount' => 24000, 'status' => 'requested', 'created_by' => $user->id,
        ]);
        GwSetting::query()->create([...$place, 'key' => 'payment_overdue_days', 'value' => 14]);
        GwReminderState::query()->create([...$place, 'kind' => 'payment_overdue', 'subject_id' => $requested->id, 'last_reminded_at' => now()]);

        $summary = app(GwaterSummaryService::class);
        $owedBefore = $summary->owedToGwater($place);
        $this->assertSame(50000, $owedBefore, '800.00 - 300.00; the request never counted');

        (require base_path('database/migrations/2026_10_22_000001_remove_gwater_payment_requests.php'))->up();

        $this->assertSame('voided', $requested->fresh()->status);
        $this->assertStringContainsString('removed', $requested->fresh()->void_reason);
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame($owedBefore, $summary->owedToGwater($place));
        $this->assertSame(0, GwSetting::query()->where('key', 'payment_overdue_days')->count());
        $this->assertSame(0, GwReminderState::query()->where('kind', 'payment_overdue')->count());
        $this->assertSame(0, DB::table('gw_supplier_payments')->where('status', 'requested')->count());
    }
}
