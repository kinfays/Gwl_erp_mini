<?php

namespace Tests\Feature\Finance;

use App\Livewire\Finance\Reports;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvType;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\PvAnalyticsService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * PvAnalyticsService is the only evaluator: figures, turnaround times, what is waiting, scope (a location sees its own books and
 * Audit never a draft), zero-denominator ratios and the cache that is dropped the moment a voucher changes.
 */
class PvAnalyticsTest extends FinanceTestCase
{
    private function analytics(): PvAnalyticsService
    {
        return app(PvAnalyticsService::class);
    }

    private function at(string $time, \Closure $do): mixed
    {
        Carbon::setTestNow($time);

        return $do();
    }

    /** Signs the user in and returns null, so `$this->who($user) ?? $this->workflow()->...` yields the workflow's result. */
    private function who($user): mixed
    {
        $this->actingAs($user);

        return null;
    }

    private function type(string $name): FinPvType
    {
        return FinPvType::query()->where('name', $name)->firstOrFail();
    }

    /**
     * A small year of Accra West: one completed voucher, one ticked and waiting for Finance after a query, one still With Audit, a
     * draft, a voided one, and a voucher of Kumasi that must never show.
     */
    private function books(): array
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $medical = $this->type('Medical');
        $stationery = $this->type('Stationery');
        $make = fn (string $no, string $date, string $amount, FinPvType $type, $by = null) => $this->pvData(['pv_no' => $no, 'pv_date' => $date, 'amount' => $amount, 'type_id' => $type->id, 'category_id' => $type->category_id]);

        // V1: sent 6 Oct, ticked 8 Oct (2 days), acknowledged 9 Oct (1 day): completed after 3 days.
        $v1 = $this->at('2026-10-06 09:00', fn () => $this->who($fin) ?? $this->workflow()->submit($fin, $this->workflow()->createDraft($fin, $make('V1', '2026-10-05', '100.00', $medical)), 1));
        $this->at('2026-10-08 09:00', fn () => $this->who($aud) ?? $this->workflow()->auditAcknowledge($aud, $v1));
        $this->at('2026-10-09 09:00', fn () => $this->who($fin) ?? $this->workflow()->financeAcknowledge($fin, $v1));

        // V2: sent 21 Oct, queried 23 Oct (2 days), resubmitted 24 Oct (1 day), ticked 25 Oct (1 day), still waiting for Finance.
        $v2 = $this->at('2026-10-21 09:00', fn () => $this->who($fin) ?? $this->workflow()->submit($fin, $this->workflow()->createDraft($fin, $make('V2', '2026-10-20', '50.00', $stationery)), 1));
        $v2 = $this->at('2026-10-23 09:00', fn () => $this->who($aud) ?? $this->workflow()->auditQuery($aud, $v2, 'missing_document', 'Receipt missing'));
        $v2 = $this->at('2026-10-24 09:00', fn () => $this->who($fin) ?? $this->workflow()->resubmit($fin, $v2, $v2->lock_version));
        $this->at('2026-10-25 09:00', fn () => $this->who($aud) ?? $this->workflow()->auditAcknowledge($aud, $v2));

        // V3: sent 3 Nov, still With Audit. A draft, and a voucher sent and then voided.
        $v3 = $this->at('2026-11-03 09:00', fn () => $this->who($fin) ?? $this->workflow()->submit($fin, $this->workflow()->createDraft($fin, $make('V3', '2026-11-02', '25.00', $medical)), 1));
        $this->at('2026-11-04 09:00', fn () => $this->who($fin) ?? $this->workflow()->createDraft($fin, $make('DRAFT', '2026-11-04', '999.00', $medical)));
        $v5 = $this->at('2026-11-02 09:00', fn () => $this->who($fin) ?? $this->workflow()->submit($fin, $this->workflow()->createDraft($fin, $make('V5', '2026-11-01', '500.00', $medical)), 1));
        $this->at('2026-11-02 10:00', fn () => $this->workflow()->void($fin, $v5, 'Entered twice'));

        // Kumasi's own books.
        $this->at('2026-10-10 09:00', fn () => $this->who($ash) ?? $this->workflow()->submit($ash, $this->workflow()->createDraft($ash, $make('KUMASI-ONLY', '2026-10-10', '1000.00', $medical)), 1));

        Carbon::setTestNow('2026-11-07 09:00:00');

