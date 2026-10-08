<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\Home;
use App\Livewire\HealthSafety\MyPpe;
use App\Livewire\HealthSafety\PpeGaps;
use App\Livewire\HealthSafety\PpeIssues;
use App\Livewire\HealthSafety\PpeStock;
use App\Models\Employee;
use App\Models\HsPpeEntitlement;
use App\Models\HsPpeIssue;
use App\Models\HsPpeReorderLevel;
use App\Models\HsPpeStockMovement;
use App\Models\HsPpeType;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\HealthSafety\PpeComplianceService;
use App\Services\HealthSafety\PpeIssueService;
use App\Services\HealthSafety\PpeStockService;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

class PpeScreensTest extends HealthSafetyTestCase
{
    private function officer0(): User
    {
        return $this->officer('200001', $this->accraWest, $this->headOffice);
    }

    private function staff(string $staffId, ?\App\Models\District $district = null, ?JobTitle $title = null): User
    {
        $user = $this->userWithRoles($staffId, ['employee'], $district?->region, $district);

        if ($title) {
            $user->employee->update(['job_title_id' => $title->id]);
        }

        return $user->fresh();
    }

    // ---------------------------------------------------------------- stock screen

    public function test_an_officer_receives_adjusts_writes_off_and_transfers_through_the_stock_screen(): void
    {
        $officer = $this->officer0();
        $a = $this->ppeStore('Store A');
        $b = $this->ppeStore('Store B');
        $boots = $this->bootsType();
        $stock = app(PpeStockService::class);

        $page = Livewire::actingAs($officer)->test(PpeStock::class)->assertSee('Store A')->assertSee('Safety boots');

        $page->call('openPanel', 'receive', $a->id, $boots->id)
            ->set('panelSize', '41')->set('quantity', '10')->set('reference', 'DN-77')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(10, $stock->balance($a, $boots, '41'));

        $page->call('openPanel', 'adjust', $a->id, $boots->id)
            ->set('panelSize', '41')->set('quantity', '-2')->set('reason', 'Recount')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(8, $stock->balance($a, $boots, '41'));

        $page->call('openPanel', 'write_off', $a->id, $boots->id)
            ->set('panelSize', '41')->set('quantity', '1')->set('reason', 'Mould')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(7, $stock->balance($a, $boots, '41'));

        $page->call('openPanel', 'transfer', $a->id, $boots->id)
            ->set('panelSize', '41')->set('quantity', '3')->set('toStoreId', $b->id)
            ->call('save')->assertHasNoErrors();
        $this->assertSame(4, $stock->balance($a, $boots, '41'));
        $this->assertSame(3, $stock->balance($b, $boots, '41'));

        $page->assertSee('DN-77')->assertSee('Recount');
    }

    public function test_the_screen_shows_the_error_when_a_movement_would_go_below_zero(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->stocked($store, $hat, 2);

        Livewire::actingAs($officer)->test(PpeStock::class)
            ->call('openPanel', 'write_off', $store->id, $hat->id)
            ->set('quantity', '5')->set('reason', 'Lost in a flood')
            ->call('save')
            ->assertHasErrors(['quantity'])
            ->assertSee('below zero');

        $this->assertSame(2, app(PpeStockService::class)->balance($store, $hat));
    }

    public function test_the_low_filter_and_the_reorder_flag(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $gloves = $this->ppeType(['name' => 'Work gloves']);
        $this->stocked($store, $hat, 2);
        $this->stocked($store, $gloves, 50);
        HsPpeReorderLevel::query()->create(['site_id' => $store->id, 'ppe_type_id' => $hat->id, 'level' => 5]);
        HsPpeReorderLevel::query()->create(['site_id' => $store->id, 'ppe_type_id' => $gloves->id, 'level' => 5]);

        Livewire::actingAs($officer)->test(PpeStock::class)->assertSee('Hard hat')->assertSee('Work gloves')->assertSee('Low');

        Livewire::withQueryParams(['low' => 1])->actingAs($officer)->test(PpeStock::class)
            ->assertSee('Hard hat')
            ->assertDontSeeHtml('wire:key="hs-ppe-row-'.$store->id.'-'.$gloves->id.'"');
    }

