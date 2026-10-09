<?php

namespace Tests\Feature\Commercial\Customers;

use App\Livewire\Commercial\Customers\BatchDetail;
use App\Livewire\Commercial\Customers\Dashboard;
use App\Livewire\Commercial\Customers\Lists;
use App\Livewire\Commercial\Customers\Show;
use App\Livewire\Commercial\Customers\Uploads;
use App\Models\AuditLog;
use App\Models\CommercialCustomerBatch;
use App\Services\Commercial\Customers\CustomerListService;
use App\Services\Commercial\Customers\CustomerScope;
use App\Support\ErpNavigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/** Who may open which customer screen, and what each person can see of whose customers. */
class CustomerAccessTest extends CustomerTestCase
{
    // ---------------------------------------------------------------- permissions

    public function test_every_screen_needs_its_permission(): void
    {
        $this->loadTwoRegions();
        $analyst = $this->analyst();
        $nothing = $this->userWith('900030', ['commercial.view_dashboard']);
        $id = $this->customerId(1);

        foreach (['commercial.customers', 'commercial.customers.list'] as $name) {
            $this->actingAs($analyst)->get(route($name))->assertOk();
            $this->actingAs($nothing)->get(route($name))->assertForbidden();
        }

        $this->actingAs($analyst)->get(route('commercial.customers.show', $id))->assertOk();
        $this->actingAs($nothing)->get(route('commercial.customers.show', $id))->assertForbidden();

        // Uploads and the lookups screen are for the people who work with batches / settings.
        $this->actingAs($analyst)->get(route('commercial.customers.uploads'))->assertForbidden();
        $this->actingAs($analyst)->get(route('commercial.customers.lookups'))->assertForbidden();
        $this->actingAs($this->officer())->get(route('commercial.customers.uploads'))->assertOk();
        $this->actingAs($this->superAdmin())->get(route('commercial.customers.lookups'))->assertOk();

        // The Livewire components check again by themselves.
        Livewire::actingAs($nothing)->test(Dashboard::class)->assertForbidden();
        Livewire::actingAs($nothing)->test(Lists::class)->assertForbidden();
        Livewire::actingAs($analyst)->test(Uploads::class)->assertForbidden();
    }

    public function test_the_default_roles_see_aggregates_but_only_super_admin_sees_personal_data(): void
    {
        foreach (['commercial_officer', 'commercial_manager', 'chief_manager', 'regional_chief_manager', 'district_manager'] as $role) {
            $user = $this->userWithRoles('9005'.crc32($role) % 90, [$role]);
            $this->assertTrue($user->hasPermission('commercial.view_customer_analytics'), $role);
            $this->assertFalse($user->hasPermission('commercial.view_customer_details'), "{$role} must not see personal data by default");
        }

        $this->assertTrue($this->superAdmin()->hasRoles('super_admin'));
    }

    public function test_the_sidebar_items_follow_the_permission_and_the_flag(): void
    {
        $navigation = app(ErpNavigation::class);
        $labels = fn ($user) => collect($navigation->build($user, 'commercial')['sidebar'])->pluck('label')->all();

        $this->assertContains('Customer List', $labels($this->analyst()));
        $this->assertNotContains('Customer uploads', $labels($this->analyst()));
        $this->assertContains('Customer uploads', $labels($this->officer()));
        $this->assertNotContains('Customer List', $labels($this->userWith('900031', ['commercial.view_dashboard'])));

        config(['gwl.commercial_customer_list_enabled' => false]);

        $this->assertNotContains('Customer List', $labels($this->analyst('900032')));
        $this->assertNotContains('Customer uploads', $labels($this->officer('900033')));
    }

    public function test_with_the_customer_flag_off_the_routes_do_not_exist(): void
    {
        $this->assertTrue(Route::has('commercial.customers'), 'sanity: registered while the flag is on');

        $_ENV['GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED'] = 'false';
        $_SERVER['GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED'] = 'false';
        putenv('GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED=false');
        $this->refreshApplication();

        try {
            foreach (['commercial.customers', 'commercial.customers.list', 'commercial.customers.uploads', 'commercial.customers.upload', 'commercial.customers.show', 'commercial.customers.export'] as $name) {
                $this->assertFalse(Route::has($name), "{$name} must not be registered when the customer flag is off");
            }

            $this->assertTrue(Route::has('commercial.home'), 'the rest of Commercial is untouched');
        } finally {
            $_ENV['GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED'] = 'true';
            $_SERVER['GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED'] = 'true';
            putenv('GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED=true');
        }
    }

