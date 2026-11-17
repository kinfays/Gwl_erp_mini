<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Batches;
use App\Livewire\Commercial\Billing;
use App\Livewire\Commercial\Home;
use App\Livewire\Commercial\Kpis;
use App\Livewire\Commercial\Summary;
use App\Models\CommercialImportBatch;
use App\Services\Commercial\BillingAnalyticsService;
use App\Services\Commercial\BillingSummaryFromDetails;
use App\Services\Commercial\CommercialExportService;
use App\Services\Commercial\CommercialPeriods;
use App\Services\Commercial\CommercialReportData;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsDerivedSummary;

/**
 * Phase 8h: which billing summary counts when a region and period have BOTH an uploaded one and one derived from the detailed report
 * (commercial_billing_summary_preference), and that the two are never counted in one total, on every screen that shows billing.
 *
 * The figures: the derived summary's billing is 436.76 (271.44 + 108.12 + 57.20, BuildsDerivedSummary). The uploaded twin changes route 4101's billing to
 * 300.00, so its total is 465.32 (300.00 + 108.12 + 57.20) and a screen's figure says which of the two it counted.
 */
class DerivedSummaryPreferenceTest extends CommercialTestCase
{
    use BuildsBillingDetails;
    use BuildsDerivedSummary;

    protected CommercialImportBatch $source;

    protected CommercialImportBatch $derived;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        config(['gwl.commercial_customer_list_enabled' => false, 'gwl.commercial_module_enabled' => true]);
        $this->setUpBillingDetails();
        $this->tolerant();
        $this->source = $this->importBilling($this->derivedSpec());
        $this->derived = $this->derivedBatch($this->source);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function uploaded(): CommercialImportBatch
    {
        return $this->uploadedTwin(['4101' => ['billing_for_period' => 300.00]]);
    }

    /** @return list<int> the ids of the billing snapshots every screen starts from */
    protected function snapshotIds(): array
    {
        return array_map(fn (array $s) => (int) $s['id'], app(BillingAnalyticsService::class)->snapshots(CommercialImportBatch::query()));
    }

    // ---------------------------------------------------------------- the two preferences

    public function test_the_default_counts_the_uploaded_summary_and_marks_the_derived_one_not_used(): void
    {
        $uploaded = $this->uploaded();

        $this->assertSame('uploaded', config('gwl.commercial_billing_summary_preference'));
        $this->assertSame([$uploaded->id], $this->snapshotIds(), 'the derived summary stays stored but is not a snapshot anyone counts');
        $this->assertNotNull(CommercialImportBatch::query()->find($this->derived->id), 'nothing is deleted');

        $why = app(BillingSummaryFromDetails::class);
        $this->assertSame('not used: an uploaded summary covers this period (batch #'.$uploaded->id.')', $why->notUsedReason($this->derived));
        $this->assertNull($why->notUsedReason($uploaded));
    }

    public function test_the_detailed_report_preference_counts_the_derived_summary_and_marks_the_uploaded_one(): void
    {
        config(['gwl.commercial_billing_summary_preference' => 'details']);
        $uploaded = $this->uploaded();

        $this->assertSame([$this->derived->id], $this->snapshotIds());
        $this->assertSame('not used: the detailed report covers this period (batch #'.$this->derived->id.')', app(BillingSummaryFromDetails::class)->notUsedReason($uploaded));
        $this->assertNull(app(BillingSummaryFromDetails::class)->notUsedReason($this->derived));
    }

    public function test_without_an_uploaded_summary_the_derived_one_counts_under_either_preference(): void
    {
        foreach (['uploaded', 'details'] as $preference) {
            config(['gwl.commercial_billing_summary_preference' => $preference]);
            $this->assertSame([$this->derived->id], $this->snapshotIds(), $preference);
            $this->assertNull(app(BillingSummaryFromDetails::class)->notUsedReason($this->derived));
        }
    }

    public function test_with_no_detailed_report_nothing_changes_for_anyone(): void
    {
        app(\App\Services\Commercial\BatchLifecycleService::class)->void($this->source, 'none', $this->superAdmin()->id);
        $uploaded = $this->uploaded();

        foreach (['uploaded', 'details'] as $preference) {
            config(['gwl.commercial_billing_summary_preference' => $preference]);
            $this->assertSame([$uploaded->id], $this->snapshotIds(), $preference);
        }
    }

