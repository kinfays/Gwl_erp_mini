<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialBillingDetailDistrictCell;
use App\Models\CommercialBillingDetailIssue;
use App\Models\CommercialBillingDetailTransition;
use App\Models\CommercialImportBatch;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\BillingDetails\BillingDetailsSampleFile;
use App\Services\Commercial\Customers\CustomerScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsBillingDetails;
use Tests\Support\Commercial\ReportWorkbooks;

/**
 * Phase 8e step 0.2: what the customer-list link says about the accounts of a Detailed Billing Report. The staging table keeps the status
 * and the service category AS BILLED (codes only); when the batch goes live every linked account's billing-time status and category are set
 * against its CURRENT ones (per route), the district of its billing route against the district the list puts it in (the matrix), and the
 * accounts whose status moved are listed (capped, amount 0). Invented accounts only; the arithmetic is in the comments.
 *
 * THE LIST (loaded first, district Sowutuom route 1001 and district Odorkor route 3001), as of now:
 *     1 ACTB 611    2 DISC 611    3 ACTB 611    4 ACTB 611    5 ACTB 611    7 ACTB 611 (not billed)    8 DISC 611 (not billed)   | Odorkor 901 902 903 ACTB 611
 * THE BILL (Sowutuom 4101 and 4102, September 2026):
 *     4101:  1 ACTB 611 (same)   2 ACTB 611 (now DISC: status moved)   3 ACTB 612 (category moved)   4 DISC 611 (now ACTB: status moved)   5 ACTB 611 (same)   6 ACTB 611 (not in the list)
 *     4102:  901 902 903 ACTB 611 (the list puts them in Odorkor: the bill puts the route in Sowutuom)
 */