    // ---------------------------------------------------------------- region scope

    public function test_a_regional_user_sees_only_their_own_regions_customers_everywhere(): void
    {
        $this->loadTwoRegions();
        $west = $this->analyst('900040', $this->accraWest, $this->sowutuom);
        $ashanti = $this->analyst('900041', $this->ashanti, $this->kumasi);
        $headOffice = $this->analyst('900042', $this->accraWest, $this->headOffice);
        $headOffice->employee->forceFill(['location_type' => 'HeadOffice'])->save();

        // dashboard: totals
        $total = fn ($user) => Livewire::actingAs($user)->test(Dashboard::class)->viewData('overview')['total'];
        $this->assertSame(5, $total($west));
        $this->assertSame(3, $total($ashanti));
        $this->assertSame(8, $total($headOffice->fresh()), 'Head Office sees every region');
        $this->assertSame(8, $total($this->superAdmin()));

        // asking for the other region's district by id changes nothing
        $page = Livewire::actingAs($west)->test(Dashboard::class, [])->set('district', (string) $this->kumasi->id)->set('region', (string) $this->ashanti->id);
        $this->assertSame(5, $page->viewData('overview')['total']);

        // the list and the search
        $lists = fn ($user, array $q) => app(CustomerListService::class)->page($q + ['restriction' => CustomerScope::regionRestriction($user), 'size' => 50]);
        $this->assertCount(0, $lists($west, ['district_id' => $this->kumasi->id])['rows'], 'a district id of another region lists nothing');
        $this->assertCount(0, $lists($west, ['search' => ['type' => 'account', 'value' => $this->customerRow(101)->account_no]])['rows'], 'nor does searching for its account number');
        $this->assertCount(1, $lists($ashanti, ['search' => ['type' => 'account', 'value' => $this->customerRow(101)->account_no]])['rows']);
        $this->assertCount(5, $lists($west, ['district_id' => $this->sowutuom->id])['rows']);

        // one customer
        $foreign = $this->customerId(101);
        $this->actingAs($west)->get(route('commercial.customers.show', $foreign))->assertNotFound();
        $this->actingAs($ashanti)->get(route('commercial.customers.show', $foreign))->assertOk();
        $this->assertNull(app(CustomerListService::class)->find($foreign, CustomerScope::regionRestriction($west)));
    }

    public function test_a_user_with_no_region_sees_nothing(): void
    {
        $this->loadTwoRegions();
        $loner = $this->userWithoutEmployee('900050', []);
        $role = \App\Models\Role::query()->create(['name' => 'loner', 'display_name' => 'Loner', 'is_system' => false]);
        $role->permissions()->attach(\App\Models\Permission::query()->where('name', 'commercial.view_customer_analytics')->pluck('id'));
        \App\Models\ModuleAccess::query()->create(['role_id' => $role->id, 'module' => 'commercial', 'can_access' => true]);
        $loner->roles()->attach($role);

        $this->assertSame(0, CustomerScope::regionRestriction($loner->fresh()));
        $this->assertTrue(Livewire::actingAs($loner->fresh())->test(Dashboard::class)->viewData('empty'));
    }