    public function test_a_containing_period_counts_the_preferred_source_and_a_partial_overlap_keeps_both(): void
    {
        // an uploaded summary for the whole of Q3 contains the derived September: under 'uploaded' the derived one does not count
        $quarter = $this->seedBilling([['district' => 'Sowutuom', 'code' => '4101', 'billing_for_period' => 1.00]], '2026-07-01', ['segment' => 'all', 'period_to' => '2026-09-30', 'districts' => ['Sowutuom' => $this->sowutuom->id]]);
        $this->assertSame([$quarter->id], $this->snapshotIds());
        $this->assertSame('not used: an uploaded summary covers this period (batch #'.$quarter->id.')', app(BillingSummaryFromDetails::class)->notUsedReason($this->derived));

        // under 'details' the derived September wins over the quarter that contains it only when the DERIVED one is the container: here it is not, so both stay
        config(['gwl.commercial_billing_summary_preference' => 'details']);
        $this->assertEqualsCanonicalizing([$quarter->id, $this->derived->id], $this->snapshotIds());
        $this->assertNull(app(BillingSummaryFromDetails::class)->notUsedReason($this->derived));
    }

    public function test_two_uploaded_summaries_or_two_derived_ones_keep_the_newest_wins_rule(): void
    {
        $first = $this->uploaded();
        $second = $this->uploadedTwin(['4101' => ['billing_for_period' => 111.00]]);
        $this->assertSame([$second->id], $this->snapshotIds());
        $this->assertNull(app(BillingSummaryFromDetails::class)->notUsedReason($second));
        $this->assertNull(app(BillingSummaryFromDetails::class)->notUsedReason($first), 'two uploaded summaries are settled by the older newest-wins rule (a label), not by the preference');
    }

    // ---------------------------------------------------------------- never both, on every screen

    /** @return array<string, array{0: string, 1: string, 2: string}> preference => [counted figure, the other figure, the batch that counts] */
    public static function preferences(): array
    {
        return ['uploaded' => ['uploaded', '465.32', '436.76'], 'details' => ['details', '436.76', '465.32']];
    }

    #[DataProvider('preferences')]
    public function test_the_billing_screen_counts_one_summary_only(string $preference, string $counted, string $other): void
    {
        config(['gwl.commercial_billing_summary_preference' => $preference]);
        $uploaded = $this->uploaded();
        $this->actingAs($this->superAdmin());

        $snapshot = app(CommercialReportData::class)->billingSnapshot(null);
        $this->assertSame($preference === 'details' ? $this->derived->id : $uploaded->id, (int) $snapshot['snapshot']['id']);
        $this->assertSame((float) $counted, round((float) $snapshot['overview']['totals']['billing_for_period'], 2));
        $this->assertSame($preference === 'details', (bool) $snapshot['snapshot']['is_derived']);

        $page = Livewire::test(Billing::class);
        $page->assertSee(number_format((float) $counted, 2));
        $page->assertDontSee(number_format((float) $other, 2));

        if ($preference === 'details') {
            $page->assertSee('from the detailed billing report');
        } else {
            $page->assertDontSee('from the detailed billing report');
        }
    }

    #[DataProvider('preferences')]
    public function test_home_counts_one_summary_only(string $preference, string $counted, string $other): void
    {
        config(['gwl.commercial_billing_summary_preference' => $preference]);
        $this->uploaded();
        $this->actingAs($this->superAdmin());

        Livewire::test(Home::class)->assertSee('GH¢ '.number_format((float) $counted, 2))->assertDontSee('GH¢ '.number_format((float) $other, 2));
    }

    #[DataProvider('preferences')]
    public function test_the_summary_counts_one_summary_only_and_names_the_period_and_the_source(string $preference, string $counted, string $other): void
    {
        config(['gwl.commercial_billing_summary_preference' => $preference]);
        $this->uploaded();
        $this->actingAs($this->superAdmin());

        $data = app(CommercialReportData::class)->summary();
        $this->assertSame($preference === 'details', (bool) ($data['snapshot']['is_derived'] ?? false));
        $this->assertCount(1, $data['snapshots'], 'one snapshot for the period, never two');

        $page = Livewire::test(Summary::class);

        if ($preference === 'details') {
            $page->assertSee('from the detailed billing report');
        } else {
            $page->assertDontSee('from the detailed billing report');
        }
    }

    #[DataProvider('preferences')]
    public function test_the_kpis_count_one_summary_only_and_say_where_the_billing_comes_from(string $preference, string $counted, string $other): void
    {
        config(['gwl.commercial_billing_summary_preference' => $preference]);
        $this->uploaded();
        $this->actingAs($this->superAdmin());

        $kpis = app(CommercialReportData::class)->kpis();
        $this->assertFalse($kpis['empty']);
        $this->assertCount(1, $kpis['months']);
        $this->assertSame($preference === 'details', $kpis['months'][0]['is_derived']);
        $billing = collect($kpis['total']['rows'])->first(fn (array $r) => str_contains(strtolower($r['label']), 'billing'));
        $this->assertSame((float) $counted, round((float) array_values($billing['actuals'])[0], 2));

        $page = Livewire::test(Kpis::class);

        if ($preference === 'details') {
            $page->assertSee('billing figures from the detailed billing report')->assertSee('Sep 2026');
        } else {
            $page->assertDontSee('from the detailed billing report');
        }
    }

