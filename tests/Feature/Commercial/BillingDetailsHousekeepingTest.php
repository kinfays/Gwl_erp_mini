<?php

namespace Tests\Feature\Commercial;

use App\Jobs\Commercial\ProcessBillingDetailsBatch;
use App\Livewire\Commercial\Batches;
use App\Livewire\Commercial\BatchShow;
use App\Models\AuditLog;
use App\Models\CommercialImportBatch;
use App\Models\CommercialSetting;
use App\Models\District;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Commercial\BillingDetails\BillingDetailsReport;
use App\Services\Commercial\CommercialReportData;
use App\Services\Commercial\CommercialSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Commercial\BuildsBillingDetails;

/**
 * Phase 8d: the capped account lists and their purge, the new Settings, the housekeeping command, and the batch page of a Detailed Billing
 * Report upload (progress, the check before loading, blocked, failed, the district to resolve, void). Invented data only.
 */
class BillingDetailsHousekeepingTest extends CommercialTestCase
{
    use BuildsBillingDetails;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        config(['gwl.commercial_customer_list_enabled' => false]);
        $this->setUpBillingDetails();
        $this->tolerant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- the capped lists

    public function test_each_list_keeps_only_the_cap_per_kind_and_district_and_the_largest_figures_but_the_counts_cover_everyone(): void
    {
        config(['gwl.commercial_billing_detail_issue_cap' => 5]);
        $batch = $this->importBilling();

        $debtors = $batch->detailIssues()->where('kind', 'top_debtor')->get();
        // 117 accounts owe: Sowutuom 50, Odorkor 36, Amasaman 30 and the unplaced district 1; each district keeps at most 5 (the unplaced one its 1)
        $this->assertSame(16, $debtors->count());
        $this->assertSame(5, $debtors->where('district_id', $this->sowutuom->id)->count());
        $this->assertSame(5, $debtors->where('district_id', $this->odorkor->id)->count());
        $this->assertSame(5, $debtors->where('district_id', $this->amasaman->id)->count());

        // the first district keeps its LARGEST five: 557.20 (n = 17), 100.00 (n = 1), then three of 57.20 (the lowest account numbers: n = 5, 25, 30)
        $first = $debtors->where('district_id', $this->sowutuom->id)->sortByDesc(fn ($i) => [(float) $i->amount, -((int) substr($i->account_no, 3))])->values();
        $this->assertSame(['100000000017', '100000000001', '100000000005', '100000000025', '100000000030'], $first->pluck('account_no')->all());
        $this->assertSame([557.2, 100.0, 57.2, 57.2, 57.2], $first->map(fn ($i) => (float) $i->amount)->all());

        // the counts and figures are over everyone, not just the listed accounts
        $this->assertSame(117, collect($batch->control_totals['concentration'])->first(fn (array $c) => $c['district'] === '*' && $c['category'] === '*')['owing']);
        $this->assertSame(117, (int) $batch->detailRollups()->sum('owing'));
        $this->actingAs($this->officer());
        $report = app(CommercialReportData::class)->billingDetails();
        $this->assertSame(117, $report['analysis']['totals']['owing']);
        $this->assertSame(16, $report['lists']['top_debtor']['total']);
        $this->assertSame(50, BillingDetailsReport::LIST_ROWS, 'the screen shows 50 at most');

        // a list that has nothing to list is empty, not an error
        $this->assertSame(1, $report['lists']['large_adjustment']['total']);
    }

