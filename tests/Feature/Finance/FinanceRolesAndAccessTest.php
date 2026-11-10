<?php

namespace Tests\Feature\Finance;

use App\Livewire\Audit\Queue;
use App\Livewire\Finance\Vouchers;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Uac\RoleGrantPolicy;
use App\Support\ErpNavigation;
use Livewire\Livewire;

/** The two roles, their permissions, module access, the single flag and where the roles may be given. */
class FinanceRolesAndAccessTest extends FinanceTestCase
{
    public function test_the_permissions_exist_in_both_modules(): void
    {
        $finance = ['finance.view', 'finance.create_pv', 'finance.void_pv', 'finance.acknowledge', 'finance.manage_categories', 'finance.manage_suppliers',
            'finance.deactivate_suppliers', 'finance.import_suppliers', 'finance.view_reports', 'finance.export', 'finance.manage_reminders'];
        $audit = ['audit.view', 'audit.review', 'audit.view_reports', 'audit.export', 'audit.view_reviewed', 'audit.manage_reminders', 'audit.assign'];

        $this->assertEqualsCanonicalizing($finance, Permission::query()->where('module', 'finance')->pluck('name')->all());
        $this->assertEqualsCanonicalizing($audit, Permission::query()->where('module', 'audit')->pluck('name')->all());
        $this->assertContains('finance', Permission::MODULES);
        $this->assertContains('audit', Permission::MODULES);
    }

    public function test_the_roles_are_ict_assignable_unprotected_and_in_neither_location_list(): void
    {
        foreach (['finance_officer', 'audit_officer'] as $name) {
            $role = Role::query()->where('name', $name)->firstOrFail();

            $this->assertTrue((bool) $role->ict_assignable, $name);
            $this->assertFalse((bool) $role->is_protected, $name);
            $this->assertFalse((bool) $role->is_system, $name);
            $this->assertNotContains($name, RoleGrantPolicy::HEAD_OFFICE_ROLES);
            $this->assertNotContains($name, RoleGrantPolicy::REGIONAL_ROLES);

            // Head Office, regional and district staff can all hold them.
            $policy = app(RoleGrantPolicy::class);
            foreach ([$this->headOffice, $this->sowutuom, $this->kumasi] as $district) {
                $this->assertNull($policy->locationDenial($role, $this->employee('X'.$name.$district->id, $district)), "{$name} at {$district->district_name}");
            }
        }
    }

    public function test_each_role_holds_its_own_permissions_and_module_only(): void
    {
        $finance = Role::query()->where('name', 'finance_officer')->firstOrFail();
        $audit = Role::query()->where('name', 'audit_officer')->firstOrFail();
        $slugs = fn (Role $role) => $role->permissions()->pluck('name')->all();

        $this->assertContains('finance.create_pv', $slugs($finance));
        $this->assertContains('finance.import_suppliers', $slugs($finance));
        $this->assertNotContains('finance.deactivate_suppliers', $slugs($finance), 'deactivating a supplier is granted to few people');
        $this->assertNotContains('finance.manage_categories', $slugs($finance), 'the catalogue belongs to the Finance manager (FinanceManagersTest)');
        $this->assertNotContains('audit.review', $slugs($finance));
        $this->assertEqualsCanonicalizing(['audit.view', 'audit.review', 'audit.view_reports', 'audit.export'], $slugs($audit));

        $access = fn (Role $role) => ModuleAccess::query()->where('role_id', $role->id)->where('can_access', true)->pluck('module')->all();
        $this->assertContains('finance', $access($finance));
        $this->assertNotContains('audit', $access($finance));
        $this->assertContains('audit', $access($audit));
        $this->assertNotContains('finance', $access($audit));

        $super = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->assertContains('finance.deactivate_suppliers', $slugs($super));
        $this->assertContains('audit.review', $slugs($super));
    }

    public function test_the_modules_show_in_the_navigation_by_access_and_one_flag_hides_both(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $plain = $this->person('E1', $this->sowutuom);
        $navigation = app(ErpNavigation::class);
        $slugs = fn ($who) => collect($navigation->build($who, 'finance')['modules'])->pluck('slug')->all();

        $this->assertContains('finance', $slugs($fin));
        $this->assertNotContains('audit', $slugs($fin));
        $this->assertContains('audit', $slugs($aud));
        $this->assertNotContains('finance', $slugs($plain));
        $this->assertNotContains('audit', $slugs($plain));
        $this->assertContains('finance.pvs.create', collect($navigation->build($fin, 'finance')['sidebar'])->pluck('route')->all());
        $this->assertContains('audit.home', collect($navigation->build($aud, 'audit')['sidebar'])->pluck('route')->all());

        config(['gwl.finance_audit_enabled' => false]);
        $this->assertNotContains('finance', $slugs($fin));
        $this->assertNotContains('audit', $slugs($aud));

        // And a component refuses to mount even if someone reached it another way.
        Livewire::actingAs($fin)->test(Vouchers::class)->assertNotFound();
        Livewire::actingAs($aud)->test(Queue::class)->assertNotFound();
    }

    public function test_the_home_dashboard_shows_a_tile_for_each_module_the_person_may_enter_and_one_flag_hides_both(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $both = $this->both('B1', $this->sowutuom);
        $plain = $this->person('E1', $this->sowutuom);

        $tiles = function ($who) {
            $this->actingAs($who);

            return collect(dashboardModules())->pluck('route', 'slug');
        };

        $this->assertSame(route('finance.home'), $tiles($fin)['finance']);
        $this->assertArrayNotHasKey('audit', $tiles($fin)->all());
        $this->assertSame(route('audit.home'), $tiles($aud)['audit']);
        $this->assertArrayNotHasKey('finance', $tiles($aud)->all());
        $this->assertArrayHasKey('finance', $tiles($both)->all());
        $this->assertArrayHasKey('audit', $tiles($both)->all());
        $this->assertArrayNotHasKey('finance', $tiles($plain)->all());
        $this->assertArrayNotHasKey('audit', $tiles($plain)->all());

        $this->actingAs($fin)->get(route('dashboard'))->assertOk()->assertSee(route('finance.home'), false);

        config(['gwl.finance_audit_enabled' => false]);
        $this->assertArrayNotHasKey('finance', $tiles($fin)->all());
        $this->assertArrayNotHasKey('audit', $tiles($both)->all());
    }

    public function test_the_flag_is_off_by_default_in_config_and_routes_are_not_registered_without_it(): void
    {
        // phpunit.xml switches the flag on, so the shipped default is read from the config file itself.
        $this->assertStringContainsString("env('GWL_FINANCE_AUDIT_ENABLED', false)", file_get_contents(base_path('config/gwl.php')));

        // phpunit.xml turns it on; with it on the named routes exist.
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('finance.home'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('audit.home'));
    }
}
