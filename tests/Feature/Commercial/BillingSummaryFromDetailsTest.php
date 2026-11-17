<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialImportBatch;
use App\Services\Commercial\BatchLifecycleService;
use App\Services\Commercial\BatchResolutionService;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\BillingSummaryFromDetails;
use App\Services\Commercial\CommercialImportException;
use App\Services\Commercial\CommercialReportData;
use Illuminate\Support\Carbon;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsDerivedSummary;

/**
 * Phase 8h: the billing summary derived from the Detailed Billing Report (design 8.52). The invented detailed file and every figure the derived summary
 * must hold are worked out by hand in Tests\Support\Commercial\BuildsDerivedSummary (read its header first).
 */
class BillingSummaryFromDetailsTest extends CommercialTestCase
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

    // ---------------------------------------------------------------- every column

    public function test_every_column_of_the_derived_summary_is_the_hand_worked_figure(): void
    {
        $source = $this->importBilling($this->derivedSpec());
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $source->status, (string) $source->error_message);

        $derived = $this->derivedBatch($source);
        $this->assertNotNull($derived, 'a live detailed batch gets a derived billing summary');
        $this->assertSame('billing_summary', $derived->report_type);
        $this->assertSame('derived', $derived->source);
        $this->assertSame($source->id, (int) $derived->derived_from_batch_id);
        $this->assertSame($source->region_id, $derived->region_id);
        $this->assertSame('2026-09-01', $derived->period_from->toDateString());
        $this->assertSame('2026-09-30', $derived->period_to->toDateString());
        $this->assertSame('all', $derived->customer_segment);
        $this->assertSame('imported', $derived->status);
        $this->assertNull($derived->file_path, 'a derived summary has no workbook');
        $this->assertTrue($derived->isDerived());
        $this->assertSame(3, $derived->routes()->count());

        foreach ($this->expectedRoutes() as $expected) {
            $row = $derived->routes()->where('route_code', $expected['code'])->firstOrFail();
            $this->assertSame($expected['district'], $row->district_label_raw);
            $this->assertNotNull($row->district_id);

            foreach ($expected as $column => $value) {
                if (in_array($column, ['district', 'code'], true)) {
                    continue;
                }

                $this->assertEqualsWithDelta((float) $value, (float) $row->{$column}, 0.0001, $expected['code'].' '.$column);
            }
        }
    }

    public function test_the_identity_is_checked_per_route_and_a_broken_route_is_flagged_never_forced(): void
    {
        $derived = $this->derivedBatch($this->importBilling($this->derivedSpec()));
        $control = $derived->control_totals;

        $this->assertSame(3, $control['identity_checked']);
        $this->assertSame(1, $control['identity_flagged']);
        $this->assertSame('4202', $control['identity_failures'][0]['route']);
        $this->assertEqualsWithDelta(12.34, $control['identity_failures'][0]['difference'], 0.001);
        $this->assertFalse($derived->reconciliation_passed);
        $this->assertSame(1, $derived->warning_count);

        $flagged = $derived->routes()->where('route_code', '4202')->firstOrFail();
        $this->assertEqualsWithDelta(12.34, (float) $flagged->closing_balance, 0.001, 'the closing balance stays as the report gives it');
        $this->assertEqualsWithDelta(0.0, (float) $flagged->total_receivable - (float) $flagged->total_payments, 0.001, 'receivable less payments is 0.00, so the route does not balance');

        // the batch page lists it
        $this->actingAs($this->superAdmin());
        \Livewire\Livewire::test(\App\Livewire\Commercial\BatchShow::class, ['batch' => $derived])->assertSee('4202')->assertSee('12.34')->assertSee('Derived from the detailed billing report');
    }

    public function test_the_status_and_meter_mapping_and_the_customers_in_no_column(): void
    {
        $derived = $this->derivedBatch($this->importBilling($this->derivedSpec()));
        $a = $derived->routes()->where('route_code', '4101')->firstOrFail();

        // SUSP -> suspense; DISC, DISO and DISN -> disconnected; ACTB and NSC unbilled -> other; W and F metered; N and blank unmetered
        $this->assertSame([1, 1, 2, 1, 2], [$a->unbilled_suspense_metered, $a->unbilled_suspense_unmetered, $a->unbilled_disconn_metered, $a->unbilled_disconn_unmetered, $a->unbilled_other]);
        $this->assertSame([2, 2, 2], [$a->billed_average_metered, $a->billed_average_unmetered, $a->billed_actual_reading]);
        // customer 14 (bill status CANCELLED) is in the number of customers (14 = 6 + 7 + 1) and in no other column
        $this->assertSame(1, $derived->control_totals['customers_in_neither_column']);
        $this->assertSame(14, $a->customers_count);
        $this->assertSame($a->billed_total + $a->unbilled_total + 1, $a->customers_count);
    }

    public function test_the_domestic_band_table_is_made_from_category_611_billed_customers(): void
    {
        $derived = $this->derivedBatch($this->importBilling($this->derivedSpec()));
        $bands = $derived->bands()->orderBy('id')->get();

        $this->assertSame('built', $derived->control_totals['bands']);
        $this->assertSame(['<=5', '>5'], $bands->pluck('band')->all());
        $this->assertSame(['611', '611'], $bands->pluck('category_code')->all());
        $this->assertSame([3, 6], $bands->pluck('customers')->map(fn ($n) => (int) $n)->all());
        $this->assertEqualsWithDelta(14.00, (float) $bands[0]->volume, 0.001);
        $this->assertEqualsWithDelta(83.28, (float) $bands[0]->amount, 0.001);
        $this->assertEqualsWithDelta(53.50, (float) $bands[1]->volume, 0.001);
        $this->assertEqualsWithDelta(307.32, (float) $bands[1]->amount, 0.001);
        // consumption of exactly 5 is "at or under"; category 612 and unbilled customers are in no band
        $this->assertSame(9, (int) $bands->sum('customers'));
    }

    public function test_the_consume_factor_scales_the_volumes_and_the_band_split(): void
    {
        config(['gwl.commercial_details_consume_factor' => 2.0]);
        $source = $this->importBilling($this->derivedSpec());
        $derived = $this->derivedBatch($source);
        $a = $derived->routes()->where('route_code', '4101')->firstOrFail();

        $this->assertEqualsWithDelta(32.00, (float) $a->volume_actual, 0.001);
        $this->assertEqualsWithDelta(62.00, (float) $a->volume_average, 0.001);
        $this->assertEqualsWithDelta(94.00, (float) $a->volume_total, 0.001);
        // every 611 customer now uses more than 5 (the smallest was 4 x 2 = 8): the split is made at upload time with the factor then in force
        $bands = $derived->bands()->orderBy('id')->get();
        $this->assertSame([0, 9], $bands->pluck('customers')->map(fn ($n) => (int) $n)->all());
        $this->assertEqualsWithDelta(135.00, (float) $bands[1]->volume, 0.001);
        $this->assertEqualsWithDelta(390.60, (float) $bands[1]->amount, 0.001);
        $this->assertSame(2.0, (float) $derived->control_totals['consume_factor']);

        // the factor changes afterwards: the volumes follow it, the band table cannot be scaled again and says so instead of guessing
        config(['gwl.commercial_details_consume_factor' => 1.0]);
        $derived = app(BillingSummaryFromDetails::class)->build($source->fresh());
        $this->assertEqualsWithDelta(16.00, (float) $derived->routes()->where('route_code', '4101')->firstOrFail()->volume_actual, 0.001);
        $this->assertSame(0, $derived->bands()->count());
        $this->assertStringContainsString('upload the report again', $derived->control_totals['bands']);
    }

    public function test_a_detailed_batch_loaded_before_this_phase_has_no_band_table(): void
    {
        $source = $this->importBilling($this->derivedSpec());
        $control = $source->control_totals;
        unset($control['domestic_factor']);
        $source->forceFill(['control_totals' => $control])->save();
        \Illuminate\Support\Facades\DB::table('commercial_billing_detail_domestic_bands')->where('batch_id', $source->id)->delete();

        $derived = app(BillingSummaryFromDetails::class)->build($source->fresh());

        $this->assertSame(0, $derived->bands()->count());
        $this->assertStringContainsString('before the band table existed', $derived->control_totals['bands']);
        $this->assertSame(3, $derived->routes()->count(), 'the routes do not depend on the band table');
    }

    // ---------------------------------------------------------------- it follows its source

    public function test_voiding_the_source_removes_the_derived_summary_and_a_derived_summary_cannot_be_voided_alone(): void
    {
        $source = $this->importBilling($this->derivedSpec());
        $derived = $this->derivedBatch($source);
        $admin = $this->superAdmin();

        try {
            app(BatchLifecycleService::class)->void($derived, 'try', $admin->id);
            $this->fail('a derived summary has no life of its own');
        } catch (CommercialImportException $e) {
            $this->assertStringContainsString('derived from the detailed billing report', $e->getMessage());
        }

        app(BatchLifecycleService::class)->void($source, 'wrong file', $admin->id);

        $this->assertNull($this->derivedBatch(), 'the derived summary goes with its source');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('commercial_billing_routes')->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('commercial_billing_bands')->count());
        $this->assertSame(1, \App\Models\AuditLog::query()->where('action', 'commercial.billing_summary_derived_removed')->count());
    }

    public function test_a_source_waiting_for_its_check_has_no_derived_summary_until_it_is_loaded_and_a_discard_leaves_none(): void
    {
        $waiting = $this->importBilling($this->derivedSpec(), ['review' => true]);
        $this->assertSame(CommercialImportBatch::STATUS_AWAITING_REVIEW, $waiting->status);
        $this->assertNull($this->derivedBatch());

        $loaded = app(BillingDetailsImportService::class)->confirm($waiting);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $loaded->status);
        $this->assertNotNull($this->derivedBatch($loaded));

        $other = $this->importBilling($this->derivedSpec('October-2026 - October-2026', 200), ['review' => true]);
        app(BillingDetailsImportService::class)->discard($other);
        $this->assertSame(1, CommercialImportBatch::query()->where('source', 'derived')->count());
    }

    public function test_a_re_upload_of_the_same_period_supersedes_the_old_source_and_rebuilds_the_summary(): void
    {
        $first = $this->importBilling($this->derivedSpec());
        $firstDerived = $this->derivedBatch($first);
        $second = $this->importBilling($this->derivedSpec('September-2026 - September-2026', 300));

        $this->assertSame(CommercialImportBatch::STATUS_SUPERSEDED, $first->fresh()->status);
        $this->assertNull(CommercialImportBatch::query()->find($firstDerived->id), 'the superseded source loses its derived summary');
        $this->assertSame(1, CommercialImportBatch::query()->where('source', 'derived')->count());
        $this->assertSame($second->id, (int) $this->derivedBatch()->derived_from_batch_id);

        // voiding the newer one brings the older source back: its derived summary is built again
        app(BatchLifecycleService::class)->void($second, 'oops', $this->superAdmin()->id);
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $first->fresh()->status);
        $this->assertSame($first->id, (int) $this->derivedBatch()->derived_from_batch_id);
        $this->assertSame(3, $this->derivedBatch()->routes()->count());
    }

    public function test_a_route_whose_district_nobody_can_place_is_not_written_until_it_is_resolved(): void
    {
        $spec = $this->derivedSpec();
        $spec['routes'][] = $this->route('ATLANTIS 9901', 30, 31);

        $source = $this->importBilling($spec);
        $derived = $this->derivedBatch($source);

        $this->assertSame(3, $derived->routes()->count());
        $this->assertNull($derived->routes()->where('route_code', '9901')->first());
        $this->assertSame(1, $derived->control_totals['parked_routes']);
        $this->assertSame(['ATLANTIS 9901'], $derived->control_totals['parked_labels']);
        $this->assertSame(1, $derived->warning_count - $derived->control_totals['identity_flagged']);
        $before = $derived->id;

        app(BatchResolutionService::class)->resolveDistrict($source->fresh(), 'ATLANTIS', $this->odorkor);

        $derived = $this->derivedBatch($source);
        $this->assertSame($before, $derived->id, 'it is refreshed in place');
        $this->assertSame(4, $derived->routes()->count());
        $row = $derived->routes()->where('route_code', '9901')->firstOrFail();
        $this->assertSame('Odorkor', $row->district_label_raw);
        $this->assertSame(2, $row->customers_count);
        $this->assertSame(0, $derived->control_totals['parked_routes']);
    }

    // ---------------------------------------------------------------- the command

    public function test_the_command_rebuilds_one_or_all_and_is_safe_to_run_again(): void
    {
        $source = $this->importBilling($this->derivedSpec());
        $derived = $this->derivedBatch($source);
        $derived->routes()->update(['billing_for_period' => 1.00]);   // something to repair
        $other = $this->importBilling($this->derivedSpec('October-2026 - October-2026', 400));

        $this->artisan('commercial:billing-summary:derive', ['--batch' => $source->id])->expectsOutputToContain('Rebuilt 1 derived summary (3 routes)')->assertExitCode(0);
        $this->assertEqualsWithDelta(271.44, (float) $this->derivedBatch($source)->routes()->where('route_code', '4101')->firstOrFail()->billing_for_period, 0.001);
        $this->assertSame($derived->id, $this->derivedBatch($source)->id);

        $this->artisan('commercial:billing-summary:derive', ['--all' => true])->expectsOutputToContain('Rebuilt 2, built 0 more, removed 0.')->assertExitCode(0);
        $this->artisan('commercial:billing-summary:derive', ['--all' => true])->expectsOutputToContain('Rebuilt 2, built 0 more, removed 0.')->assertExitCode(0);
        $this->assertSame(2, CommercialImportBatch::query()->where('source', 'derived')->count());

        // the id of the derived summary also works, and a source that is gone leaves nothing behind
        $this->artisan('commercial:billing-summary:derive', ['--batch' => $this->derivedBatch($other)->id])->assertExitCode(0);
        app(BatchLifecycleService::class)->void($other, 'x', $this->superAdmin()->id);
        $this->artisan('commercial:billing-summary:derive', ['--all' => true])->expectsOutputToContain('Rebuilt 1')->assertExitCode(0);
        $this->artisan('commercial:billing-summary:derive')->assertExitCode(1);
        $this->artisan('commercial:billing-summary:derive', ['--batch' => 999999])->assertExitCode(1);
    }

    public function test_every_build_and_removal_is_audited_with_ids_and_counts_only(): void
    {
        $source = $this->importBilling($this->derivedSpec());
        $row = \App\Models\AuditLog::query()->where('action', 'commercial.billing_summary_derived')->firstOrFail();
        $meta = $row->metadata;

        $this->assertSame($source->id, $meta['source_batch_id']);
        $this->assertSame(3, $meta['routes']);
        $this->assertSame(1, $meta['identity_flagged']);
        $this->assertSame(2, $meta['band_rows']);
        $this->assertStringNotContainsString('Sowutuom', json_encode($meta));
        $this->assertStringNotContainsString('4101', json_encode($meta));
    }

    // ---------------------------------------------------------------- the period model

    public function test_two_detailed_uploads_that_do_not_overlap_add_their_flows_and_take_the_latest_closing_balance(): void
    {
        $this->importBilling($this->derivedSpec('01-September-2026 - 15-September-2026'));
        $this->importBilling($this->derivedSpec('16-September-2026 - 30-September-2026', 100));
        $this->assertSame(2, CommercialImportBatch::query()->where('source', 'derived')->count());

        $this->actingAs($this->superAdmin());
        $data = app(CommercialReportData::class);
        $range = $data->billingRange($data->snapshots()[0], '2026-09', '2026-09');

        $this->assertCount(2, $range['batch_ids']);
        $a = $range['routes']->first(fn ($r) => $r->route_code === '4101');
        $this->assertEqualsWithDelta(542.88, (float) $a->billing_for_period, 0.001, 'flows add: 2 x 271.44');
        $this->assertEqualsWithDelta(94.00, (float) $a->volume_total, 0.001);
        $this->assertEqualsWithDelta(235.00, (float) $a->opening_balance, 0.001, 'the opening balance is the earliest upload\'s');
        $this->assertEqualsWithDelta(308.32, (float) $a->closing_balance, 0.001, 'the closing balance is the latest upload\'s');
        $this->assertEqualsWithDelta(235.00 + 542.88 - 6.00, (float) $a->total_receivable, 0.001, 'receivable is recomputed, not added');
        $this->assertSame(14, (int) $a->customers_count, 'customer counts are the latest upload\'s');
    }
}