    public function test_batches_and_batch_pages_are_region_scoped(): void
    {
        $this->loadTwoRegions();
        $westOfficer = $this->officer('900051', $this->accraWest, $this->sowutuom);
        $ashantiBatch = CommercialCustomerBatch::query()->where('district_id', $this->kumasi->id)->firstOrFail();
        $westBatch = CommercialCustomerBatch::query()->where('district_id', $this->sowutuom->id)->firstOrFail();

        $this->actingAs($westOfficer)->get(route('commercial.customers.batch', $westBatch))->assertOk();
        $this->actingAs($westOfficer)->get(route('commercial.customers.batch', $ashantiBatch))->assertNotFound();

        $page = Livewire::actingAs($westOfficer)->test(Uploads::class);
        $page->assertSee('#'.$westBatch->id)->assertDontSee('Kumasi');

        // the actions re-check: matching, retrying and voiding another region's batch is refused
        Livewire::actingAs($westOfficer)->test(BatchDetail::class, ['batch' => $westBatch])->assertOk();
        Livewire::actingAs($westOfficer)->test(BatchDetail::class, ['batch' => $ashantiBatch])->assertNotFound();

        // swapping the batch id inside an open component is refused too (the property is locked)
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($westOfficer)->test(BatchDetail::class, ['batch' => $westBatch])->set('batchId', $ashantiBatch->id);
    }

    public function test_the_service_scope_and_the_livewire_scope_agree(): void
    {
        $users = [
            'super_admin' => $this->superAdmin(),
            'admin' => $this->userWithRoles('900060', ['admin']),
            'regional officer' => $this->officer('900061', $this->ashanti, $this->kumasi),
            'no employee' => $this->userWithoutEmployee('900062', []),
        ];
        $headOffice = $this->officer('900063', $this->accraWest, $this->headOffice);
        $headOffice->employee->forceFill(['location_type' => 'HeadOffice'])->save();
        $users['head office'] = $headOffice->fresh();

        foreach ($users as $label => $user) {
            $probe = new class
            {
                use \App\Livewire\Commercial\Concerns\ScopesCommercialByActor;

                public function restriction(): ?int
                {
                    return $this->customerRestriction();
                }

                public function seesAll(): bool
                {
                    return $this->actorSeesAllRegions();
                }

                public function region(): ?int
                {
                    return $this->actorRegionId();
                }
            };

            $this->actingAs($user);
            $restriction = CustomerScope::regionRestriction($user);

            $this->assertSame($probe->seesAll(), $restriction === null, $label.': sees every region');
            $this->assertSame($restriction, $probe->restriction(), $label);

            if ($restriction !== null && $restriction !== 0) {
                $this->assertSame($probe->region(), $restriction, $label.': own region');
            }
        }
    }

    // ---------------------------------------------------------------- personal data