    public function test_without_a_store_the_stock_screen_says_how_to_make_one(): void
    {
        Livewire::actingAs($this->officer0())->test(PpeStock::class)->assertSee('There is no PPE store yet');
    }

    public function test_a_district_manager_sees_issues_and_gaps_in_their_district_no_store_balances_and_cannot_post(): void
    {
        // Design 8.10.1: a store is the regional-office site, which has no district.
        $store = \App\Models\HsSite::query()->create([
            'name' => 'Accra West Regional Office', 'kind' => 'regional_office', 'region_id' => $this->accraWest->id, 'district_id' => null, 'is_ppe_store' => true,
        ])->fresh();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->stocked($store, $hat, 4);
        $manager = $this->districtManager('200003', $this->sowutuom);

        $title = JobTitle::query()->create(['job_title_name' => 'Fitter']);
        HsPpeEntitlement::query()->create(['job_title_id' => $title->id, 'ppe_type_id' => $hat->id, 'quantity' => 1]);
        $here = $this->staff('300001', $this->sowutuom);
        $there = $this->staff('300002', $this->odorkor);
        $here->employee->update(['job_title_id' => $title->id]);
        $there->employee->update(['job_title_id' => $title->id]);

        Livewire::actingAs($manager)->test(PpeStock::class)
            ->assertDontSee('Accra West Regional Office')
            ->assertDontSee('Receive stock');

        Livewire::actingAs($manager)->test(PpeGaps::class)
            ->assertSee($here->employee->full_name)
            ->assertDontSee($there->employee->full_name);

        foreach (['receive', 'adjust', 'write_off', 'transfer'] as $kind) {
            Livewire::actingAs($manager)->test(PpeStock::class)->call('openPanel', $kind, $store->id, $hat->id)->assertForbidden();
        }

        // Even a save aimed straight at the component, with no panel opened.
        Livewire::actingAs($manager)->test(PpeStock::class)
            ->set('panelStoreId', $store->id)->set('panelTypeId', $hat->id)->set('quantity', '1')->set('reason', 'x')
            ->call('save')->assertForbidden();

        $this->assertSame(4, app(PpeStockService::class)->balance($store, $hat));
        $this->assertSame(1, HsPpeStockMovement::query()->count());
    }

    public function test_the_regional_officer_sees_the_regional_office_store_and_it_is_preselected(): void
    {
        $store = \App\Models\HsSite::query()->create([
            'name' => 'Accra West Regional Office', 'kind' => 'regional_office', 'region_id' => $this->accraWest->id, 'district_id' => null, 'is_ppe_store' => true,
        ])->fresh();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->stocked($store, $hat, 4);
        $officer = $this->officer('200001', $this->accraWest, $this->odorkor);

        Livewire::actingAs($officer)->test(PpeStock::class)
            ->assertSee('Accra West Regional Office')
            ->call('openPanel', 'receive')
            ->assertSet('panelStoreId', $store->id);

        // Issued from the regional store to staff of any district in the region.
        $kofi = $this->staff('300001', $this->sowutuom);
        $issue = app(PpeIssueService::class)->issue($officer, [
            'employee_id' => $kofi->employee->id, 'ppe_type_id' => $hat->id, 'quantity' => 1, 'store_id' => $store->id, 'issued_on' => today()->toDateString(),
        ]);
        $this->assertSame('issued', $issue->status);
        $this->assertSame(3, app(PpeStockService::class)->balance($store, $hat));
    }

