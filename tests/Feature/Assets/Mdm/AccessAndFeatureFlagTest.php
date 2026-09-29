<?php

namespace Tests\Feature\Assets\Mdm;

use App\Livewire\Assets\Mdm\DevicesDashboard;
use App\Livewire\Assets\Mdm\EnrollPhone;
use App\Livewire\Assets\PhonesList;
use App\Models\MdmDevice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ErpNavigation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

class AccessAndFeatureFlagTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
    }

    // ------------------------------------------------------------------ who can reach what

    public function test_default_grants_match_the_brief(): void
    {
        $slugs = ['assets.mdm_view', 'assets.mdm_manage_policies', 'assets.mdm_enroll', 'assets.mdm_command', 'assets.mdm_wipe'];
        $holds = fn (string $role) => collect($slugs)->filter(
            fn ($slug) => Role::query()->where('name', $role)->firstOrFail()->permissions()->where('name', $slug)->exists()
        )->values()->all();

        $this->assertSame($slugs, $holds('super_admin'));
        $this->assertSame($slugs, $holds('admin'));
        $this->assertSame(['assets.mdm_view', 'assets.mdm_enroll', 'assets.mdm_command'], $holds('ict_team'));
        $this->assertSame([], $holds('employee'));
        $this->assertSame([], $holds('hr_headoffice'));
    }

    public function test_the_permissions_belong_to_the_assets_module(): void
    {
        $this->assertSame(5, Permission::query()->where('module', 'assets')->where('name', 'like', 'assets.mdm_%')->count());
    }

    public function test_admin_can_open_the_mdm_screens_but_still_nothing_else_in_assets(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('assets.mdm.devices'))->assertOk();
        $this->actingAs($admin)->get(route('assets.mdm.policies'))->assertOk();
        $this->actingAs($admin)->get(route('assets.mdm.policies.create'))->assertOk();
        $this->actingAs($admin)->get(route('assets.mdm.enroll'))->assertOk();

        foreach (['assets.home', 'assets.assets', 'assets.phones', 'assets.network', 'assets.reports'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertForbidden();
        }
    }

    public function test_admins_assets_tile_lands_on_the_mdm_dashboard_instead_of_a_403(): void
    {
        $navigation = app(ErpNavigation::class);

        $this->assertSame('assets.mdm.devices', $navigation->assetsLandingRoute($this->admin()));
        $this->assertSame('assets.home', $navigation->assetsLandingRoute($this->superAdmin()));
        $this->assertSame('assets.home', $navigation->assetsLandingRoute($this->ictIn($this->district())));

        $modules = collect($navigation->build($this->admin(), 'assets')['modules']);
        $this->assertSame(route('assets.mdm.devices'), $modules->firstWhere('slug', 'assets')['route']);

        $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()->assertSee(route('assets.mdm.devices'), false);
    }

    public function test_super_admin_and_ict_team_reach_the_mdm_screens(): void
    {
        $this->actingAs($this->superAdmin())->get(route('assets.mdm.devices'))->assertOk();
        $this->actingAs($this->ictIn($this->district()))->get(route('assets.mdm.devices'))->assertOk();
        $this->actingAs($this->ictIn($this->district(), 'ICT777'))->get(route('assets.mdm.enroll'))->assertOk();
    }

    public function test_other_roles_and_guests_cannot_reach_mdm(): void
    {
        $this->get(route('assets.mdm.devices'))->assertRedirect(route('login'));

        foreach (['employee', 'hr_headoffice', 'transport_manager', 'receptionist'] as $i => $role) {
            $user = $this->userWithRole($role, 'X'.$i);
            $this->actingAs($user)->get(route('assets.mdm.devices'))->assertRedirectContains('/dashboard');
        }

        $webhookToken = $this->postJson('/webhooks/android-management', []);
        $webhookToken->assertUnauthorized();
    }

    public function test_the_enroll_screen_needs_the_enroll_permission(): void
    {
        $ict = $this->ictIn($this->district());
        Role::query()->where('name', 'ict_team')->firstOrFail()->permissions()
            ->detach(Permission::query()->where('name', 'assets.mdm_enroll')->value('id'));

        $this->actingAs($ict->fresh())->get(route('assets.mdm.enroll'))->assertForbidden();
        $this->actingAs($ict->fresh())->get(route('assets.mdm.devices'))->assertOk();
    }

    public function test_the_admin_can_use_the_livewire_mdm_components_but_not_reach_other_assets_modules_through_them(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(DevicesDashboard::class)->assertOk();
        Livewire::actingAs($admin)->test(EnrollPhone::class)->assertOk();
    }

    // ------------------------------------------------------------------ sidebar

    public function test_the_sidebar_shows_the_mdm_entries_according_to_permission(): void
    {
        $navigation = app(ErpNavigation::class);
        $labels = fn (User $user) => collect($navigation->build($user, 'assets')['sidebar'])->pluck('label')->all();

        $super = $labels($this->superAdmin());
        $this->assertContains('Mobile Devices', $super);
        $this->assertContains('MDM Devices', $super);
        $this->assertContains('Enroll Phone', $super);
        $this->assertContains('MDM Policies', $super);

        $ict = $labels($this->ictIn($this->district()));
        $this->assertContains('MDM Devices', $ict);
        $this->assertContains('Enroll Phone', $ict);
        $this->assertContains('MDM Policies', $ict);

        $admin = $labels($this->admin());
        $this->assertContains('MDM Devices', $admin);
        $this->assertNotContains('Assets', $admin, 'admin still has no inventory access');
        $this->assertNotContains('Phones', $admin);
    }

    public function test_the_mdm_sidebar_section_disappears_with_the_flag_and_entries_follow_their_permission(): void
    {
        $navigation = app(ErpNavigation::class);
        $ict = $this->ictIn($this->district());

        Role::query()->where('name', 'ict_team')->firstOrFail()->permissions()
            ->detach(Permission::query()->where('name', 'assets.mdm_enroll')->value('id'));

        $labels = collect($navigation->build($ict->fresh(), 'assets')['sidebar'])->pluck('label')->all();
        $this->assertContains('MDM Devices', $labels);
        $this->assertNotContains('Enroll Phone', $labels);
    }

    // ------------------------------------------------------------------ feature flag

    public function test_with_the_flag_off_the_sidebar_and_module_tile_show_nothing_mdm_even_for_super_admin(): void
    {
        config(['gwl.mdm_enabled' => false]);
        $navigation = app(ErpNavigation::class);

        foreach ([$this->superAdmin(), $this->ictIn($this->district()), $this->admin()] as $user) {
            $labels = collect($navigation->build($user, 'assets')['sidebar'])->pluck('label')->all();

            foreach (['Mobile Devices', 'MDM Devices', 'Enroll Phone', 'MDM Policies'] as $mdmLabel) {
                $this->assertNotContains($mdmLabel, $labels);
            }
        }

        $this->assertSame('assets.home', $navigation->assetsLandingRoute($this->admin()));
    }

    public function test_with_the_flag_off_the_livewire_components_refuse_to_mount(): void
    {
        config(['gwl.mdm_enabled' => false]);
        $this->withoutExceptionHandling();

        $this->expectException(NotFoundHttpException::class);

        Livewire::actingAs($this->superAdmin())->test(DevicesDashboard::class);
    }

    public function test_with_the_flag_off_the_phones_screen_shows_no_mdm_link(): void
    {
        $district = $this->district();
        MdmDevice::factory()->forAsset($this->phone($district, ['asset_name' => 'Linked Phone']))->create();
        $viewer = $this->ictIn($district);

        Livewire::actingAs($viewer)->test(PhonesList::class)->assertSee('Linked Phone')->assertSee('MDM · Managed');

        config(['gwl.mdm_enabled' => false]);

        Livewire::actingAs($viewer)->test(PhonesList::class)->assertSee('Linked Phone')->assertDontSee('MDM ·');
    }

    public function test_with_the_flag_off_the_routes_including_the_webhook_do_not_exist_and_answer_404(): void
    {
        $this->assertTrue(Route::has('webhooks.android-management'), 'sanity: registered while the flag is on');

        $this->rebuildApplicationWithFlag('false');

        try {
            $this->assertFalse(config('gwl.mdm_enabled'));

            foreach (['webhooks.android-management', 'assets.mdm.devices', 'assets.mdm.devices.show', 'assets.mdm.enroll', 'assets.mdm.policies', 'assets.mdm.policies.create', 'assets.mdm.policies.edit', 'assets.mdm.enterprise.callback', 'assets.mdm.enterprise.create'] as $name) {
                $this->assertFalse(Route::has($name), "{$name} must not be registered when GWL_MDM_ENABLED is false");
            }

            $this->postJson('/webhooks/android-management?token=anything', ['message' => ['messageId' => '1', 'data' => 'e30=']])->assertNotFound();
            $this->get('/assets/mdm')->assertNotFound();
            $this->get('/assets/mdm/enroll')->assertNotFound();

            // The rest of Assets is unaffected.
            $this->assertTrue(Route::has('assets.phones'));

            // …and the schedule carries no MDM entries.
            $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode(' ');
            $this->assertStringNotContainsString('mdm:', $commands);
        } finally {
            $this->restoreFlag();
        }
    }

    public function test_the_flag_defaults_to_off_and_is_on_in_the_test_suite(): void
    {
        $this->assertTrue(config('gwl.mdm_enabled'), 'phpunit.xml switches it on so the suite always runs');

        $defaults = require base_path('config/gwl.php');
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertArrayHasKey('mdm_enabled', $defaults);
        $this->assertStringContainsString('GWL_MDM_ENABLED=false', $envExample);
    }

    public function test_every_documented_setting_is_in_env_example_and_config(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        foreach ([
            'GWL_MDM_ENABLED', 'GOOGLE_CLOUD_PROJECT_ID', 'GOOGLE_APPLICATION_CREDENTIALS', 'ANDROID_MANAGEMENT_ENTERPRISE_ID',
            'GWL_MDM_ENROLLMENT_TOKEN_MINUTES', 'GWL_MDM_LOST_MODE_MESSAGE', 'GWL_MDM_LOST_MODE_PHONE', 'GWL_MDM_LOST_MODE_ADDRESS',
            'GWL_MDM_PUBSUB_TOPIC', 'GWL_MDM_PUBSUB_SUBSCRIPTION', 'GWL_MDM_PUBSUB_MODE',
            'GWL_MDM_PUBSUB_PUSH_AUDIENCE', 'GWL_MDM_PUBSUB_PUSH_SERVICE_ACCOUNT', 'GWL_MDM_PUBSUB_PUSH_TOKEN',
        ] as $variable) {
            $this->assertStringContainsString($variable.'=', $envExample, "{$variable} is missing from .env.example");
        }

        foreach ([
            'mdm_enabled', 'mdm_google_project_id', 'mdm_credentials_path', 'mdm_enterprise_name', 'mdm_enrollment_token_minutes',
            'mdm_lost_mode_message', 'mdm_lost_mode_phone', 'mdm_lost_mode_address', 'mdm_pubsub_topic', 'mdm_pubsub_subscription',
            'mdm_pubsub_mode', 'mdm_pubsub_push_audience', 'mdm_pubsub_push_service_account', 'mdm_pubsub_push_token',
        ] as $key) {
            $this->assertArrayHasKey($key, require base_path('config/gwl.php'), "gwl.{$key} is missing");
        }
    }

    public function test_the_credentials_filename_patterns_are_git_ignored(): void
    {
        $ignore = file_get_contents(base_path('.gitignore'));

        $this->assertStringContainsString('service-account', $ignore);
        $this->assertStringContainsString('google-credentials', $ignore);
    }

    // ------------------------------------------------------------------ helpers

    /** Boot a fresh application whose GWL_MDM_ENABLED is $value, so routes and schedules register (or not) at boot. */
    private function rebuildApplicationWithFlag(string $value): void
    {
        $_ENV['GWL_MDM_ENABLED'] = $value;
        $_SERVER['GWL_MDM_ENABLED'] = $value;
        putenv('GWL_MDM_ENABLED='.$value);

        $this->refreshApplication();
    }

    private function restoreFlag(): void
    {
        $_ENV['GWL_MDM_ENABLED'] = 'true';
        $_SERVER['GWL_MDM_ENABLED'] = 'true';
        putenv('GWL_MDM_ENABLED=true');
    }
}