    public function test_the_exports_carry_the_label_in_their_notes_line(): void
    {
        config(['gwl.commercial_billing_summary_preference' => 'details']);
        $this->uploaded();
        $this->actingAs($this->superAdmin());
        $data = app(CommercialReportData::class);
        $export = app(CommercialExportService::class);

        $this->assertTrue(collect($export->billing($data->billingSnapshot(null))['notes'])->contains(fn ($n) => str_contains($n, 'from the detailed billing report')));
        $this->assertTrue(collect($export->kpis($data->kpis())['notes'])->contains(fn ($n) => str_contains($n, 'from the detailed billing report')));

        config(['gwl.commercial_billing_summary_preference' => 'uploaded']);
        $this->assertFalse(collect($export->billing($data->billingSnapshot(null))['notes'])->contains(fn ($n) => str_contains($n, 'from the detailed billing report')));
    }

    // ---------------------------------------------------------------- the Uploads page

    public function test_the_uploads_page_labels_a_derived_summary_marks_the_batch_not_used_and_calls_the_summary_upload_optional(): void
    {
        $this->uploaded();
        $this->actingAs($this->superAdmin());

        Livewire::test(Batches::class)
            ->assertSeeHtml('data-testid="derived-label"')
            ->assertSee('Derived from the detailed billing report (batch '.$this->source->id.')')
            ->assertSee('Not used: an uploaded summary covers this period')
            ->set('showUpload', true)
            ->assertSee('uploading the billing summary is optional');
    }

    public function test_the_summary_upload_is_not_called_optional_when_no_detailed_report_is_live_and_the_preference_is_the_default(): void
    {
        app(\App\Services\Commercial\BatchLifecycleService::class)->void($this->source, 'none', $this->superAdmin()->id);
        $this->actingAs($this->superAdmin());

        Livewire::test(Batches::class)->set('showUpload', true)->assertDontSee('uploading the billing summary is optional');

        config(['gwl.commercial_billing_summary_preference' => 'details']);
        Livewire::test(Batches::class)->set('showUpload', true)->assertSee('uploading the billing summary is optional');
    }

    public function test_a_range_view_names_a_derived_batch_and_the_batch_page_explains_it(): void
    {
        $this->actingAs($this->superAdmin());
        $range = app(CommercialReportData::class)->billingRange(app(CommercialReportData::class)->snapshots()[0], '2026-09', '2026-09');

        $this->assertTrue($range['meta']['is_derived']);
        $this->assertStringContainsString('from the detailed billing report', $range['meta']['label']);
        $this->assertTrue(collect($range['notes'])->contains(fn ($n) => str_contains($n, 'derived from the detailed billing report')));
        $this->assertSame('from the detailed billing report', CommercialPeriods::unitOf($this->derived)['source'] === 'derived' ? 'from the detailed billing report' : '');
    }

    public function test_the_settings_exist_with_their_defaults_and_are_stored_only_when_they_differ(): void
    {
        $settings = app(\App\Services\Commercial\CommercialSettings::class);
        $keys = ['commercial_details_consume_factor' => 1.0, 'commercial_billing_summary_preference' => 'uploaded', 'commercial_x12_money_tolerance' => 0.10, 'commercial_x12_volume_tolerance' => 0.5];

        foreach ($keys as $key => $default) {
            $this->assertArrayHasKey($key, \App\Services\Commercial\CommercialSettings::definitions());
            $this->assertEquals($default, config('gwl.'.$key), $key);
        }

        $this->assertSame('billing_details', \App\Services\Commercial\CommercialSettings::definitions()['commercial_details_consume_factor']['group']);
        $this->assertStringContainsString('PENDING CONFIRMATION BY THE COMMERCIAL TEAM', \App\Services\Commercial\CommercialSettings::definitions()['commercial_details_consume_factor']['help']);
        $this->assertSame('imports', \App\Services\Commercial\CommercialSettings::definitions()['commercial_billing_summary_preference']['group']);

        $settings->save(['commercial_billing_summary_preference' => 'details', 'commercial_details_consume_factor' => 1.0], $this->superAdmin()->id);
        $this->assertSame('details', config('gwl.commercial_billing_summary_preference'));
        $this->assertSame(['commercial_billing_summary_preference'], array_keys($settings->overrides()), 'a value equal to its default is stored as no override');
        $this->assertSame([$this->derived->id], $this->snapshotIds());
    }
}