    public function test_an_employee_gets_403_on_the_stock_issues_and_gaps_screens(): void
    {
        $employee = $this->staff('300001');

        foreach (['health_safety.ppe.stock', 'health_safety.ppe.issues', 'health_safety.ppe.gaps', 'health_safety.ppe.types', 'health_safety.ppe.entitlements', 'health_safety.ppe.reorder-levels', 'health_safety.ppe.import'] as $route) {
            $this->actingAs($employee)->get(route($route))->assertForbidden();
        }

        Livewire::actingAs($employee)->test(PpeStock::class)->assertForbidden();
        Livewire::actingAs($employee)->test(PpeIssues::class)->assertForbidden();
        Livewire::actingAs($employee)->test(PpeGaps::class)->assertForbidden();
    }

    public function test_a_regional_officer_sees_and_posts_only_in_their_own_region(): void
    {
        $officer = $this->officer('200001', $this->accraWest, $this->odorkor);
        $mine = $this->ppeStore('Accra Store', $this->odorkor);
        $theirs = $this->ppeStore('Kumasi Store', $this->kumasi);
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $this->stocked($mine, $hat, 5);
        $this->stocked($theirs, $hat, 5);

        $this->staff('300001', $this->odorkor);
        $kumasiStaff = $this->staff('300002', $this->kumasi);
        $this->workflowIssue($this->superAdmin(), $kumasiStaff, $hat, $theirs);

        Livewire::actingAs($officer)->test(PpeStock::class)->assertSee('Accra Store')->assertDontSee('Kumasi Store');
        Livewire::actingAs($officer)->test(PpeIssues::class)->assertDontSee($kumasiStaff->employee->full_name);

        // A store id aimed at another region is refused, not trusted.
        Livewire::actingAs($officer)->test(PpeStock::class)
            ->call('openPanel', 'receive', $theirs->id, $hat->id)
            ->set('quantity', '3')->call('save')->assertForbidden();

        $this->assertSame(4, app(PpeStockService::class)->balance($theirs, $hat), '5 received, 1 issued to the Kumasi member of staff, and nothing more');
    }

    private function workflowIssue(User $actor, User $staff, HsPpeType $type, \App\Models\HsSite $store, int $quantity = 1, ?string $size = null): HsPpeIssue
    {
        return app(PpeIssueService::class)->issue($actor, ['employee_id' => $staff->employee->id, 'ppe_type_id' => $type->id, 'store_id' => $store->id, 'quantity' => $quantity, 'size' => $size]);
    }

    // ---------------------------------------------------------------- issues screen

    public function test_an_officer_issues_ppe_through_the_screen_and_the_ledger_follows(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $boots = $this->bootsType();
        $kofi = $this->staff('300001');
        $this->stocked($store, $boots, 5, '41');

        Livewire::actingAs($officer)->test(PpeIssues::class)
            ->call('startIssue')
            ->set('employeeSearch', '300001')
            ->call('chooseEmployee', $kofi->employee->id)
            ->set('issueTypeId', $boots->id)
            ->set('issueSize', '41')
            ->set('issueQuantity', '1')
            ->call('saveIssue')
            ->assertHasNoErrors();

        $issue = HsPpeIssue::query()->firstOrFail();
        $this->assertSame([$kofi->employee->id, '41', $store->id], [$issue->employee_id, $issue->size, HsPpeStockMovement::query()->where('issue_id', $issue->id)->value('site_id')]);
        $this->assertSame(4, app(PpeStockService::class)->balance($store, $boots, '41'));
        $this->assertSame(today()->addMonths(12)->toDateString(), $issue->replace_due_on->toDateString());
    }

    public function test_the_screen_asks_for_a_size_only_for_a_sized_type_and_reports_missing_pieces(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $boots = $this->bootsType();
        $kofi = $this->staff('300001');
        $this->stocked($store, $boots, 5, '41');

        $page = Livewire::actingAs($officer)->test(PpeIssues::class)->call('startIssue');

        $page->call('saveIssue')->assertHasErrors(['issueEmployeeId', 'issueTypeId']);

        $page->set('employeeSearch', '300001')->call('chooseEmployee', $kofi->employee->id)
            ->set('issueTypeId', $boots->id)
            ->assertSee('Select size')
            ->call('saveIssue')
            ->assertHasErrors(['size']);

        $this->assertSame(0, HsPpeIssue::query()->count());
    }

