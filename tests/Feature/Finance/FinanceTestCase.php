<?php

namespace Tests\Feature\Finance;

use App\Models\FinPaymentVoucher;
use App\Models\FinPvType;
use App\Models\User;
use App\Services\Finance\PaymentVoucherWorkflowService;
use Database\Seeders\FinanceAuditRolePermissionSeeder;
use Illuminate\Support\Carbon;
use Tests\Feature\Transport\TransportTestCase;

/**
 * Fixtures for the Finance / Audit tests. Reuses the Transport ones (two regions; Head Office a district that SHARES Accra
 * West's region id; people in a place), plus the finance_officer / audit_officer grants. The catalogue (Staff Claims,
 * Operations Activities) comes from the migration, exactly as on a real install. The clock is fixed.
 */
abstract class FinanceTestCase extends TransportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-07 09:00:00');
        $this->seed(FinanceAuditRolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function workflow(): PaymentVoucherWorkflowService
    {
        return app(PaymentVoucherWorkflowService::class);
    }

    protected function finance(string $staffId, \App\Models\District $district): User
    {
        return $this->person($staffId, $district, ['employee', 'finance_officer']);
    }

    protected function audit(string $staffId, \App\Models\District $district): User
    {
        return $this->person($staffId, $district, ['employee', 'audit_officer']);
    }

    protected function both(string $staffId, \App\Models\District $district): User
    {
        return $this->person($staffId, $district, ['employee', 'finance_officer', 'audit_officer']);
    }

    /** @return array<string, mixed> */
    protected function pvData(array $overrides = []): array
    {
        $type = FinPvType::query()->where('name', 'Medical')->firstOrFail();

        return [
            'pv_date' => '2026-11-02',
            'pv_no' => 'PV-001',
            'payee_kind' => 'district',
            'payee_district_id' => $this->kumasi->id,
            'category_id' => $type->category_id,
            'type_id' => $type->id,
            'details' => 'Medical claim for the third quarter',
            'amount' => '1250.50',
            'remarks' => 'Finance own note',
            ...$overrides,
        ];
    }

    /** A draft prepared by the user. */
    protected function draft(User $user, array $overrides = []): FinPaymentVoucher
    {
        $this->actingAs($user);

        return $this->workflow()->createDraft($user, $this->pvData($overrides));
    }

    /** A voucher the user has sent to Audit (status With Audit, round 1). */
    protected function sent(User $user, array $overrides = []): FinPaymentVoucher
    {
        $pv = $this->draft($user, $overrides);

        return $this->workflow()->submit($user, $pv, $pv->lock_version);
    }
}