        return compact('fin', 'aud', 'ash');
    }

    private function west(): array
    {
        return ['region_id' => $this->sowutuom->region_id, 'district_id' => $this->sowutuom->id];
    }

    public function test_the_figures_of_a_location_for_a_period(): void
    {
        ['fin' => $fin] = $this->books();

        $r = $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07');

        $this->assertSame(3, $r['tiles']['vouchers'], 'V1, V2, V3: not the draft, not the voided one, not Kumasi\'s');
        $this->assertSame(17500, $r['tiles']['amount']);
        $this->assertSame(5833, $r['tiles']['average_amount']);
        $this->assertSame(1, $r['tiles']['completed_vouchers']);
        $this->assertSame(10000, $r['tiles']['completed_amount']);
        $this->assertSame(2, $r['tiles']['open_vouchers']);
        $this->assertSame(7500, $r['tiles']['open_amount']);
        $this->assertSame(1, $r['tiles']['drafts']);
        $this->assertSame(1, $r['tiles']['voided']);

        $this->assertSame(['Staff Claims', 'Operations Activities'], array_column($r['categories'], 'name'));
        $this->assertSame([12500, 5000], array_column($r['categories'], 'amount'));
        $this->assertSame(['Staff Claims > Medical', 'Operations Activities > Stationery'], array_column($r['types'], 'name'));
        $this->assertSame(['District'], array_column($r['payee_kinds'], 'label'));

        $this->assertSame(['Oct 2026', 'Nov 2026'], $r['months']['labels']);
        $this->assertSame([2, 1], $r['months']['vouchers']);
        $this->assertSame([150.0, 25.0], $r['months']['amount']);

        $status = collect($r['statuses'])->pluck('count', 'status');
        $this->assertSame(1, $status[Pv::DRAFT]);
        $this->assertSame(1, $status[Pv::VOIDED]);
        $this->assertSame(1, $status[Pv::COMPLETED]);
        $this->assertSame(1, $status[Pv::WITH_AUDIT]);
        $this->assertSame(1, $status[Pv::AUDIT_ACKNOWLEDGED]);
    }

    public function test_how_often_audit_queried_why_and_how_long_each_step_took(): void
    {
        ['fin' => $fin] = $this->books();

        $a = $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07')['audit'];

        $this->assertSame(1, $a['queried_vouchers']);
        $this->assertSame(33.3, $a['queried_rate']);
        $this->assertSame(1.33, $a['average_rounds']);
        $this->assertSame(1, $a['multi_round_vouchers']);

        // Audit: 2 days (V1), 2 days then 1 day (V2) = 1.7 over 3. Finance answered the query in 1 day; V1 was acknowledged 1 day
        // after the tick and took 3 days from first sending.
        $this->assertSame(['days' => 1.7, 'count' => 3], $a['turnaround']['audit_response']);
        $this->assertSame(['days' => 1.0, 'count' => 1], $a['turnaround']['finance_response']);
        $this->assertSame(['days' => 1.0, 'count' => 1], $a['turnaround']['acknowledge']);
        $this->assertSame(['days' => 3.0, 'count' => 1], $a['turnaround']['end_to_end']);

        $reasons = $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07')['reasons'];
        $this->assertSame([['code' => 'missing_document', 'label' => 'Missing supporting document', 'count' => 1]], $reasons);
    }

    public function test_what_is_waiting_right_now_with_the_locations_own_limits(): void
    {
        ['fin' => $fin] = $this->books();

        $w = $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07')['waiting'];

        // V3 has been With Audit for 4 days (limit 3); V2 was ticked 13 days ago (limit 3). Nothing is queried.
        $this->assertSame(['count' => 1, 'overdue' => 1, 'oldest_days' => 4, 'threshold' => 3], collect($w['kinds']['audit'])->only(['count', 'overdue', 'oldest_days', 'threshold'])->all());
        $this->assertSame(['count' => 1, 'overdue' => 1, 'oldest_days' => 13, 'threshold' => 3], collect($w['kinds']['acknowledge'])->only(['count', 'overdue', 'oldest_days', 'threshold'])->all());
        $this->assertSame(0, $w['kinds']['queried']['count']);
        $this->assertSame([0, 1, 0, 0], $w['aging']['counts']);
        $this->assertSame(['V2', 'V3'], array_column($w['items'], 'pv_no'), 'the longest wait first');

        // Waiting is as at today whatever the period asked for.
        $other = $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $this->west(), '2025-01-01', '2025-01-31');
        $this->assertSame(2, collect($other['waiting']['kinds'])->sum('count'));
        $this->assertSame(0, $other['tiles']['vouchers']);

        // The location's own limit decides "overdue", and saving it drops the cached figures at once.
        app(\App\Services\Finance\AuditSettings::class)->save($this->auditManager('AM1', $this->sowutuom), [\App\Services\Finance\AuditSettings::WAIT => 10], null);
        $after = $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07')['waiting'];
        $this->assertSame(0, $after['kinds']['audit']['overdue']);
        $this->assertSame(10, $after['kinds']['audit']['threshold']);
    }

    public function test_audit_never_sees_a_draft_and_a_location_never_sees_anothers_books(): void
    {
        ['fin' => $fin, 'aud' => $aud, 'ash' => $ash] = $this->books();

        $audit = $this->analytics()->report($aud, FinanceScope::AS_AUDIT, $this->west(), '2026-10-01', '2026-11-07');
        $this->assertSame(0, $audit['tiles']['drafts']);
        $this->assertSame(3, $audit['tiles']['vouchers']);
        $this->assertNotContains(Pv::DRAFT, array_column($audit['statuses'], 'status'));

        // Kumasi sees its own voucher only, and asking for Accra West's location gets nothing: the scope still applies.
        $kumasi = ['region_id' => $this->kumasi->region_id, 'district_id' => $this->kumasi->id];
        $this->assertSame(100000, $this->analytics()->report($ash, FinanceScope::AS_FINANCE, $kumasi, '2026-10-01', '2026-11-07')['tiles']['amount']);
        $this->assertSame(0, $this->analytics()->report($ash, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07')['tiles']['vouchers']);

        // Head Office is its own books, not part of Accra West's region.
        $ho = ['region_id' => $this->headOffice->region_id, 'district_id' => $this->headOffice->id];
        $this->assertSame(0, $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $ho, '2026-10-01', '2026-11-07')['tiles']['vouchers']);

        // super_admin may read any location.
        $this->assertSame(3, $this->analytics()->report($this->superAdmin(), FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07')['tiles']['vouchers']);
    }

    public function test_a_ratio_with_nothing_to_divide_by_is_null_not_zero(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);

        $r = $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-10-31');

        $this->assertSame(0, $r['tiles']['vouchers']);
        $this->assertNull($r['tiles']['average_amount']);
        $this->assertNull($r['audit']['queried_rate']);
        $this->assertNull($r['audit']['average_rounds']);
        $this->assertNull($r['audit']['turnaround']['audit_response']['days']);
        $this->assertSame([], $r['categories']);
        $this->assertSame([0], $r['months']['vouchers']);
    }

    public function test_the_payload_is_cached_until_a_voucher_changes(): void
    {
        ['fin' => $fin] = $this->books();
        $first = $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07');

        // Written straight to the table (nothing bumped the books version): the cached copy is served.
        Pv::query()->where('pv_no', 'V3')->update(['amount' => 99999]);
        $this->assertSame($first['tiles']['amount'], $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07')['tiles']['amount']);

        // A real change goes through the workflow, which bumps the version, so the next read recomputes.
        $this->actingAs($fin);
        $this->workflow()->submit($fin, $this->workflow()->createDraft($fin, $this->pvData(['pv_no' => 'LATE', 'pv_date' => '2026-11-05', 'amount' => '1.00'])), 1);
        $second = $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $this->west(), '2026-10-01', '2026-11-07');

        $this->assertSame(4, $second['tiles']['vouchers']);
        $this->assertSame(99999 + 10000 + 5000 + 100, $second['tiles']['amount']);
    }

    public function test_the_months_of_a_long_period_are_capped_and_zero_filled(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $r = $this->analytics()->report($fin, FinanceScope::AS_FINANCE, $this->west(), '2020-01-01', '2026-11-07');

        $this->assertCount(PvAnalyticsService::MAX_MONTHS, $r['months']['labels']);
        $this->assertSame('Nov 2026', end($r['months']['labels']));
    }

    public function test_the_screen_shows_the_locations_figures_and_super_admin_picks_a_location(): void
    {
        ['fin' => $fin, 'aud' => $aud] = $this->books();

        Livewire::actingAs($fin)->test(Reports::class)
            ->assertSee('Spend by month')->assertSee('Staff Claims')->assertSee('V3')
            ->assertDontSee('KUMASI-ONLY')
            ->set('preset', 'this_month')->assertDispatched('fin-reports-updated')
            ->assertSet('from', '2026-11-01');

        Livewire::actingAs($aud)->test(Reports::class, ['module' => 'audit'])->assertSee('Spend by month')->assertSee('Queried by Audit');

        Livewire::actingAs($this->superAdmin())->test(Reports::class)
            ->assertSee('Choose a location above')->assertDontSee('Spend by month')
            ->set('locationId', (string) $this->sowutuom->id)->assertSee('Spend by month')->assertSee('Staff Claims');
    }
}