    public function test_the_employee_picker_offers_only_active_staff_in_the_officers_scope(): void
    {
        $officer = $this->officer('200001', $this->accraWest, $this->odorkor);
        $inScope = $this->staff('300001', $this->odorkor);
        $left = $this->staff('300002', $this->odorkor);
        $left->employee->update(['is_active' => false]);
        $other = $this->staff('300003', $this->kumasi);
        foreach ([$inScope, $left, $other] as $person) {
            $person->employee->update(['full_name' => 'Pickme '.$person->staff_id]);
        }

        $page = Livewire::actingAs($officer)->test(PpeIssues::class)->call('startIssue')->set('employeeSearch', 'Pickme');

        $page->assertSee('Pickme 300001')->assertDontSee('Pickme 300002')->assertDontSee('Pickme 300003');

        // An id the picker never offered is not accepted either.
        $page->call('chooseEmployee', $other->employee->id)->assertSet('issueEmployeeId', null);
        $page->call('chooseEmployee', $left->employee->id)->assertSet('issueEmployeeId', null);
    }

    public function test_the_replacement_step_lists_the_held_rows_and_defaults_due_ones_to_worn_out(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $boots = $this->bootsType();
        $kofi = $this->staff('300001');
        $this->stocked($store, $boots, 5, '41');
        $svc = app(PpeIssueService::class);
        $data = fn (string $issuedOn) => ['employee_id' => $kofi->employee->id, 'ppe_type_id' => $boots->id, 'size' => '41', 'quantity' => 1, 'is_historic' => true, 'issued_on' => $issuedOn];

        $overdue = $svc->issue($officer, $data(today()->subMonths(18)->toDateString()));   // 12-month life: overdue
        $fresh = $svc->issue($officer, $data(today()->subMonths(2)->toDateString()));       // not due

        $page = Livewire::actingAs($officer)->test(PpeIssues::class)
            ->call('startIssue')
            ->set('employeeSearch', '300001')
            ->call('chooseEmployee', $kofi->employee->id)
            ->set('issueTypeId', $boots->id)
            ->assertSee('They already hold this');

        $closings = $page->get('closings');
        $this->assertSame('worn_out', $closings[$overdue->id]['outcome'], 'overdue rows default to worn out');
        $this->assertSame('', $closings[$fresh->id]['outcome'], 'a row that is not due is kept unless the officer says otherwise');

        $page->set('issueSize', '41')->call('saveIssue')->assertHasNoErrors();

        $this->assertSame('worn_out', $overdue->fresh()->status);
        $this->assertSame('issued', $fresh->fresh()->status);
        $new = HsPpeIssue::query()->latest('id')->first();
        $this->assertSame($new->id, $overdue->fresh()->replaced_by_issue_id);
    }

    public function test_the_already_held_option_takes_a_past_date_and_posts_no_stock(): void
    {
        $officer = $this->officer0();
        $boots = $this->bootsType();
        $kofi = $this->staff('300001');

        Livewire::actingAs($officer)->test(PpeIssues::class)
            ->call('startIssue')
            ->set('employeeSearch', '300001')
            ->call('chooseEmployee', $kofi->employee->id)
            ->set('issueTypeId', $boots->id)
            ->set('issueSize', '42')
            ->set('issueHistoric', true)
            ->set('issueIssuedOn', '2024-03-01')
            ->call('saveIssue')
            ->assertHasNoErrors();

        $issue = HsPpeIssue::query()->firstOrFail();
        $this->assertTrue($issue->is_historic);
        $this->assertSame('2024-03-01', $issue->issued_on->toDateString());
        $this->assertSame(0, HsPpeStockMovement::query()->count());
    }

