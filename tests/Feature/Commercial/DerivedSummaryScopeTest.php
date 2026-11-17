<?php

namespace Tests\Feature\Commercial;

use App\Models\AuditLog;
use App\Models\CommercialImportBatch;
use App\Services\Commercial\CommercialExportService;
use App\Services\Commercial\CommercialReportData;
use App\Services\Commercial\Trends\UploadTimeliness;
use App\Services\Commercial\UploadReminderService;
use Illuminate\Support\Carbon;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsDerivedSummary;

/**
 * Phase 8h: a district user and the derived summary, the upload reminders, the timeliness calendar (T8) and the exports.
 */
class DerivedSummaryScopeTest extends CommercialTestCase
{
    use BuildsBillingDetails;
    use BuildsDerivedSummary;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        config(['gwl.commercial_customer_list_enabled' => false, 'gwl.commercial_module_enabled' => true]);
        $this->setUpBillingDetails();
        $this->tolerant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- district users

    public function test_a_district_users_detailed_file_keeps_only_their_district_and_so_does_the_derived_summary(): void
    {
        $viewer = $this->userWithRoles('900985', ['district_commercial_viewer'], $this->accraWest, $this->sowutuom);
        $source = $this->importBilling($this->derivedSpec(), ['actor' => $viewer, 'districts' => [$this->sowutuom->id]]);
        $derived = $this->derivedBatch($source);

        $this->assertNotNull($derived);
        $this->assertSame(['4101'], $derived->routes()->pluck('route_code')->all(), 'the Odorkor routes were never stored, so they are not derived');
        $this->assertEqualsWithDelta(271.44, (float) $derived->routes()->first()->billing_for_period, 0.001);
    }

    public function test_a_district_user_sees_their_districts_derived_rows_and_no_region_wide_row(): void
    {
        $this->importBilling($this->derivedSpec());
        $this->actingAs($this->userWithRoles('900986', ['district_commercial_viewer'], $this->accraWest, $this->sowutuom));
        $data = app(CommercialReportData::class);

        $snapshot = $data->billingSnapshot(null);
        $this->assertTrue($snapshot['snapshot']['is_derived']);
        $this->assertEqualsWithDelta(271.44, (float) $snapshot['overview']['totals']['billing_for_period'], 0.001, 'Sowutuom only');
        $this->assertSame(['Sowutuom'], array_column($snapshot['overview']['districts'], 'district'));
        $this->assertSame([], $data->billingSnapshot(null)['bands']['rows'] ?? [], 'the band table is region-wide: not for a district user');

        $kpis = $data->kpis();
        $this->assertNull($kpis['total'], 'no "All districts" row for a district user');
        $this->assertSame(['Sowutuom'], array_column($kpis['districts'], 'name'));
    }

    // ---------------------------------------------------------------- reminders and timeliness

    public function test_a_billing_summary_is_not_overdue_when_a_live_derived_one_is_recent_and_is_unchanged_otherwise(): void
    {
        config(['gwl.commercial_reminder_billing_days' => 30]);
        $reminders = app(UploadReminderService::class);
        $old = $this->uploadedTwin();
        $old->forceFill(['imported_at' => now()->subDays(60)])->save();

        $overdue = fn () => collect($reminders->overdue(CommercialImportBatch::query()->notVoided()->with('region')->get(), now()))->where('report_type', 'billing_summary')->count();
        $this->assertSame(1, $overdue(), 'an old uploaded summary and nothing else: overdue, as before this phase');

        $this->importBilling($this->derivedSpec());
        $this->assertNotNull($this->derivedBatch());
        $this->assertSame(0, $overdue(), 'a live derived summary covers the reminder window');

        // the billing-details reminder of 8f is unchanged: it is judged on the detailed uploads
        $detail = CommercialImportBatch::query()->where('report_type', 'billing_details')->firstOrFail();
        $detail->forceFill(['imported_at' => now()->subDays(90)])->save();
        $this->derivedBatch()->forceFill(['imported_at' => now()->subDays(90)])->save();
        $items = collect($reminders->overdue(CommercialImportBatch::query()->notVoided()->with('region')->get(), now()));
        $this->assertSame(1, $items->where('report_type', 'billing_details')->count());
        $this->assertSame(1, $items->where('report_type', 'billing_summary')->count(), 'when the derived summary is old too, the summary is overdue again');
    }

    public function test_the_timeliness_calendar_counts_a_derived_summary_on_its_own_line(): void
    {
        $this->uploadedTwin();
        $this->importBilling($this->derivedSpec());
        $this->actingAs($this->superAdmin());
        $t8 = app(UploadTimeliness::class);

        $this->assertSame('Billing summary (from the detailed report)', UploadTimeliness::TYPES['billing_summary_derived']['label']);
        $derived = $t8->build('billing_summary_derived', $this->accraWest->id, '2026-09');
        $uploaded = $t8->build('billing_summary', $this->accraWest->id, '2026-09');

        $this->assertSame(1, $derived['total']);
        $this->assertSame(1, $uploaded['total'], 'the uploaded summary is counted on its own line, not twice');
        $this->assertEqualsCanonicalizing(['Odorkor', 'Sowutuom'], array_column($derived['districts'], 'name'));
    }

    // ---------------------------------------------------------------- exports

    public function test_the_billing_export_of_a_derived_summary_is_scoped_and_labelled(): void
    {
        $this->importBilling($this->derivedSpec());
        $this->actingAs($this->userWithRoles('900987', ['district_commercial_viewer'], $this->accraWest, $this->sowutuom));
        $export = app(CommercialExportService::class);
        $report = $export->billing(app(CommercialReportData::class)->billingSnapshot(null));

        $text = json_encode($report['tables']);
        $this->assertStringContainsString('Sowutuom', $text);
        $this->assertStringNotContainsString('Odorkor', $text, 'another district never reaches the file');
        $this->assertTrue(collect($report['notes'])->contains(fn ($n) => str_contains($n, 'from the detailed billing report')));
        $this->assertStringContainsString('from the detailed billing report', $report['meta']['Billing snapshot'] ?? json_encode($report['meta']));
    }

    public function test_the_derive_audit_rows_never_hold_a_payee_a_route_or_a_figure(): void
    {
        $this->importBilling($this->derivedSpec());
        $all = AuditLog::query()->where('action', 'like', 'commercial.billing_summary_derived%')->get()->map(fn ($r) => json_encode($r->metadata))->implode(' ');

        foreach (['4101', '271.44', 'Sowutuom', 'Sample Customer'] as $needle) {
            $this->assertStringNotContainsString($needle, $all);
        }
    }
}