    public function test_the_nightly_purge_deletes_old_account_lists_orphaned_staging_and_marks_dead_runs_failed(): void
    {
        $batch = $this->importBilling();
        $lists = $batch->detailIssues()->count();
        $this->assertGreaterThan(0, $lists);
        $rollups = $batch->detailRollups()->count();

        // within the keep period (3 months) nothing goes
        Carbon::setTestNow('2027-01-10 03:35:00');
        Artisan::call('commercial:billing-details:purge');
        $this->assertSame($lists, $batch->detailIssues()->count());

        // an orphaned staging row (its batch is live, so nobody will use it) and a run that never finished
        DB::table('commercial_billing_detail_accounts')->insert(['batch_id' => $batch->id, 'account_no' => '100000000999', 'route_seq' => 1]);
        $stuck = $this->registerBilling($this->spec([$this->route('SOWUTUOM 4101', 700, 709)], 'August-2026 - August-2026'));
        DB::table('commercial_import_batches')->where('id', $stuck->id)->update(['updated_at' => now()->subDays(2)]);
        $fresh = $this->registerBilling($this->spec([$this->route('SOWUTUOM 4101', 800, 809)], 'July-2026 - July-2026'));

        Artisan::call('commercial:billing-details:purge', ['--dry-run' => true]);
        $this->assertSame(1, DB::table('commercial_billing_detail_accounts')->count(), 'a dry run deletes nothing');
        $this->assertSame(CommercialImportBatch::STATUS_QUEUED, $stuck->fresh()->status);

        Artisan::call('commercial:billing-details:purge');
        $this->assertSame(0, DB::table('commercial_billing_detail_accounts')->count());
        $this->assertSame(CommercialImportBatch::STATUS_FAILED, $stuck->fresh()->status, 'a day without moving: its worker died');
        $this->assertStringContainsString('did not finish', (string) $stuck->fresh()->error_message);
        $this->assertSame(CommercialImportBatch::STATUS_QUEUED, $fresh->fresh()->status, 'a run that is only just queued is left alone');

        // four months on: the account lists are gone, the figures (rollups, bands) are kept with their batch
        Carbon::setTestNow('2027-03-20 03:35:00');
        Artisan::call('commercial:billing-details:purge');
        $this->assertSame(0, $batch->detailIssues()->count());
        $this->assertSame($rollups, $batch->detailRollups()->count());
        $this->assertGreaterThan(0, $batch->detailBands()->count());

        // and a screen that finds the lists purged says so
        $this->actingAs($this->officer());
        $report = app(CommercialReportData::class)->billingDetails();
        $this->assertSame(0, $report['lists']['top_debtor']['total']);
        $this->assertSame(117, $report['analysis']['totals']['owing'], 'the count is in the rollups, not the list');
        $this->assertStringContainsString('have been deleted (the figures are kept)', implode(' ', $report['notes']));
    }

    public function test_the_new_settings_are_editable_and_stored_only_when_they_differ_from_the_default(): void
    {
        $settings = app(CommercialSettings::class);
        $definitions = CommercialSettings::definitions();

        foreach (['commercial_billing_detail_issue_cap', 'commercial_billing_detail_issue_keep_months', 'commercial_tariff_tolerance_pct', 'commercial_tariff_tolerance_amount'] as $key) {
            $this->assertArrayHasKey($key, $definitions);
            $this->assertSame('billing_details', $definitions[$key]['group']);
        }

        $this->assertSame(200, config('gwl.commercial_billing_detail_issue_cap'));
        $this->assertSame(3, config('gwl.commercial_billing_detail_issue_keep_months'));
        $this->assertSame(1.0, (float) config('gwl.commercial_tariff_tolerance_pct'));
        $this->assertSame(1.0, (float) config('gwl.commercial_tariff_tolerance_amount'));

        $settings->save(['commercial_billing_detail_issue_cap' => 50, 'commercial_billing_detail_issue_keep_months' => 6, 'commercial_tariff_tolerance_pct' => 2.5, 'commercial_tariff_tolerance_amount' => 0.5], $this->superAdmin()->id);
        $this->assertSame(50, (int) config('gwl.commercial_billing_detail_issue_cap'));
        $this->assertSame(6, (int) config('gwl.commercial_billing_detail_issue_keep_months'));
        $this->assertSame(2.5, (float) config('gwl.commercial_tariff_tolerance_pct'));

        // a value equal to the default is no override at all
        $settings->save(['commercial_billing_detail_issue_cap' => 200], $this->superAdmin()->id);
        $this->assertFalse(CommercialSetting::query()->where('name', 'commercial_billing_detail_issue_cap')->exists());

        // out of range is refused
        $this->expectException(ValidationException::class);
        $settings->save(['commercial_billing_detail_issue_cap' => 5], $this->superAdmin()->id);
    }

    // ---------------------------------------------------------------- the batch page