    public function test_an_issue_is_closed_through_the_screen_and_a_return_goes_back_into_the_chosen_store(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staff('300001');
        $this->stocked($store, $hat, 3);
        $issue = $this->workflowIssue($officer, $kofi, $hat, $store);

        Livewire::actingAs($officer)->test(PpeIssues::class)
            ->call('startClose', $issue->id)
            ->call('saveClose')->assertHasErrors(['closeOutcome'])
            ->set('closeOutcome', 'returned')
            ->set('closeStoreId', $store->id)
            ->call('saveClose')->assertHasNoErrors();

        $this->assertSame('returned', $issue->fresh()->status);
        $this->assertSame(3, app(PpeStockService::class)->balance($store, $hat));
    }

    public function test_without_manage_ppe_every_issue_action_is_forbidden_even_through_livewire(): void
    {
        $store = $this->ppeStore('Sowutuom Store', $this->sowutuom);
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staff('300001', $this->sowutuom);
        $this->stocked($store, $hat, 3);
        $issue = $this->workflowIssue($this->officer0(), $kofi, $hat, $store);
        $manager = $this->districtManager('200003', $this->sowutuom);

        Livewire::actingAs($manager)->test(PpeIssues::class)->assertSee($kofi->employee->full_name)->assertDontSee('Issue to staff');
        Livewire::actingAs($manager)->test(PpeIssues::class)->call('startIssue')->assertForbidden();
        Livewire::actingAs($manager)->test(PpeIssues::class)->call('startClose', $issue->id)->assertForbidden();
        Livewire::actingAs($manager)->test(PpeIssues::class)
            ->set('closeOutcome', 'lost')->set('closeOn', today()->toDateString())
            ->call('saveClose')->assertForbidden();
        Livewire::actingAs($manager)->test(PpeIssues::class)
            ->set('issueEmployeeId', $kofi->employee->id)->set('issueTypeId', $hat->id)->set('issueStoreId', $store->id)
            ->call('saveIssue')->assertForbidden();

        $this->assertSame('issued', $issue->fresh()->status);
        $this->assertSame(1, HsPpeIssue::query()->count());
    }

    public function test_the_issue_list_filters_work_and_the_overview_links_resolve(): void
    {
        $officer = $this->officer0();
        $hat = $this->ppeType(['name' => 'Hard hat', 'replacement_months' => 6]);
        $svc = app(PpeIssueService::class);

        foreach ([['300001', 12], ['300002', 5], ['300003', 1]] as [$id, $monthsAgo]) {
            $svc->issue($officer, ['employee_id' => $this->staff($id)->employee->id, 'ppe_type_id' => $hat->id, 'quantity' => 1, 'is_historic' => true, 'issued_on' => today()->subMonths($monthsAgo)->toDateString()]);
        }

        $names = fn ($id) => Employee::query()->where('staff_id', $id)->value('full_name');

        Livewire::withQueryParams(['state' => 'overdue'])->actingAs($officer)->test(PpeIssues::class)
            ->assertSee($names('300001'))->assertDontSee($names('300002'))->assertDontSee($names('300003'));

        Livewire::withQueryParams(['state' => 'replacement_due'])->actingAs($officer)->test(PpeIssues::class)
            ->assertSee($names('300002'))->assertDontSee($names('300001'));

        Livewire::withQueryParams([])->actingAs($officer)->test(PpeIssues::class)->set('search', '300003')->assertSee($names('300003'))->assertDontSee($names('300001'));
    }

    // ---------------------------------------------------------------- gaps, My PPE and the overview agree

