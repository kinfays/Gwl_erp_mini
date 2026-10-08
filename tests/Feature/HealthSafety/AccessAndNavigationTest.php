<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\Home;
use App\Livewire\HealthSafety\ReportIncident;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ErpNavigation;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

class AccessAndNavigationTest extends HealthSafetyTestCase
{
    /** A role every member of staff could have had, minus the right to report. */
    private function userWithoutReportPermission(string $staffId = '100050')
    {
        $role = Role::query()->create(['name' => 'no_report_test', 'display_name' => 'No report', 'is_system' => false]);
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_HEALTH_SAFETY, 'can_access' => true]);

        $user = $this->userWithRoles($staffId, []);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    public function test_a_user_without_the_report_permission_gets_403_on_the_report_route_and_in_the_component(): void
    {
        $user = $this->userWithoutReportPermission();

        $this->actingAs($user)->get(route('health_safety.report'))->assertForbidden();
        $this->actingAs($user)->get(route('health_safety.mine'))->assertForbidden();
        Livewire::actingAs($user)->test(ReportIncident::class)->assertForbidden();
    }

    public function test_the_submit_action_is_refused_if_the_permission_is_taken_away_after_the_page_opened(): void
    {
        $user = $this->reporter();
        $page = Livewire::actingAs($user)->test(ReportIncident::class);

        $user->roles()->detach();
        $user->roles()->attach($this->userWithoutReportPermission('100051')->roles->first());

        $page->set(['context' => 'district_office', 'districtId' => $this->sowutuom->id, 'incidentType' => 'near_miss', 'firstAid' => 'no_need', 'description' => 'Late.'])
            ->call('submit')
            ->assertForbidden();
    }

    public function test_a_user_with_a_role_that_has_no_module_access_is_sent_back_to_the_dashboard(): void
    {
        $role = Role::query()->create(['name' => 'blocked_test', 'display_name' => 'Blocked', 'is_system' => false]);
        ModuleAccess::query()->create(['role_id' => $role->id, 'module' => Permission::MODULE_HEALTH_SAFETY, 'can_access' => false]);

        $user = $this->userWithRoles('100052', []);
        $user->roles()->attach($role);

        $this->actingAs($user->fresh())->get(route('health_safety.report'))->assertRedirect('/dashboard');
    }

    public function test_every_role_may_enter_the_module_and_global_admin_too(): void
    {
        foreach (['employee', 'manager', 'hr_headoffice', 'secretary', 'driver', 'ict_team', 'admin', 'receptionist', 'managing_director', 'transport_manager'] as $index => $role) {
            $user = $this->userWithRoles('10006'.$index, [$role]);

            $this->assertContains(Permission::MODULE_HEALTH_SAFETY, $user->getAccessibleModules(), "{$role} should reach the module");
            $this->actingAs($user)->get(route('health_safety.report'))->assertOk();
            $this->actingAs($user)->get(route('health_safety.mine'))->assertOk();
        }
    }

    public function test_the_overview_is_for_officers_and_managers_and_a_plain_employee_is_sent_to_the_form(): void
    {
        $this->actingAs($this->reporter())->get(route('health_safety.home'))->assertRedirect(route('health_safety.report'));

        $this->actingAs($this->officer())->get(route('health_safety.home'))->assertOk()->assertSee('New reports');
        $this->actingAs($this->districtManager('200003', $this->sowutuom))->get(route('health_safety.home'))->assertOk();
        $this->actingAs($this->superAdmin())->get(route('health_safety.home'))->assertOk();

        Livewire::actingAs($this->reporter('100002'))->test(Home::class)->assertForbidden();
    }

    public function test_the_overview_counts_link_to_the_filtered_rows(): void
    {
        $officer = $this->officer();
        $this->incident($this->reporter('100001'));
        $this->triaged($this->reporter('100002'), $officer, 'low');

        $page = Livewire::actingAs($officer)->test(Home::class);

        $page->assertSee(route('health_safety.incidents', ['status' => 'reported']))
            ->assertSee(route('health_safety.incidents', ['status' => 'in_progress']));

        $this->actingAs($officer)->get(route('health_safety.incidents', ['status' => 'reported']))->assertOk();
    }

    public function test_the_sidebar_shows_each_person_only_what_they_may_use(): void
    {
        $navigation = app(ErpNavigation::class);
        $labels = fn ($user) => collect($navigation->build($user, 'health_safety')['sidebar'])->pluck('label')->all();

        $this->assertSame(['Report an incident', 'My reports', 'My PPE'], $labels($this->reporter()));
        // Phase 2 added the equipment screens (the full list per role is pinned in EquipmentAccessTest).
        $this->assertSame(['Overview', 'Report an incident', 'My reports', 'Incidents', 'Actions', 'Fire extinguishers', 'First aid kits', 'Expiry register', 'PPE stock', 'PPE issues', 'PPE gaps', 'My PPE', 'Kit templates', 'Import equipment', 'PPE types', 'PPE entitlements', 'PPE reorder levels', 'Sites'], $labels($this->officer()));
        $this->assertSame(['Overview', 'Report an incident', 'My reports', 'Incidents', 'Actions', 'Fire extinguishers', 'First aid kits', 'Expiry register', 'PPE stock', 'PPE issues', 'PPE gaps', 'My PPE'], $labels($this->districtManager('200003', $this->sowutuom)));
        $this->assertContains('Sites', $labels($this->superAdmin()));
    }

    public function test_someone_given_an_action_sees_the_actions_item_in_the_sidebar(): void
    {
        $officer = $this->officer();
        $kofi = $this->userWithRoles('300001', ['employee']);
        $navigation = app(ErpNavigation::class);

        $this->assertNotContains('Actions', collect($navigation->build($kofi, 'health_safety')['sidebar'])->pluck('label')->all());

        $incident = $this->triaged($this->reporter(), $officer, 'low');
        $this->workflow()->createAction($incident, $officer, ['description' => 'Fix it.', 'assigned_to_employee_id' => $kofi->employee->id, 'due_on' => today()->addWeek()->toDateString()]);

        $this->assertContains('Actions', collect($navigation->build($kofi->fresh(), 'health_safety')['sidebar'])->pluck('label')->all());
    }

    public function test_the_module_is_in_the_navigation_when_the_flag_is_on_and_hidden_when_it_is_off(): void
    {
        $navigation = app(ErpNavigation::class);
        $titles = fn ($user) => collect($navigation->build($user, 'leave')['modules'])->pluck('title')->all();

        $employee = $this->reporter();
        $this->assertContains('Health & Safety', $titles($employee));
        $this->assertContains('Health & Safety', $titles($this->superAdmin()));

        config(['gwl.health_safety_module_enabled' => false]);

        $this->assertNotContains('Health & Safety', $titles($employee));
        $this->assertNotContains('Health & Safety', $titles($this->superAdmin()));
    }

    public function test_the_dashboard_lists_the_module_while_it_is_on(): void
    {
        $this->actingAs($this->reporter())->get(route('dashboard'))->assertOk()->assertSee('Health &amp; Safety', false);
    }

    public function test_with_the_flag_off_the_routes_do_not_exist(): void
    {
        $this->assertTrue(Route::has('health_safety.home'), 'sanity: registered while the flag is on');

        $this->rebuildApplicationWithFlag('false');

        try {
            $this->assertFalse(config('gwl.health_safety_module_enabled'));

            foreach (['health_safety.home', 'health_safety.report', 'health_safety.mine', 'health_safety.incidents', 'health_safety.incidents.show', 'health_safety.incidents.print', 'health_safety.attachments.show', 'health_safety.actions', 'health_safety.sites', 'health_safety.scan', 'health_safety.labels.extinguishers', 'health_safety.labels.kits', 'health_safety.posters'] as $name) {
                $this->assertFalse(Route::has($name), "{$name} must not be registered when GWL_HEALTH_SAFETY_MODULE_ENABLED is false");
            }

            $this->get('/health-safety')->assertNotFound();
        } finally {
            $this->restoreFlag();
        }
    }

    public function test_the_flag_defaults_to_off_and_every_setting_is_documented(): void
    {
        $this->assertTrue(config('gwl.health_safety_module_enabled'), 'phpunit.xml switches it on so the suite always runs');

        $defaults = require base_path('config/gwl.php');
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('GWL_HEALTH_SAFETY_MODULE_ENABLED=false', $envExample);

        foreach ([
            'GWL_HEALTH_SAFETY_MODULE_ENABLED' => 'health_safety_module_enabled',
            'GWL_HS_ACK_HOURS' => 'hs_ack_hours',
            'GWL_HS_INVESTIGATION_DUE_DAYS' => 'hs_investigation_due_days',
            'GWL_HS_ATTACHMENT_MAX_MB' => 'hs_attachment_max_mb',
            'GWL_HS_ATTACHMENTS_PER_INCIDENT' => 'hs_attachments_per_incident',
            'GWL_HS_EMERGENCY_CONTACTS' => 'hs_emergency_contacts',
            'GWL_HS_QR_BASE_URL' => 'hs_qr_base_url',
            'GWL_HS_LABELS_PER_PDF_MAX' => 'hs_labels_per_pdf_max',
        ] as $variable => $key) {
            $this->assertStringContainsString($variable.'=', $envExample, "{$variable} is missing from .env.example");
            $this->assertArrayHasKey($key, $defaults, "gwl.{$key} is missing");
        }
    }

    public function test_the_emergency_strip_shows_only_when_numbers_are_configured(): void
    {
        $user = $this->reporter();

        Livewire::actingAs($user)->test(ReportIncident::class)->assertDontSee('In an emergency');

        config(['gwl.hs_emergency_contacts' => 'Ambulance: 193 | Fire: 192']);

        Livewire::actingAs($user)->test(ReportIncident::class)
            ->assertSee('In an emergency')
            ->assertSee('Ambulance')
            ->assertSee('tel:193', false);
    }

    /** Boots a fresh application whose GWL_HEALTH_SAFETY_MODULE_ENABLED is $value, so routes register (or not) at boot. */
    private function rebuildApplicationWithFlag(string $value): void
    {
        $_ENV['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = $value;
        $_SERVER['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = $value;
        putenv('GWL_HEALTH_SAFETY_MODULE_ENABLED='.$value);

        $this->refreshApplication();
    }

    private function restoreFlag(): void
    {
        $_ENV['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = 'true';
        $_SERVER['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = 'true';
        putenv('GWL_HEALTH_SAFETY_MODULE_ENABLED=true');
    }
}
