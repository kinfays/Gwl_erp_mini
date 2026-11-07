<?php

namespace Tests\Feature\Finance;

use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinSupplier;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\SupplierDirectory;
use App\Services\Finance\SupplierService;
use App\Services\GWater\Money;

/**
 * The supplier LIST is company-wide, but the figures beside a supplier (vouchers, total, last used) are worked out only through
 * FinanceScope: a location never sees what another location paid a supplier.
 */
class SupplierFiguresTest extends FinanceTestCase
{
    private function directory(): SupplierDirectory
    {
        return app(SupplierDirectory::class);
    }

    private function supplier(string $name, array $extra = []): FinSupplier
    {
        $super = $this->superAdmin('SUPPLIER-SEED');
        $this->actingAs($super);

        return app(SupplierService::class)->create($super, ['name' => $name] + $extra);
    }

    private function sentTo(FinSupplier $supplier, $by, string $no, string $amount, string $date = '2026-11-02'): Pv
    {
        return $this->sent($by, ['pv_no' => $no, 'payee_kind' => 'supplier', 'supplier_id' => $supplier->id, 'amount' => $amount, 'pv_date' => $date]);
    }

    public function test_the_list_is_company_wide_but_the_figures_are_each_locations_own(): void
    {
        $west = $this->finance('W1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $supplier = $this->supplier('Shared Supplies Ltd');

        $this->sentTo($supplier, $west, 'W-1', '100.00', '2026-10-05');
        $this->sentTo($supplier, $west, 'W-2', '50.00', '2026-11-01');
        $this->sentTo($supplier, $ash, 'A-1', '250.00', '2026-11-03');

        $this->assertSame([$supplier->id], $this->directory()->suppliers()->pluck('id')->all(), 'both locations read the same list');

        $figures = fn ($user, string $as = FinanceScope::AS_FINANCE) => $this->directory()->figures($user, $as, [$supplier->id])[$supplier->id] ?? null;

        $this->assertSame(['count' => 2, 'total' => 15000, 'last_date' => '2026-11-01'], $figures($west));
        $this->assertSame(['count' => 1, 'total' => 25000, 'last_date' => '2026-11-03'], $figures($ash));
        $this->assertSame(['count' => 3, 'total' => 40000, 'last_date' => '2026-11-03'], $figures($this->superAdmin()), 'super_admin reads every location');

        // Audit of the same location sees the same vouchers, still nothing from elsewhere.
        $this->assertSame(2, $figures($this->audit('AU1', $this->sowutuom), FinanceScope::AS_AUDIT)['count']);
        $this->assertSame([], $this->directory()->figures($this->finance('HO1', $this->headOffice), FinanceScope::AS_FINANCE, [$supplier->id]), 'Head Office is its own books');
    }

    public function test_drafts_and_voided_vouchers_are_not_counted_as_use(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $supplier = $this->supplier('Counting Co');

        $this->sentTo($supplier, $fin, 'SENT-1', '10.00');
        $this->draft($fin, ['pv_no' => 'DRAFT-1', 'payee_kind' => 'supplier', 'supplier_id' => $supplier->id, 'amount' => '999.00']);
        $voided = $this->sentTo($supplier, $fin, 'VOID-1', '500.00');
        $this->workflow()->void($fin, $voided, 'Entered twice');

        $this->assertSame(['count' => 1, 'total' => 1000, 'last_date' => '2026-11-02'], $this->directory()->figures($fin, FinanceScope::AS_FINANCE, [$supplier->id])[$supplier->id]);
        $this->assertSame(['SENT-1'], $this->directory()->recentVouchers($fin, FinanceScope::AS_FINANCE, $supplier->id)->pluck('pv_no')->all());
    }

    public function test_recent_vouchers_are_only_those_the_user_reaches_newest_first(): void
    {
        $west = $this->finance('W1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $supplier = $this->supplier('Recent Co');

        $this->sentTo($supplier, $west, 'OLD', '1.00', '2026-09-01');
        $this->sentTo($supplier, $west, 'NEW', '2.00', '2026-10-30');
        $this->sentTo($supplier, $ash, 'KUMASI', '3.00', '2026-11-04');

        $this->assertSame(['NEW', 'OLD'], $this->directory()->recentVouchers($west, FinanceScope::AS_FINANCE, $supplier->id)->pluck('pv_no')->all());
        $this->assertSame(['KUMASI'], $this->directory()->recentVouchers($ash, FinanceScope::AS_FINANCE, $supplier->id)->pluck('pv_no')->all());
    }

    public function test_the_list_filters_by_status_and_searches_name_tin_phone_and_code(): void
    {
        $a = $this->supplier('Alpha Stores', ['tin' => 'C1111111111', 'phone' => '0201234567', 'code' => 'ALP-1']);
        $b = $this->supplier('Beta Works', ['tin' => 'C2222222222']);
        $b->forceFill(['is_active' => false])->save();

        $names = fn (string $search = '', string $status = 'active') => $this->directory()->suppliers($search, $status)->pluck('name')->all();

        $this->assertSame(['Alpha Stores'], $names());
        $this->assertSame(['Beta Works'], $names('', 'inactive'));
        $this->assertSame(['Alpha Stores', 'Beta Works'], $names('', 'all'));
        $this->assertSame(['Alpha Stores'], $names('C1111', 'all'));
        $this->assertSame(['Alpha Stores'], $names('0201234', 'all'));
        $this->assertSame(['Alpha Stores'], $names('ALP-1', 'all'));
        $this->assertSame([], $names('100%'), 'a % typed in the search is text, not a wildcard');
        $this->assertSame($a->id, $this->directory()->suppliers('Alpha')->value('id'));
    }
}