    public function test_the_gaps_screen_my_ppe_and_the_overview_agree_for_the_same_fixture(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat', 'replacement_months' => 6]);
        $boots = $this->ppeType(['name' => 'Boots', 'replacement_months' => 12]);
        $title = JobTitle::query()->firstOrCreate(['job_title_name' => 'Technician']);
        HsPpeEntitlement::query()->create(['job_title_id' => $title->id, 'ppe_type_id' => $hat->id, 'quantity' => 1]);
        HsPpeEntitlement::query()->create(['job_title_id' => $title->id, 'ppe_type_id' => $boots->id, 'quantity' => 1]);
        $this->stocked($store, $hat, 10);
        $this->stocked($store, $boots, 10);

        $a = $this->staff('300001', null, $title);   // holds nothing: missing x2
        $b = $this->staff('300002', null, $title);   // overdue hat, boots ok
        $c = $this->staff('300003', null, $title);   // fine
        $svc = app(PpeIssueService::class);
        $hist = fn (User $u, HsPpeType $t, int $monthsAgo) => $svc->issue($officer, ['employee_id' => $u->employee->id, 'ppe_type_id' => $t->id, 'quantity' => 1, 'is_historic' => true, 'issued_on' => today()->subMonths($monthsAgo)->toDateString()]);
        $hist($b, $hat, 9);
        $hist($b, $boots, 1);
        $hist($c, $hat, 1);
        $hist($c, $boots, 1);
        HsPpeReorderLevel::query()->create(['site_id' => $store->id, 'ppe_type_id' => $hat->id, 'level' => 20]);   // 10 in stock <= 20: low

        $summary = app(PpeComplianceService::class)->summary($officer);
        $this->assertSame(['missing' => 2, 'overdue' => 1, 'short' => 0, 'replacement_due' => 0, 'ok' => 3], $summary['by_state']);
        $this->assertSame(2, $summary['gap_employees']);

        // The gaps screen lists exactly those rows.
        $gaps = Livewire::actingAs($officer)->test(PpeGaps::class)->assertSeeInOrder([$a->employee->full_name, $b->employee->full_name]);
        $gaps->assertViewHas('page', fn ($page) => $page->total() === 6);
        Livewire::withQueryParams(['state' => 'gap'])->actingAs($officer)->test(PpeGaps::class)->assertViewHas('page', fn ($page) => $page->total() === 3);

        // My PPE tells each person what the gaps screen says of them.
        Livewire::actingAs($a)->test(MyPpe::class)->assertSee('Hard hat: missing')->assertSee('Boots: missing');
        Livewire::actingAs($b)->test(MyPpe::class)->assertSee('Hard hat: overdue')->assertDontSee('Boots: ');
        Livewire::actingAs($c)->test(MyPpe::class)->assertDontSee('Ask your Health');

        // The overview counts are the same numbers, and each tile links to the list that shows them.
        $home = Livewire::actingAs($officer)->test(Home::class)
            ->assertSee('PPE low in stock')->assertSee('PPE replacements overdue')->assertSee('Staff with PPE gaps')
            ->assertSee(route('health_safety.ppe.stock', ['low' => 1]), false)
            ->assertSee(route('health_safety.ppe.issues', ['state' => 'overdue']), false)
            ->assertSee(route('health_safety.ppe.gaps', ['state' => 'gap']), false);

        $tiles = collect($home->viewData('ppeTiles'))->pluck('value', 'label');
        $this->assertSame(1, $tiles['PPE low in stock']);
        $this->assertSame(1, $tiles['PPE replacements overdue']);
        $this->assertSame($summary['gap_employees'], $tiles['Staff with PPE gaps']);

        $this->assertSame($tiles['PPE low in stock'], app(PpeStockService::class)->matrix($officer)->filter(fn ($row) => $row['low'])->count());
        $this->assertSame($tiles['PPE replacements overdue'], $this->equipmentScopeFor($officer)->withState('overdue')->count());
    }

    private function equipmentScopeFor(User $user)
    {
        return app(\App\Services\HealthSafety\EquipmentScope::class)->ppeIssues($user);
    }

    public function test_a_user_without_view_equipment_gets_no_ppe_row_on_the_overview(): void
    {
        $role = \App\Models\Role::query()->create(['name' => 'dash_only_ppe', 'display_name' => 'Dash', 'is_system' => false]);
        $role->permissions()->attach(\App\Models\Permission::query()->where('name', 'health_safety.view_dashboard')->value('id'));
        \App\Models\ModuleAccess::query()->create(['role_id' => $role->id, 'module' => 'health_safety', 'can_access' => true]);
        $viewer = $this->userWithRoles('200070', ['employee']);
        $viewer->roles()->attach($role);

        Livewire::actingAs($viewer->fresh())->test(Home::class)->assertDontSee('PPE low in stock');
    }