    public function test_without_the_details_permission_no_personal_data_reaches_the_page(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));
        $analyst = $this->analyst();

        $page = Livewire::actingAs($analyst)->test(Lists::class)->set('district', (string) $this->sowutuom->id);
        $page->assertSee($this->customerRow(1)->account_no)->assertDontSee('Customer 000001')->assertDontSee('House 1')->assertDontSee('0240000001')->assertDontSee('c1@example.test');
        $this->assertNull($page->viewData('page')['rows'][0]['name']);

        $show = Livewire::actingAs($analyst)->test(Show::class, ['customer' => $this->customerId(1)]);
        $show->assertDontSee('Customer 000001')->assertSee('do not hold the permission');

        // search by name / phone / e-mail is refused, not silently empty
        $search = Livewire::actingAs($analyst)->test(Lists::class)->set('search', '0240000001')->set('searchType', 'phone');
        $this->assertSame('account', $search->viewData('activeSearchType'), 'the option is not even offered');
    }

    public function test_with_the_details_permission_names_show_and_phones_and_emails_are_masked(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));
        $user = $this->detailer();

        $page = Livewire::actingAs($user)->test(Lists::class)->set('district', (string) $this->sowutuom->id);
        $page->assertSee('Customer 000001')->assertSee('House 1')->assertSee('024****001')->assertDontSee('0240000001')->assertDontSee('c1@example.test')->assertSee('c***@example.test');

        // search by phone works for a detailer, and still shows the masked number
        $found = Livewire::actingAs($user)->test(Lists::class)->set('searchType', 'phone')->set('search', '0240000003');
        $this->assertCount(1, $found->viewData('page')['rows']);
        $found->assertSee('Customer 000003')->assertDontSee('0240000003');
    }

    public function test_unmasking_a_customer_is_audited_without_putting_the_values_in_the_log(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 3));
        $user = $this->detailer();
        $id = $this->customerId(2);

        $page = Livewire::actingAs($user)->test(Show::class, ['customer' => $id]);
        $page->assertSee('024****002')->assertDontSee('0240000002');
        $this->assertSame(0, AuditLog::query()->where('action', 'commercial.customer_contact_revealed')->count(), 'looking at the masked view is not logged');

        $page->call('reveal')->assertSee('0240000002')->assertSee('c2@example.test');

        $audit = AuditLog::query()->where('action', 'commercial.customer_contact_revealed')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($id, (int) $audit[0]->target_id);
        $this->assertSame($user->id, (int) $audit[0]->user_id);
        $this->assertStringNotContainsString('0240000002', json_encode($audit[0]->toArray()));

        // someone without the permission cannot unmask by calling the action
        Livewire::actingAs($this->analyst())->test(Show::class, ['customer' => $id])->call('reveal')->assertForbidden();
    }

    public function test_no_audit_row_or_log_line_holds_a_name_phone_email_or_address(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 10));
        $detailer = $this->detailer();
        Livewire::actingAs($detailer)->test(Show::class, ['customer' => $this->customerId(4)])->call('reveal');
        $this->actingAs($this->analyst())->get(route('commercial.customers.export', ['report' => 'list', 'format' => 'excel', 'district' => $this->sowutuom->id]));

        $audit = AuditLog::query()->get()->map(fn ($row) => json_encode($row->toArray()))->implode("\n");

        foreach (['Customer 0000', 'House ', 'Test Street', '024000', '@example.test'] as $needle) {
            $this->assertStringNotContainsString($needle, $audit, "an audit row contains \"{$needle}\"");
        }

        $this->assertStringContainsString('commercial.customer_batch_imported', $audit);
    }

    public function test_an_unconfirmed_status_is_shown_as_its_raw_code_everywhere(): void
    {
        $this->loadCustomers(['routes' => [['name' => '1001', 'customers' => [\Tests\Support\Commercial\ReportWorkbooks::customer(1, ['status' => 'VACN']), \Tests\Support\Commercial\ReportWorkbooks::customer(2)]]]]);

        $rows = collect(app(CustomerListService::class)->page(['district_id' => $this->sowutuom->id, 'size' => 50])['rows'])->pluck('status', 'account_no');
        $this->assertSame('VACN', $rows[$this->customerRow(1)->account_no]);
        $this->assertSame('Active (billing) (ACTB)', $rows[$this->customerRow(2)->account_no]);

        Livewire::actingAs($this->analyst())->test(Dashboard::class)->assertSee('VACN');
    }

    public function test_the_dashboard_lists_what_is_in_the_scope_and_the_lookups_screen_is_audited(): void
    {
        $this->loadCustomers($this->simpleSpec(1, 5));
        $superAdmin = $this->superAdmin();

        $page = Livewire::actingAs($this->analyst())->test(Dashboard::class);
        $page->assertSee('Customer List')->assertSee('5')->assertSee('Billing accounts (ACTB)');

        $component = Livewire::actingAs($superAdmin)->test(\App\Livewire\Commercial\Customers\Lookups::class);
        $id = (int) DB::table('commercial_customer_categories')->where('code', '611')->value('id');
        $component->set("categories.{$id}.group", 'commercial')->set("categories.{$id}.confirmed", true)->call('saveCategories');

        $this->assertSame('commercial', DB::table('commercial_customer_categories')->where('id', $id)->value('category_group'));
        $this->assertFalse((bool) DB::table('commercial_customer_categories')->where('id', $id)->value('group_is_proposed'));
        $this->assertSame(1, AuditLog::query()->where('action', 'commercial.customer_categories_changed')->count());

        // a group change moves the customers between groups in the analysis (the join is read at view time)
        $overview = app(\App\Services\Commercial\Customers\CustomerAnalyticsService::class)->overview(
            app(\App\Services\Commercial\Customers\CustomerSnapshots::class)->filters(app(\App\Services\Commercial\Customers\CustomerSnapshots::class)->current(null), null)
        );
        $this->assertSame(['Commercial' => 5], collect($overview['by_group'])->pluck('customers', 'label')->all());
    }
}
