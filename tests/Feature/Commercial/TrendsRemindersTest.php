<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\Batches;
use App\Livewire\Commercial\Home;
use App\Models\CommercialImportBatch;
use App\Models\CommercialReminderState;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Commercial\UploadReminderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsRouteReading;

/**
 * Phase 8f: upload reminders for the two route-grain reports (reading by route and the Detailed Billing Report). "Today" is 15 Oct 2026; the existing
 * limits apply (reading 10 days, billing 35), with no new setting. Invented data only.
 */
class TrendsRemindersTest extends CustomerTestCase
{
    use BuildsBillingDetails;
    use BuildsRouteReading;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->setUpBillingDetails();
        config(['gwl.commercial_customer_list_enabled' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A reading-by-route upload with a row in the given districts, that arrived on $day. */
    protected function readingUpload(string $day, array $districts, array $options = []): CommercialImportBatch
    {
        $rows = [];

        foreach ($districts as $i => $district) {
            $rows[] = ['8000'.$i, 'CCA TEST '.$i, 'Z'.$i, $district, 10, 8, 2, 10];
        }

        $batch = $this->seedRouteReading($rows, $options);
        $batch->forceFill(['imported_at' => $day.' 09:00:00'])->save();

        return $batch->fresh();
    }

    /** A Detailed Billing upload with a route in each of the given districts (by the printed label), that arrived on $day. */
    protected function billingUpload(string $day, array $labels): CommercialImportBatch
    {
        $routes = [];

        foreach ($labels as $i => $label) {
            $routes[] = $this->route($label, 1 + $i * 10, 5 + $i * 10);
        }

        $batch = $this->importBilling($this->spec($routes, '01-October-2026 - 07-October-2026'));
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->fresh()->status, (string) $batch->error_message);
        $batch->forceFill(['imported_at' => $day.' 09:00:00'])->save();

        return $batch->fresh();
    }

    protected function all()
    {
        return CommercialImportBatch::query()->notVoided()->with('region')->get();
    }

    public function test_the_route_grain_reports_are_overdue_on_the_same_two_limits(): void
    {
        $this->readingUpload('2026-10-01', [$this->sowutuom]);      // 14 days > the reading limit of 10: overdue
        $this->billingUpload('2026-09-20', ['SOWUTUOM 4101']);       // 25 days <= the billing limit of 35: fine

        $overdue = (new UploadReminderService)->overdue($this->all(), now());

        $this->assertCount(1, $overdue);
        $this->assertSame([CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY, 'reading by route', 14, 10], [$overdue[0]['report_type'], $overdue[0]['label'], $overdue[0]['days'], $overdue[0]['limit']]);

        // the billing upload goes over its OWN limit
        $this->travelTo(Carbon::parse('2026-10-26 10:00:00'));
        $late = collect((new UploadReminderService)->overdue($this->all(), now()))->keyBy('report_type');
        $this->assertSame([36, 35, 'detailed billing'], [$late['billing_details']['days'], $late['billing_details']['limit'], $late['billing_details']['label']]);
    }

    public function test_only_live_uploads_count_for_the_route_grain_reports(): void
    {
        $this->readingUpload('2026-10-01', [$this->sowutuom]);
        // a newer upload that never went live (waiting for a decision, blocked or failed) is not an upload that arrived
        $this->readingUpload('2026-10-14', [$this->sowutuom])->forceFill(['status' => CommercialImportBatch::STATUS_AWAITING_REVIEW])->save();
        $this->readingUpload('2026-10-14', [$this->sowutuom])->forceFill(['status' => CommercialImportBatch::STATUS_BLOCKED])->save();

        $overdue = (new UploadReminderService)->overdue($this->all(), now());

        $this->assertCount(1, $overdue);
        $this->assertSame(14, $overdue[0]['days']);
    }

    public function test_the_regional_officers_are_told_the_region_and_a_district_user_is_not_told_that(): void
    {
        $this->readingUpload('2026-10-01', [$this->sowutuom, $this->odorkor]);
        $officer = $this->officer('900901');
        $districtUser = $this->userWith('900902', ['commercial.upload_reports'], $this->accraWest, $this->sowutuom, true);

        $summary = app(UploadReminderService::class)->remind();

        $this->assertSame(2, $summary['reminded'], 'the region and Sowutuom: Odorkor and Head Office have nobody to tell');
        Notification::assertSentTo($officer, GeneralDatabaseNotification::class, fn ($n) => str_contains($n->toArray($officer)['title'], 'Reading by route upload overdue: Accra West'));
        Notification::assertSentTo($districtUser, GeneralDatabaseNotification::class, fn ($n) => str_contains($n->toArray($districtUser)['title'], 'Reading by route upload overdue: Sowutuom'));
        Notification::assertNotSentTo($officer, GeneralDatabaseNotification::class, fn ($n) => str_contains($n->toArray($officer)['title'], 'Sowutuom'));
    }

    public function test_a_district_user_is_reminded_only_about_their_own_district(): void
    {
        // Sowutuom's last upload is old; Odorkor's is fresh. The newest upload of the region is fresh, so the REGION is not overdue.
        $this->readingUpload('2026-10-01', [$this->sowutuom]);
        $this->readingUpload('2026-10-14', [$this->odorkor]);
        $sowutuom = $this->userWith('900903', ['commercial.upload_reports'], $this->accraWest, $this->sowutuom, true);
        $odorkor = $this->userWith('900904', ['commercial.upload_reports'], $this->accraWest, $this->odorkor, true);
        $officer = $this->officer('900905');

        app(UploadReminderService::class)->remind();

        Notification::assertSentTo($sowutuom, GeneralDatabaseNotification::class, function ($n) use ($sowutuom) {
            $d = $n->toArray($sowutuom);

            return str_contains($d['title'], 'Reading by route upload overdue: Sowutuom') && str_contains($d['message'], '14 days') && str_contains($d['message'], 'limit is 10');
        });
        Notification::assertNotSentTo($odorkor, GeneralDatabaseNotification::class);
        Notification::assertNotSentTo($officer, GeneralDatabaseNotification::class);
    }

    public function test_a_district_with_no_upload_while_its_region_has_uploaded_is_overdue(): void
    {
        $this->readingUpload('2026-10-14', [$this->sowutuom]);

        $items = collect((new UploadReminderService)->overdueDistricts(now(), [$this->odorkor->id], $this->all()));

        $this->assertCount(1, $items);
        $this->assertSame('Odorkor', $items[0]['district']);
        $this->assertNull($items[0]['last_upload']);
        $this->assertNull($items[0]['days']);
    }

    public function test_district_reminders_repeat_only_after_the_interval_and_keep_their_own_state(): void
    {
        $this->readingUpload('2026-10-01', [$this->sowutuom]);
        $user = $this->userWith('900906', ['commercial.upload_reports'], $this->accraWest, $this->sowutuom, true);

        $first = app(UploadReminderService::class)->remind();
        $again = app(UploadReminderService::class)->remind();

        $this->assertSame(2, $first['reminded'], 'the region (nobody to tell) and the district');
        $this->assertSame(0, $again['reminded']);
        $this->assertSame(2, $again['skipped']);
        $this->assertTrue(CommercialReminderState::query()->where('report_type', 'reading_route_summary@d'.$this->sowutuom->id)->exists());
        $this->assertTrue(CommercialReminderState::query()->where('report_type', 'reading_route_summary')->exists());
        Notification::assertSentToTimes($user, GeneralDatabaseNotification::class, 1);

        $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));
        $this->assertSame(2, app(UploadReminderService::class)->remind()['reminded'], 'after the repeat interval of 7 days');
    }

    public function test_the_notification_names_no_customer_cca_or_route(): void
    {
        $this->readingUpload('2026-10-01', [$this->sowutuom]);
        $this->userWith('900907', ['commercial.upload_reports'], $this->accraWest, $this->sowutuom, true);
        $officer = $this->officer('900908');

        app(UploadReminderService::class)->remind();

        foreach (Notification::sent($officer, GeneralDatabaseNotification::class) as $n) {
            $text = json_encode($n->toArray($officer));
            $this->assertStringNotContainsString('CCA TEST', $text);
            $this->assertStringNotContainsString('Z0', $text);
        }

        $this->assertCount(1, Notification::sent($officer, GeneralDatabaseNotification::class));
    }

    public function test_the_dry_run_sends_nothing_and_the_command_lists_the_districts(): void
    {
        $this->readingUpload('2026-10-01', [$this->sowutuom]);
        $user = $this->userWith('900909', ['commercial.upload_reports'], $this->accraWest, $this->sowutuom, true);

        $summary = app(UploadReminderService::class)->remind(dryRun: true);

        $this->assertSame(5, $summary['overdue']);   // the region and the four districts of it
        $this->assertSame(2, $summary['reminded']);
        Notification::assertNotSentTo($user, GeneralDatabaseNotification::class);
        $this->assertSame(0, CommercialReminderState::query()->count());
    }

    public function test_the_billing_details_district_rows_decide_which_district_is_late(): void
    {
        $this->billingUpload('2026-09-01', ['SOWUTUOM 4101', 'ODORKOR 4201']);   // 44 days: both late
        $this->billingUpload('2026-10-10', ['ODORKOR 4201']);                      // Odorkor is fresh again

        $items = collect((new UploadReminderService)->overdueDistricts(now(), null, $this->all()))->where('report_type', 'billing_details');

        $this->assertContains('Sowutuom', $items->pluck('district')->all());
        $this->assertNotContains('Odorkor', $items->pluck('district')->all());
        $this->assertSame(44, $items->firstWhere('district', 'Sowutuom')['days']);
    }

    public function test_the_uploads_page_home_and_summary_show_them(): void
    {
        $this->readingUpload('2026-10-01', [$this->sowutuom]);
        $officer = $this->officer('900910');

        $this->actingAs($officer);
        Livewire::test(Batches::class)->assertSee('Reading by route upload overdue for Accra West');
        Livewire::test(Home::class)->assertSee('Reading by route upload overdue for Accra West');

        // a district user sees their own district's line (and not the region's)
        $districtUser = $this->userWith('900911', ['commercial.upload_reports', 'commercial.view_dashboard'], $this->accraWest, $this->sowutuom, true);
        $this->actingAs($districtUser);
        Livewire::test(Batches::class)->assertSee('Reading by route upload overdue for Sowutuom')->assertDontSee('overdue for Accra West');
        Livewire::test(Home::class)->assertSee('Reading by route upload overdue for Sowutuom');
    }
}