    // ---------------------------------------------------------------- My PPE

    public function test_my_ppe_shows_only_the_signed_in_employees_own_issues(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $boots = $this->ppeType(['name' => 'Gumboots']);
        $kofi = $this->staff('300001');
        $ama = $this->staff('300002');
        $this->stocked($store, $hat, 5);
        $this->stocked($store, $boots, 5);
        $this->workflowIssue($officer, $kofi, $hat, $store);
        $this->workflowIssue($officer, $ama, $boots, $store);

        Livewire::actingAs($kofi)->test(MyPpe::class)->assertSee('Hard hat')->assertDontSee('Gumboots');
        Livewire::actingAs($ama)->test(MyPpe::class)->assertSee('Gumboots')->assertDontSee('Hard hat');
        $this->actingAs($kofi)->get(route('health_safety.my-ppe'))->assertOk();
    }

    public function test_confirming_receipt_works_once_and_only_for_my_own_issue(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $kofi = $this->staff('300001');
        $ama = $this->staff('300002');
        $this->stocked($store, $hat, 5);
        $mine = $this->workflowIssue($officer, $kofi, $hat, $store);
        $hers = $this->workflowIssue($officer, $ama, $hat, $store);

        Livewire::actingAs($kofi)->test(MyPpe::class)->assertSee('Confirm I received this')->call('confirm', $mine->id)->assertHasNoErrors();
        $this->assertNotNull($mine->fresh()->acknowledged_at);

        Livewire::actingAs($kofi)->test(MyPpe::class)->assertSee('Receipt confirmed')->assertDontSee('Confirm I received this');
        Livewire::actingAs($kofi)->test(MyPpe::class)->call('confirm', $mine->id)->assertHasErrors(['status']);

        // Somebody else's issue id is a 403 and changes nothing.
        Livewire::actingAs($kofi)->test(MyPpe::class)->call('confirm', $hers->id)->assertForbidden();
        $this->assertNull($hers->fresh()->acknowledged_at);

        // The officer cannot confirm for them either.
        Livewire::actingAs($officer)->test(MyPpe::class)->call('confirm', $hers->id)->assertForbidden();
    }

    public function test_my_ppe_needs_an_employee_record(): void
    {
        $noRecord = $this->userWithoutEmployee('900500', ['employee']);

        $this->actingAs($noRecord)->get(route('health_safety.my-ppe'))->assertForbidden();
        Livewire::actingAs($noRecord)->test(MyPpe::class)->assertForbidden();
    }

    public function test_my_ppe_needs_no_permission_beyond_module_access(): void
    {
        $role = \App\Models\Role::query()->create(['name' => 'bare_ppe', 'display_name' => 'Bare', 'is_system' => false]);
        \App\Models\ModuleAccess::query()->create(['role_id' => $role->id, 'module' => 'health_safety', 'can_access' => true]);
        $user = $this->userWithRoles('300077', []);
        $user->roles()->attach($role);

        $this->actingAs($user->fresh())->get(route('health_safety.my-ppe'))->assertOk();
    }

    // ---------------------------------------------------------------- flag

