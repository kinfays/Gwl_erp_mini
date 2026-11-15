<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialBillingDetailRollup;
use App\Models\CommercialImportBatch;
use App\Models\CommercialRoute;
use App\Services\Commercial\Customers\CustomerScope;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Commercial\Customers\CustomerTestCase;
use Tests\Support\Commercial\BuildsBillingDetails;

/**
 * Phase 8d, the link to the customer list: after a Detailed Billing Report goes live, commercial_customers.ops_route_id (the CANONICAL route of
 * the account's billing row) is filled for the accounts of the file that exist in the customer list, one route at a time and only for the
 * districts that upload is the newest of. It never changes who sees which customer (that is Phase 8e, behind a setting). Invented data.
 *
 * The customer list is the invented one of the customer tests (accounts 100000000001 and up, the same numbering the billing sample uses):
 * accounts 1 to 5 in Sowutuom and 901 to 903 in Odorkor. The billing sample covers accounts 1 to 577.
 */
class BillingDetailsCustomerLinkTest extends CustomerTestCase
{
    use BuildsBillingDetails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBillingDetails();
        $this->tolerant();
        config(['gwl.commercial_customer_list_enabled' => true]);
        $this->loadCustomers($this->simpleSpec(1, 5));
        $this->loadCustomers($this->simpleSpec(901, 903, ['district' => 'ODORKOR'], '3001'));
    }

    protected function opsRoute(int $n): ?int
    {
        $value = $this->customerRow($n)->ops_route_id;

        return $value === null ? null : (int) $value;
    }

    protected function routeId(string $label): int
    {
        return (int) CommercialBillingDetailRollup::query()->where('route_label_raw', $label)->value('route_id');
    }

    public function test_ops_route_id_is_filled_for_the_accounts_of_the_file_that_are_in_the_customer_list_only(): void
    {
        $this->assertNull($this->opsRoute(1));

        $batch = $this->importBilling();
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->status, (string) $batch->error_message);

        // accounts 1 to 5 are on the first route of the first district; the canonical route of that billing row is what the customer is given
        $route = $this->routeId('SOWUTUOM 4101');
        $this->assertGreaterThan(0, $route);

        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->assertSame($route, $this->opsRoute($n), "account {$n}");
        }

        // accounts 901 to 903 are in the list but not in the file: untouched
        foreach ([901, 902, 903] as $n) {
            $this->assertNull($this->opsRoute($n));
        }

        // nothing was created in the customer list: only the accounts that were already there
        $this->assertSame(8, $this->customerCount());
        $this->assertSame(5, DB::table('commercial_customers')->whereNotNull('ops_route_id')->count());

        // the report on the batch page: 577 accounts (the repeated one is one account), 5 found, 572 in billing but not in the list; 3 list accounts of these districts not in the file
        $link = $batch->fresh()->control_totals['link'];
        $this->assertTrue($link['available']);
        $this->assertSame(577, $link['accounts']);
        $this->assertSame(5, $link['in_customer_list']);
        $this->assertSame(572, $link['not_in_customer_list']);
        $this->assertSame(5, $link['linked']);
        $this->assertSame(3, $link['list_accounts_not_in_file']);
        $this->assertSame(0, $link['routes_left_to_a_newer_upload']);

        // the staging table is emptied
        $this->assertSame(0, DB::table('commercial_billing_detail_accounts')->count());
    }

    public function test_it_does_not_change_who_sees_which_customer(): void
    {
        $before = DB::table('commercial_customers')->orderBy('id')->get(['id', 'district_id', 'route_id', 'region_id', 'status_id', 'balance'])->map(fn ($r) => (array) $r)->all();

        $this->importBilling();

        $after = DB::table('commercial_customers')->orderBy('id')->get(['id', 'district_id', 'route_id', 'region_id', 'status_id', 'balance'])->map(fn ($r) => (array) $r)->all();
        $this->assertSame($before, $after, 'the district, route and balance the customer list holds are exactly as they were: only ops_route_id was written');

        // the customer scope of a district user is still the list's own district
        $viewer = $this->userWithRoles('900991', ['district_commercial_viewer'], $this->accraWest, $this->odorkor);
        $this->assertSame([$this->odorkor->id], CustomerScope::districtIds($viewer));
    }

    public function test_an_older_upload_does_not_overwrite_what_a_newer_one_filed_and_a_newer_one_does(): void
    {
        // September first (accounts 1 to 5 under SOWUTUOM 4101)
        $this->importBilling();
        $september = $this->routeId('SOWUTUOM 4101');
        $this->assertSame($september, $this->opsRoute(1));

        // an OLDER period (August) uploaded afterwards puts the same accounts on another route: the newer data stands
        $august = $this->importBilling($this->spec([$this->route('SOWUTUOM 4999', 1, 5)], 'August-2026 - August-2026'));
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $august->status, (string) $august->error_message);
        $this->assertSame($september, $this->opsRoute(1), 'August is older than the September file that already filed this district');
        $this->assertSame(0, $august->fresh()->control_totals['link']['linked']);
        $this->assertSame(1, $august->fresh()->control_totals['link']['routes_left_to_a_newer_upload']);

        // a NEWER period (October) takes over
        $october = $this->importBilling($this->spec([$this->route('SOWUTUOM 4998', 1, 5)], 'October-2026 - October-2026'));
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $october->status, (string) $october->error_message);
        $this->assertSame($this->routeId('SOWUTUOM 4998'), $this->opsRoute(1));
        $this->assertSame($this->routeId('SOWUTUOM 4998'), $this->opsRoute(5));
        $this->assertNotSame($september, $this->opsRoute(3));
        $this->assertSame(5, $october->fresh()->control_totals['link']['linked']);
    }

    public function test_an_alias_route_files_the_canonical_route(): void
    {
        // the same code printed under two districts is one canonical route (billing / reading is the authority): accounts under the alias get the canonical route
        $first = $this->importBilling($this->spec([$this->route('SOWUTUOM 7001', 1, 2), $this->route('ODORKOR 7001', 3, 5)], 'September-2026 - September-2026'));
        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $first->status, (string) $first->error_message);

        $canonical = CommercialRoute::query()->where('code', '7001')->whereNull('canonical_route_id')->value('id');
        $this->assertNotNull($canonical);

        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->assertSame((int) $canonical, $this->opsRoute($n), 'account '.$n.' got the canonical route, whichever district printed it');
        }
    }

    public function test_with_the_customer_list_switched_off_nothing_is_linked_and_the_batch_says_so(): void
    {
        config(['gwl.commercial_customer_list_enabled' => false]);
        $batch = $this->importBilling();

        $this->assertSame(CommercialImportBatch::STATUS_IMPORTED, $batch->status);
        $this->assertFalse($batch->fresh()->control_totals['link']['available']);
        $this->assertSame(0, DB::table('commercial_customers')->whereNotNull('ops_route_id')->count());
    }
}