    public function test_the_page_of_a_queued_and_of_a_running_upload_shows_the_progress_and_refreshes_itself(): void
    {
        $this->actingAs($this->officer());
        $batch = $this->registerBilling();

        Livewire::test(BatchShow::class, ['batch' => $batch])->assertSee('being read in the background')->assertSee('waiting for a queue worker')->assertSeeHtml('wire:poll.3s');

        $batch->forceFill(['status' => CommercialImportBatch::STATUS_PROCESSING, 'phase' => 'reading', 'progress' => ['rows' => 24000, 'routes' => 88]])->save();
        Livewire::test(BatchShow::class, ['batch' => $batch])->assertSee('24,000 customer rows and 88 routes read so far')->assertSeeHtml('wire:poll.3s');

        // a finished one stops polling
        $done = $this->importBilling($this->spec([$this->route('SOWUTUOM 4101', 1, 5)], 'September-2026 - September-2026'));
        Livewire::test(BatchShow::class, ['batch' => $done])->assertDontSeeHtml('wire:poll');
    }

    public function test_the_check_before_loading_shows_what_the_file_holds_and_loading_it_makes_it_count(): void
    {
        $officer = $this->officer();
        $this->actingAs($officer);
        $batch = $this->importBilling([], ['review' => true]);

        $page = Livewire::test(BatchShow::class, ['batch' => $batch])
            ->assertSee('nothing counts in any analysis until you load it')->assertSee('Load these figures')->assertSee('Discard')
            ->assertSee('578')->assertSee('32,670.00')->assertSee('Bill status of the customers')->assertSee('Estimated (ESTIMATED)')->assertSee('Unbilled')
            ->assertSee('Districts in the file')->assertSee('Sowutuom')->assertSee('Odorkor')->assertSee('Amasaman')->assertSee('ATLANTIS (not matched)')
            ->assertSee('Routes that disagree with their own totals row')->assertSee('ODORKOR 4202')->assertSee('Reconciliation and integrity');

        // what the page shows is counts and figures: no customer name or meter number
        $this->assertStringNotContainsString('Sample Customer', $page->html());
        $this->assertStringNotContainsString('SMPL-', $page->html());

        $page->call('loadFigures')->assertHasNoErrors()->assertDontSee('Load these figures');
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->fresh()->status);