    public function test_with_the_flag_off_the_ppe_routes_do_not_exist(): void
    {
        $names = ['health_safety.ppe.stock', 'health_safety.ppe.issues', 'health_safety.ppe.gaps', 'health_safety.ppe.types', 'health_safety.ppe.entitlements',
            'health_safety.ppe.reorder-levels', 'health_safety.ppe.import', 'health_safety.ppe.import.template', 'health_safety.my-ppe'];

        foreach ($names as $name) {
            $this->assertTrue(Route::has($name), "sanity: {$name} is registered while the flag is on");
        }

        $_ENV['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = $_SERVER['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = 'false';
        putenv('GWL_HEALTH_SAFETY_MODULE_ENABLED=false');
        $this->refreshApplication();

        try {
            foreach ($names as $name) {
                $this->assertFalse(Route::has($name), "{$name} must not be registered when the flag is off");
            }

            $this->get('/health-safety/ppe/stock')->assertNotFound();
            $this->get('/health-safety/my-ppe')->assertNotFound();
        } finally {
            $_ENV['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = $_SERVER['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = 'true';
            putenv('GWL_HEALTH_SAFETY_MODULE_ENABLED=true');
        }
    }

    public function test_the_phase_three_tables_have_the_columns_indexes_and_uniques_the_design_calls_for(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::class;
        $indexes = fn (string $table) => collect($schema::getIndexes($table))->map(fn ($i) => implode(',', $i['columns']).($i['unique'] ? ' (unique)' : ''))->all();

        $this->assertTrue($schema::hasColumns('hs_ppe_issues', ['closed_on', 'close_note', 'expires_on', 'is_historic', 'replaced_by_issue_id', 'replace_due_on', 'acknowledged_at']));
        $this->assertFalse($schema::hasColumn('hs_ppe_issues', 'returned_on'));
        $this->assertTrue($schema::hasColumn('hs_sites', 'is_ppe_store'));
        $this->assertTrue($schema::hasColumns('hs_ppe_types', ['name', 'category', 'has_sizes', 'sizes', 'replacement_months', 'has_expiry', 'unit', 'is_active']));

        $this->assertContains('name (unique)', $indexes('hs_ppe_types'));
        $this->assertContains('site_id,ppe_type_id,size', $indexes('hs_ppe_stock_movements'));
        $this->assertContains('issue_id', $indexes('hs_ppe_stock_movements'));
        $this->assertContains('occurred_on', $indexes('hs_ppe_stock_movements'));
        $this->assertContains('site_id,ppe_type_id (unique)', $indexes('hs_ppe_reorder_levels'));
        $this->assertContains('job_title_id,ppe_type_id (unique)', $indexes('hs_ppe_entitlements'));
        $this->assertContains('employee_id,ppe_type_id,status', $indexes('hs_ppe_issues'));
        $this->assertContains('replace_due_on', $indexes('hs_ppe_issues'));
        $this->assertContains('status', $indexes('hs_ppe_issues'));

        $self = collect($schema::getForeignKeys('hs_ppe_issues'))->firstWhere('columns', ['replaced_by_issue_id']);
        $this->assertSame('hs_ppe_issues', $self['foreign_table']);
        $this->assertSame('set null', $self['on_delete']);
        $this->assertSame('restrict', collect($schema::getForeignKeys('hs_ppe_issues'))->firstWhere('columns', ['employee_id'])['on_delete']);
        $this->assertSame('cascade', collect($schema::getForeignKeys('hs_ppe_reorder_levels'))->firstWhere('columns', ['site_id'])['on_delete']);
    }

    public function test_no_new_permission_was_needed_and_the_existing_ones_are_granted_as_planned(): void
    {
        $granted = fn (string $role) => \App\Models\Role::query()->where('name', $role)->firstOrFail()->permissions()->pluck('name')->all();

        foreach (['health_safety.manage_ppe', 'health_safety.manage_master_data', 'health_safety.view_equipment'] as $permission) {
            $this->assertContains($permission, $granted('hs_officer'), $permission);
            $this->assertContains($permission, $granted('hs_manager'), $permission);
        }

        $this->assertContains('health_safety.view_equipment', $granted('district_manager'));
        $this->assertNotContains('health_safety.manage_ppe', $granted('district_manager'), 'view only');
        $this->assertNotContains('health_safety.manage_ppe', $granted('regional_chief_manager'));
        $this->assertSame(14, \App\Models\Permission::query()->where('module', 'health_safety')->count(), 'still the fourteen from Phase 1');
    }
}