class BillingDetailsLinkAnalysisTest extends CustomerTestCase
{
    use BuildsBillingDetails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBillingDetails();
        $this->tolerant();
        config(['gwl.commercial_customer_list_enabled' => true]);
    }

    protected function loadTheList(): void
    {
        $list = fn (array $ns, array $over = []) => array_map(fn (int $n) => ReportWorkbooks::customer($n, $over), $ns);

        $this->loadCustomers(['routes' => [[
            'name' => '1001',
            'customers' => array_merge($list([1]), $list([2], ['status' => 'DISC']), $list([3, 4, 5, 7]), $list([8], ['status' => 'DISC'])),
        ]]]);
        $this->loadCustomers($this->simpleSpec(901, 903, ['district' => 'ODORKOR'], '3001'));
    }

    protected function theBill(array $period = ['2026-09-01', '2026-09-30']): array
    {
        $c = fn (int $n, array $over = []) => BillingDetailsSampleFile::customer($n, $over);

        return $this->spec([
            ['label' => 'SOWUTUOM 4101', 'customers' => [$c(1), $c(2), $c(3, ['category' => '612']), $c(4, ['status' => 'DISC']), $c(5), $c(6)]],
            ['label' => 'SOWUTUOM 4102', 'customers' => [$c(901), $c(902), $c(903)]],
        ], 'September-2026 - September-2026');
    }

    protected function transitions(CommercialImportBatch $batch): array
    {
        return CommercialBillingDetailTransition::query()->where('batch_id', $batch->id)->get()
            ->mapWithKeys(fn ($t) => [$t->route_id.'|'.$t->from_status.'>'.$t->to_status.'|'.$t->from_category.'>'.$t->to_category => $t->customers])->all();
    }

    public function test_the_staging_table_keeps_the_status_and_category_as_billed_while_the_batch_is_waiting(): void
    {
        $this->loadTheList();
        $batch = $this->importBilling($this->theBill(), ['review' => true]);
        $this->assertSame(CommercialImportBatch::STATUS_AWAITING_REVIEW, $batch->status, (string) $batch->error_message);

        $row = DB::table('commercial_billing_detail_accounts')->where('batch_id', $batch->id)->where('account_no', '100000000004')->first();
        $this->assertSame('DISC', $row->status_code);
        $this->assertSame('611', $row->category_code);
        $this->assertSame('612', DB::table('commercial_billing_detail_accounts')->where('batch_id', $batch->id)->where('account_no', '100000000003')->value('category_code'));

        // nothing is compared before the batch is loaded
        $this->assertSame(0, CommercialBillingDetailTransition::query()->where('batch_id', $batch->id)->count());

        app(BillingDetailsImportService::class)->confirm($batch);

        $this->assertSame(0, DB::table('commercial_billing_detail_accounts')->where('batch_id', $batch->id)->count(), 'the staging table is emptied when the batch goes live');
        $this->assertGreaterThan(0, CommercialBillingDetailTransition::query()->where('batch_id', $batch->id)->count(), 'and what it said is kept as counts');
    }

    public function test_every_linked_account_is_set_against_its_current_status_and_category_per_route(): void
    {
        $this->loadTheList();
        $batch = $this->importBilling($this->theBill());
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->status, (string) $batch->error_message);

        $r4101 = (int) DB::table('commercial_billing_detail_rollups')->where('batch_id', $batch->id)->where('route_label_raw', 'SOWUTUOM 4101')->value('route_id');
        $r4102 = (int) DB::table('commercial_billing_detail_rollups')->where('batch_id', $batch->id)->where('route_label_raw', 'SOWUTUOM 4102')->value('route_id');

        // route 4101: accounts 1 and 5 did not move (2); 2 went ACTB to DISC; 3 moved category 612 to 611; 4 went DISC to ACTB; account 6 is not in the list: 5 compared
        // route 4102: 901 902 903 did not move (3): 8 compared in all
        $this->assertEquals([
            $r4101.'|ACTB>ACTB|611>611' => 2,
            $r4101.'|ACTB>DISC|611>611' => 1,
            $r4101.'|ACTB>ACTB|612>611' => 1,
            $r4101.'|DISC>ACTB|611>611' => 1,
            $r4102.'|ACTB>ACTB|611>611' => 3,
        ], $this->transitions($batch));

        $link = $batch->fresh()->control_totals['link'];
        $this->assertSame(8, $link['compared']);
        $this->assertSame(2, $link['status_changed'], 'accounts 2 and 4');
        $this->assertSame(1, $link['category_changed'], 'account 3');

        // the rows hold the BILLING district of the route (Sowutuom both)
        $this->assertSame([$this->sowutuom->id], CommercialBillingDetailTransition::query()->where('batch_id', $batch->id)->distinct()->pluck('district_id')->all());
    }

    public function test_the_district_of_the_billing_route_is_set_against_the_district_of_the_list(): void
    {
        $this->loadTheList();
        $batch = $this->importBilling($this->theBill());

        // accounts 1 to 5 are in the list's Sowutuom, 901 to 903 in its Odorkor, and the bill puts every one of them on a Sowutuom route
        $cells = CommercialBillingDetailDistrictCell::query()->where('batch_id', $batch->id)->get()
            ->mapWithKeys(fn ($c) => [$c->billing_district_id.'>'.$c->list_district_id => $c->customers])->all();

        $this->assertEquals([$this->sowutuom->id.'>'.$this->sowutuom->id => 5, $this->sowutuom->id.'>'.$this->odorkor->id => 3], $cells);
    }

    public function test_the_accounts_whose_status_moved_are_listed_with_no_figure_and_no_name(): void
    {
        $this->loadTheList();
        $batch = $this->importBilling($this->theBill());

        $rows = CommercialBillingDetailIssue::query()->where('batch_id', $batch->id)->where('kind', CommercialBillingDetailIssue::STATUS_CHANGED)->orderBy('account_no')->get();

        $this->assertSame(['100000000002', '100000000004'], $rows->pluck('account_no')->all());
        $this->assertSame([0.0, 0.0], $rows->pluck('amount')->all());
        $this->assertSame([$this->sowutuom->id], $rows->pluck('district_id')->unique()->values()->all());
        $this->assertSame(['SOWUTUOM 4101'], $rows->pluck('route_label_raw')->unique()->values()->all());
    }

    public function test_the_status_changed_list_is_capped_per_district_and_keeps_counting_every_account(): void
    {
        $this->loadTheList();
        config(['gwl.commercial_billing_detail_issue_cap' => 1]);

        $batch = $this->importBilling($this->theBill());

        $this->assertSame(1, CommercialBillingDetailIssue::query()->where('batch_id', $batch->id)->where('kind', CommercialBillingDetailIssue::STATUS_CHANGED)->count());
        $this->assertSame(2, $batch->fresh()->control_totals['link']['status_changed'], 'the count covers every account, not just the ones listed');
    }

    public function test_accounts_in_one_file_and_not_the_other_are_counted_per_district(): void
    {
        $this->loadTheList();
        $batch = $this->importBilling($this->theBill());
        $link = $batch->fresh()->control_totals['link'];

        // billing district Sowutuom: 6 accounts on 4101 and 3 on 4102 = 9; the list holds 8 of them (account 6 is not there)
        $this->assertSame(['accounts' => 9, 'in_list' => 8, 'not_in_list' => 1], $link['by_district'][$this->sowutuom->id]);
        $this->assertSame(9, $link['accounts']);
        $this->assertSame(1, $link['not_in_customer_list']);

        // the list's Sowutuom and Odorkor hold accounts 7 (ACTB) and 8 (DISC) that the file does not: both are "not in the file", only 7 is a BILLING status
        $this->assertSame(2, $link['list_accounts_not_in_file']);
        $this->assertSame([$this->sowutuom->id => 1], $link['list_billing_not_in_file_by_district']);
    }

    public function test_a_second_link_with_nothing_staged_keeps_what_was_kept_and_an_older_upload_still_describes_itself(): void
    {
        $this->loadTheList();
        $batch = $this->importBilling($this->theBill());
        $first = $this->transitions($batch);

        app(BillingDetailsImportService::class)->linkCustomers($batch->fresh());   // the staging table is empty now: nothing to compare, and what was kept is not lost
        $this->assertSame($first, $this->transitions($batch), 'a link with nothing staged changes nothing');
        $this->assertNotEmpty($first);

        // an upload for an EARLIER period is not the newest of the district (no ops_route_id), yet its movements are still worked out
        $older = $this->importBilling($this->spec([['label' => 'SOWUTUOM 4101', 'customers' => [BillingDetailsSampleFile::customer(1), BillingDetailsSampleFile::customer(2)]]], 'August-2026 - August-2026'));
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $older->status, (string) $older->error_message);
        $this->assertSame(1, $older->fresh()->control_totals['link']['routes_left_to_a_newer_upload']);
        $this->assertSame(2, $older->fresh()->control_totals['link']['compared']);
        $this->assertSame(2, array_sum($this->transitions($older)));
    }

    public function test_nothing_is_written_when_the_customer_list_is_off_or_empty(): void
    {
        config(['gwl.commercial_customer_list_enabled' => false]);
        $batch = $this->importBilling($this->theBill());
        $this->assertFalse($batch->fresh()->control_totals['link']['available']);
        $this->assertSame(0, CommercialBillingDetailTransition::query()->count());
        $this->assertSame(0, CommercialBillingDetailDistrictCell::query()->count());

        config(['gwl.commercial_customer_list_enabled' => true]);
        $empty = $this->importBilling($this->spec([['label' => 'SOWUTUOM 4101', 'customers' => [BillingDetailsSampleFile::customer(1)]]], 'August-2026 - August-2026'));
        $this->assertFalse($empty->fresh()->control_totals['link']['available'], 'no customer list has been loaded');
        $this->assertSame(0, CommercialBillingDetailTransition::query()->count());
    }

    /** MySQL refuses a constraint name over 64 characters and SQLite never checks: the one migration of this phase is read for every foreignId() it declares. */
    public function test_every_constraint_name_of_the_migration_fits_mysqls_limit(): void
    {
        $path = base_path('database/migrations/2026_11_24_000001_add_cross_file_link_tables_to_billing_details.php');
        $table = null;
        $checked = 0;

        foreach (file($path) as $line) {
            if (preg_match("/Schema::create\('(\w+)'/", $line, $m)) {
                $table = $m[1];
            }

            if ($table !== null && preg_match("/foreignId\('(\w+)'\)->constrained\(/", $line, $m)) {
                $checked++;
                $default = $table.'_'.$m[1].'_foreign';
                $this->assertTrue(strlen($default) <= 64 || str_contains($line, 'indexName:'), "the constraint {$default} is ".strlen($default).' characters: name it by hand');
            }
        }

        $this->assertGreaterThanOrEqual(5, $checked, 'the check read the foreign keys of both tables');
    }

    public function test_the_new_tables_hold_codes_and_counts_only(): void
    {
        foreach (['commercial_billing_detail_transitions', 'commercial_billing_detail_district_matrix'] as $table) {
            $columns = Schema::getColumnListing($table);

            foreach (['name', 'account_name', 'address', 'phone', 'mobile', 'email', 'meter_no', 'account_no'] as $personal) {
                $this->assertNotContains($personal, $columns, $table.' must never hold '.$personal);
            }
        }
    }

    public function test_a_district_users_file_compares_only_their_own_routes(): void
    {
        $this->loadTheList();
        $user = $this->userWith('900050', ['commercial.upload_reports', 'commercial.view_billing'], $this->accraWest, $this->sowutuom, true);
        $this->assertSame([$this->sowutuom->id], CustomerScope::districtIds($user));

        $spec = $this->spec([
            ['label' => 'SOWUTUOM 4101', 'customers' => [BillingDetailsSampleFile::customer(1), BillingDetailsSampleFile::customer(2)]],
            ['label' => 'ODORKOR 4201', 'customers' => [BillingDetailsSampleFile::customer(901)]],
        ], 'September-2026 - September-2026');

        $batch = $this->importBilling($spec, ['actor' => $user, 'restrict' => $this->accraWest->id, 'districts' => [$this->sowutuom->id]]);

        $this->assertSame([$this->sowutuom->id], CommercialBillingDetailTransition::query()->where('batch_id', $batch->id)->distinct()->pluck('district_id')->all(), 'the other district\'s route was never stored, so it is never compared');
        $this->assertSame(2, $batch->fresh()->control_totals['link']['compared']);
    }
}