        // loaded, it can be voided like any other upload
        Livewire::test(BatchShow::class, ['batch' => $batch->fresh()])->assertSee('Void batch');
    }

    public function test_only_someone_who_may_upload_can_load_discard_or_run_again(): void
    {
        $batch = $this->importBilling([], ['review' => true]);
        $resolver = $this->userWith('900992', ['commercial.resolve_matches']);

        // may open the page (resolve_matches is batch work) but not load, discard or run it again
        $page = Livewire::actingAs($resolver)->test(BatchShow::class, ['batch' => $batch])->assertDontSee('Load these figures');
        $page->call('loadFigures')->assertForbidden();
        Livewire::actingAs($resolver)->test(BatchShow::class, ['batch' => $batch])->call('discardRun')->assertForbidden();
        Livewire::actingAs($resolver)->test(BatchShow::class, ['batch' => $batch])->call('runAgain')->assertForbidden();
        $this->assertSame(CommercialImportBatch::STATUS_AWAITING_REVIEW, $batch->fresh()->status);

        // a user of another region cannot open it at all
        $kumasi = District::query()->create(['region_id' => $this->ashanti->id, 'district_name' => 'Kumasi']);
        Livewire::actingAs($this->userWithRoles('900993', ['commercial_officer'], $this->ashanti, $kumasi))->test(BatchShow::class, ['batch' => $batch])->assertForbidden();
    }

    public function test_a_blocked_upload_says_why_and_can_be_run_again_or_discarded_and_a_failed_one_too(): void
    {
        Queue::fake();
        $officer = $this->officer();
        config(['gwl.commercial_reconcile_max_route_failures_pct' => 0.5, 'gwl.commercial_reconcile_grand_total_tolerance_pct' => 0.5]);
        $blocked = $this->importBilling();
        $this->assertSame(CommercialImportBatch::STATUS_BLOCKED, $blocked->status);

        $this->actingAs($officer);
        Livewire::test(BatchShow::class, ['batch' => $blocked])->assertSee('This file was blocked and nothing was loaded')->assertSee('disagree with their own totals row')->assertSee('Run it again')->assertSee('Discard');

        // widen the limit, run it again from the page: it is queued again (the worker does the work)
        $this->tolerant();
        Livewire::test(BatchShow::class, ['batch' => $blocked])->call('runAgain')->assertHasNoErrors();
        $this->assertSame(CommercialImportBatch::STATUS_QUEUED, $blocked->fresh()->status);
        Queue::assertPushed(ProcessBillingDetailsBatch::class, fn ($job) => $job->batchId === $blocked->id);

        // a failed one: its sentence, and discard sends the person back to the uploads
        $failed = $this->registerBilling($this->spec([$this->route('SOWUTUOM 4101', 600, 604)], 'June-2026 - June-2026'));
        $failed->forceFill(['status' => CommercialImportBatch::STATUS_FAILED, 'phase' => 'failed', 'error_message' => 'The import stopped unexpectedly (RuntimeException). Run it again; if it keeps failing, tell the administrator.'])->save();
        Livewire::test(BatchShow::class, ['batch' => $failed])->assertSee('The import stopped: The import stopped unexpectedly (RuntimeException)')->call('discardRun')->assertRedirect(route('commercial.batches'));
        $this->assertNull(CommercialImportBatch::query()->find($failed->id));
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.billing_details_discarded')->count());

        // a run that is still going cannot be discarded
        $running = $this->registerBilling($this->spec([$this->route('SOWUTUOM 4101', 610, 614)], 'May-2026 - May-2026'));
        Livewire::test(BatchShow::class, ['batch' => $running])->call('discardRun')->assertHasErrors('run');
        $this->assertNotNull(CommercialImportBatch::query()->find($running->id));
    }

    public function test_the_unplaced_district_is_resolved_from_the_batch_page_and_the_batch_is_voided_from_it(): void
    {
        $officer = $this->officer();
        $this->actingAs($officer);
        $batch = $this->importBilling();

        $page = Livewire::test(BatchShow::class, ['batch' => $batch])->assertSee('Districts not matched')->assertSee('ATLANTIS')->assertSee('Resolve');
        $page->call('startResolvingDistrict', 'ATLANTIS')->set('resolveDistrictId', $this->odorkor->id)->call('saveDistrictResolution')->assertHasNoErrors();

        $this->assertSame(0, $batch->detailRollups()->whereNull('route_id')->count());
        Livewire::test(BatchShow::class, ['batch' => $batch->fresh()])->assertDontSee('Districts not matched');

        // a district label that is not on the batch is a 404
        Livewire::test(BatchShow::class, ['batch' => $batch->fresh()])->call('startResolvingDistrict', 'NOWHERE')->assertNotFound();

        // void it: the figures leave every screen, the batch stays on record
        Livewire::test(BatchShow::class, ['batch' => $batch->fresh()])->call('startVoid')->set('voidReason', 'loaded the wrong month')->call('voidBatch')->assertHasNoErrors();
        $this->assertSame(CommercialImportBatch::STATUS_VOIDED, $batch->fresh()->status);
        $this->assertFalse(app(CommercialReportData::class)->billingDetails()['available']);
        $this->assertSame(0, DB::table('commercial_billing_detail_accounts')->count());
    }

    public function test_the_uploads_page_has_the_background_form_and_lists_the_run_in_its_own_words(): void
    {
        $officer = $this->officer();
        $this->actingAs($officer);
        $this->importBilling([], ['review' => true]);

        Livewire::test(Batches::class)->assertSee('Detailed billing report (one row per customer)')->assertSee('Detailed billing')->assertSee('Waiting for you to check it')->assertSee('1 Sep to 21 Sep 2026');
        $this->get(route('commercial.batches'))->assertOk()->assertSee('data-billing-details-form', false)->assertSee('commercial/batches/billing-details', false);

        // nobody who may not upload sees the form
        $viewer = $this->userWith('900994', ['commercial.void_batches']);
        Livewire::actingAs($viewer)->test(Batches::class)->assertDontSee('Detailed billing report (one row per customer)');
    }

    /** A user holding exactly the given permissions (all districts of their region). */
    protected function userWith(string $staffId, array $permissions): User
    {
        $role = Role::query()->create(['name' => 'custom_'.$staffId, 'display_name' => 'Custom '.$staffId, 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('name', [...$permissions, 'commercial.view_all_districts'])->pluck('id'));
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_COMMERCIAL, 'can_access' => true]);

        $user = $this->userWithRoles($staffId, [], $this->accraWest, $this->sowutuom);
        $user->roles()->attach($role);

        return $user->fresh();
    }
}
