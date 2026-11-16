<?php

namespace Tests\Feature\Commercial\Customers;

use App\Livewire\Commercial\Customers\Dashboard;
use App\Livewire\Commercial\Customers\Uploads;
use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerImportException;
use App\Services\Commercial\Customers\CustomerImportService;
use App\Services\Commercial\Trends\TrendReport;
use App\Services\Commercial\Trends\UploadTimeliness;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8f: the customer list accepts any as-of date and the period type "daily" (the period key is the date). The trends and the timeliness calendar label a
 * daily snapshot by its date. Existing weekly and monthly batches are unchanged. Invented data.
 */
class CustomerDailyPeriodTest extends CustomerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['gwl.commercial_customer_list_enabled' => true]);
        Carbon::setTestNow('2026-11-16 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_daily_upload_is_keyed_by_its_date_and_weekly_and_monthly_are_unchanged(): void
    {
        $daily = $this->loadCustomers($this->simpleSpec(1, 5), '2026-10-24', 'daily');
        $weekly = $this->loadCustomers($this->simpleSpec(1, 5, [], '1002'), '2026-10-31', 'weekly');
        $monthly = $this->loadCustomers($this->simpleSpec(1, 5, [], '1003'), '2026-11-07', 'monthly');

        $this->assertSame(['daily', '2026-10-24'], [$daily->period_type, $daily->period_key]);
        $this->assertSame(['weekly', '2026-W44'], [$weekly->period_type, $weekly->period_key], 'the ISO week, as it always was');
        $this->assertSame(['monthly', '2026-11'], [$monthly->period_type, $monthly->period_key]);
        $this->assertSame('2026-10-24', CommercialCustomerBatch::periodKey('daily', Carbon::parse('2026-10-24')));
        $this->assertSame('2026-W44', CommercialCustomerBatch::periodKey('weekly', Carbon::parse('2026-10-31')));
        $this->assertSame('2026-10', CommercialCustomerBatch::periodKey('monthly', Carbon::parse('2026-10-31')));
    }

    public function test_any_as_of_date_is_accepted_and_an_unknown_period_type_is_not(): void
    {
        // a Wednesday in the middle of a month, neither a week's nor a month's end
        $batch = $this->loadCustomers($this->simpleSpec(1, 3), '2026-10-14', 'daily');
        $this->assertSame('2026-10-14', $batch->as_of_date->toDateString());

        $path = ReportWorkbooks::customerList($this->simpleSpec(1, 3));
        $this->workbooks[] = $path;

        try {
            app(CustomerImportService::class)->register($path, 'x.xlsx', 'hourly', Carbon::parse('2026-10-15'), null);
            $this->fail('an unknown period type must be refused');
        } catch (CustomerImportException $e) {
            $this->assertSame('The period must be daily, weekly or monthly.', $e->getMessage());
        }
    }

    public function test_the_upload_form_offers_daily_and_the_controller_accepts_it(): void
    {
        $user = $this->userWith('900601', ['commercial.view_customer_analytics', 'commercial.upload_reports'], $this->accraWest, $this->sowutuom);
        $this->actingAs($user);

        Livewire::test(Uploads::class)->assertSeeHtml('<option value="daily"');

        $path = ReportWorkbooks::customerList($this->simpleSpec(1, 3));
        $this->workbooks[] = $path;
        $file = new UploadedFile($path, 'rptCustomerDetails.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($user)->post(route('commercial.customers.upload'), ['file' => $file, 'period_type' => 'daily', 'as_of_date' => '2026-10-24'])->assertSessionHasNoErrors();
        $this->assertSame(['daily', '2026-10-24'], [CommercialCustomerBatch::query()->value('period_type'), CommercialCustomerBatch::query()->value('period_key')]);

        $this->actingAs($user)->post(route('commercial.customers.upload'), ['file' => $file, 'period_type' => 'hourly', 'as_of_date' => '2026-10-25'])->assertSessionHasErrors('period_type');
    }

    public function test_the_trends_label_a_daily_snapshot_by_its_date_and_compare_the_two_newest(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5), '2026-10-24', 'daily');
        // eight customers, the last three with no mobile number: 8 billing accounts, 5 of 8 contactable
        $three = $this->customers(6, 8, ['mobile' => null]);
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => array_merge($this->customers(1, 5), $three)]]], '2026-10-31', 'daily');

        $user = $this->userWith('900602', ['commercial.view_customer_analytics'], $this->accraWest, $this->sowutuom);
        $this->actingAs($user);
        $r = app(TrendReport::class)->build('t2', ['region' => $this->accraWest->id]);
        $part = $r['parts']['customers']['data'];

        $this->assertSame('as at 31 Oct 2026', $part['current']['label']);
        $this->assertSame('as at 24 Oct 2026', $part['compared']['label']);
        $row = $part['districts'][0];
        $this->assertSame(['31 Oct 2026', '24 Oct 2026'], [$row['now_label'], $row['then_label']]);
        $this->assertSame(7, $row['days_apart']);

        $m = collect($row['measures'])->keyBy('key');
        $this->assertSame([8, 5, 3], [(int) $m['billing_accounts']['now'], (int) $m['billing_accounts']['then'], (int) $m['billing_accounts']['change']]);
        $this->assertEquals(60.0, $m['billing_accounts']['change_pct']);
        $this->assertEquals(62.5, $m['contactable_share']['now']);
        $this->assertEquals(100.0, $m['contactable_share']['then']);
        $this->assertEquals(-37.5, $m['contactable_share']['change']);
        $this->assertSame('bad', $m['contactable_share']['tone']);

        // the same labels on the timeliness calendar (imported the day it was loaded, "today" in this test)
        $t8 = app(TrendReport::class)->build('t8', ['region' => $this->accraWest->id, 'month' => '2026-10'])['parts']['customers']['data'];
        $this->assertContains('24 Oct 2026', array_column($t8['uploads'], 'label'));
        $this->assertContains('31 Oct 2026', array_column($t8['uploads'], 'label'));
        $this->assertSame(2, collect($t8['districts'])->firstWhere('id', $this->sowutuom->id)['covered_days'], 'a daily snapshot covers its own day');

        $this->assertSame('24 Oct 2026', UploadTimeliness::snapshotLabel(CommercialCustomerBatch::query()->where('period_key', '2026-10-24')->firstOrFail()));
    }

    public function test_the_dashboard_lists_a_daily_snapshot_by_its_date(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5), '2026-10-24', 'daily');
        $this->actingAs($this->analyst('900603', $this->accraWest, $this->sowutuom));

        Livewire::test(Dashboard::class)->assertSeeHtml('2026-10-24 (daily, as of 24 Oct 2026)');
    }
}
