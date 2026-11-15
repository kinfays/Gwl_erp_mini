<?php

namespace Tests\Feature\Commercial;

use App\Livewire\Commercial\BillingDetails;
use App\Models\CommercialCustomer;
use App\Services\Commercial\Customers\CustomerListService;
use App\Services\Commercial\Customers\CustomerScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\BuildsCrossSet;
use Tests\Support\Commercial\BuildsRouteReading;

/**
 * Phase 8e leaves two things exactly as they were: WHO SEES WHICH CUSTOMER (a district user still sees the customers the customer LIST puts in their
 * district, however the billing report and the district matrix say the routes are placed), and the Billing details screens, which now name their CCA
 * through RouteCcaResolver and say which reading upload (and whether it is the bill period) they used. The old figures are pinned by
 * ExistingFiguresPinnedTest, which this phase does not touch.
 */
class CrossFileUnchangedTest extends CustomerTestCase
{
    use BuildsBillingDetails;
    use BuildsCrossSet;
    use BuildsRouteReading;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The ids of every customer a district user can open (CustomerListService::find is the one door to a single customer). @return list<int> */
    protected function visibleTo(\App\Models\User $user): array
    {
        $service = app(CustomerListService::class);

        return CommercialCustomer::query()->orderBy('id')->pluck('id')->filter(
            fn ($id) => $service->find((int) $id, CustomerScope::regionRestriction($user), CustomerScope::districtIds($user)) !== null
        )->map(fn ($id) => (int) $id)->values()->all();
    }

    public function test_loading_the_billing_report_and_the_district_matrix_change_nobodys_customers(): void
    {
        Carbon::setTestNow('2026-11-16 09:00:00');
        $this->setUpBillingDetails();
        config(['gwl.commercial_customer_list_enabled' => true]);

        // the customer list alone first (as the sample command does)
        $files = \App\Services\Commercial\Cross\CrossSampleSet::write(sys_get_temp_dir().'/cross-un-'.uniqid(), ['SOWUTUOM', 'ODORKOR'], 'ACCRA WEST');
        $this->workbooks = array_merge($this->workbooks, array_values($files));
        $imports = app(\App\Services\Commercial\Customers\CustomerImportService::class);
        ['batch' => $batch] = $imports->register($files['customers'], 'customers.xlsx', 'monthly', Carbon::parse('2026-11-15'), null);
        $imports->process($batch);

        $s = $this->userWith('900101', ['commercial.view_customer_analytics'], $this->accraWest, $this->sowutuom, true);
        $o = $this->userWith('900102', ['commercial.view_customer_analytics'], $this->accraWest, $this->odorkor, true);
        $all = $this->userWith('900103', ['commercial.view_customer_analytics'], $this->accraWest, $this->sowutuom, false);

        $before = ['s' => $this->visibleTo($s), 'o' => $this->visibleTo($o), 'all' => $this->visibleTo($all)];
        $columns = fn () => DB::table('commercial_customers')->orderBy('id')->get()->map(fn ($r) => collect((array) $r)->except('ops_route_id')->all())->all();
        $rowsBefore = $columns();

        // now the reading and the billing report (which links the list and works out the matrix)
        $preview = app(\App\Services\Commercial\CommercialImportService::class)->preview(new \Illuminate\Http\UploadedFile($files['reading'], 'reading.xlsx', null, null, true));
        app(\App\Services\Commercial\CommercialImportService::class)->createBatch([], $preview, null, null);
        ['batch' => $queued] = app(\App\Services\Commercial\BillingDetails\BillingDetailsImportService::class)->register($files['billing'], 'billing.xlsx', null);
        $billing = app(\App\Services\Commercial\BillingDetails\BillingDetailsImportService::class)->process($queued);

        $this->assertSame('imported', $billing->status, (string) $billing->error_message);
        $this->assertSame(61, $billing->control_totals['link']['compared'], 'the link and the matrix were made');
        $this->assertGreaterThan(0, DB::table('commercial_customers')->whereNotNull('ops_route_id')->count());

        $this->assertSame($before, ['s' => $this->visibleTo($s), 'o' => $this->visibleTo($o), 'all' => $this->visibleTo($all)], 'nobody gained or lost a customer');
        $this->assertSame($rowsBefore, $columns(), 'no column of the customer list moved (only ops_route_id was filled)');

        // a Sowutuom user still sees the 48 accounts the list puts in Sowutuom, among them the 10 on route 4202, which billing says is Odorkor's
        $this->assertCount(48, $before['s']);
        $this->assertCount(19, $before['o']);
        $this->assertCount(67, $before['all']);
        $this->assertTrue(CommercialCustomer::query()->whereIn('id', $before['s'])->where('account_no', '100000000081')->exists());
    }

    public function test_the_billing_details_screen_names_the_reading_upload_its_ccas_come_from(): void
    {
        $this->setUpBillingDetails();
        $this->loadCrossSet();
        $this->actingAs($this->crossAll());

        // the bill is September, the only reading upload is October: none overlaps, so the newest is used and the page says it is not the bill period
        Livewire::test(BillingDetails::class)->assertSee('The CCA beside each route is the owner of that route in the reading report by route of Oct 2026')->assertSee('this is not the bill period');

        // a reading upload that covers part of September becomes the one the CCAs come from
        $overlap = $this->seedRouteReading([['80009', 'KOFI OVERLAP', '4101', $this->sowutuom, 10, 5, 1, 6]], ['from' => '2026-09-10', 'to' => '2026-09-20']);
        Livewire::test(BillingDetails::class)->assertSee('the upload that overlaps the bill period the most (11 days in common)')->assertSee('80009 KOFI OVERLAP')->assertDontSee('this is not the bill period');
        $this->assertGreaterThan(0, $overlap->id);
    }
}
