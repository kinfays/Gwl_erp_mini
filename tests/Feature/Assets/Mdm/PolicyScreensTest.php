<?php

namespace Tests\Feature\Assets\Mdm;

use App\Livewire\Assets\Mdm\PoliciesList;
use App\Livewire\Assets\Mdm\PolicyEditor;
use App\Models\AuditLog;
use App\Models\MdmDevice;
use App\Models\MdmPolicy;
use App\Models\MdmPolicyApp;
use App\Services\Assets\Mdm\Exceptions\MdmGatewayException;
use App\Services\Assets\Mdm\PolicyService;
use Database\Seeders\MdmStarterPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

class PolicyScreensTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
    }

    public function test_the_starter_gwl_standard_phone_policy_ships_locked_down_with_placeholder_apps(): void
    {
        $policy = MdmPolicy::query()->where('name', MdmStarterPolicySeeder::NAME)->with('apps')->firstOrFail();

        $this->assertSame('WHITELIST', $policy->play_store_mode);
        $this->assertTrue($policy->install_apps_disabled);
        $this->assertTrue($policy->uninstall_apps_disabled);
        $this->assertTrue($policy->factory_reset_disabled);
        $this->assertTrue($policy->add_user_disabled);
        $this->assertSame('DISALLOW_INSTALL', $policy->untrusted_apps_policy);
        $this->assertSame('DEVELOPER_SETTINGS_DISABLED', $policy->developer_settings);
        $this->assertFalse($policy->isPublished(), 'shipped as a draft: the app list and FRP accounts are yours to edit first');

        $forced = $policy->apps->where('install_type', 'FORCE_INSTALLED');
        $this->assertContains('com.whatsapp.w4b', $forced->pluck('package_name')->all());
        $this->assertGreaterThanOrEqual(1, $forced->count());
    }

    public function test_the_starter_seeder_is_idempotent_and_never_overwrites_an_edited_policy(): void
    {
        $policy = MdmPolicy::query()->where('name', MdmStarterPolicySeeder::NAME)->firstOrFail();
        $policy->update(['description' => 'Edited by IT']);
        $policy->apps()->where('package_name', 'com.android.chrome')->delete();

        (new MdmStarterPolicySeeder)->run();
        (new MdmStarterPolicySeeder)->run();

        $this->assertSame(1, MdmPolicy::query()->where('name', MdmStarterPolicySeeder::NAME)->count());
        $this->assertSame('Edited by IT', $policy->fresh()->description);
        $this->assertFalse($policy->apps()->where('package_name', 'com.android.chrome')->exists());
    }

    public function test_the_list_shows_publication_state_and_device_counts(): void
    {
        $admin = $this->admin();
        $draft = MdmPolicy::factory()->withForcedApp()->create(['name' => 'Draft Policy']);
        $live = MdmPolicy::factory()->withForcedApp()->create(['name' => 'Live Policy']);
        app(PolicyService::class)->publish($live, $admin);
        MdmDevice::factory()->count(2)->create(['mdm_policy_id' => $live->id]);

        $stale = MdmPolicy::factory()->withForcedApp()->create(['name' => 'Edited Policy']);
        app(PolicyService::class)->publish($stale, $admin);
        $stale->update(['camera_access' => 'CAMERA_ACCESS_DISABLED']);

        Livewire::actingAs($admin)->test(PoliciesList::class)
            ->assertSee('Draft Policy')->assertSee('Never published')
            ->assertSee('Live Policy')->assertSee('Published')
            ->assertSee('Edited Policy')->assertSee('Unpublished changes');
    }

    public function test_deleting_a_policy_is_blocked_while_phones_use_it(): void
    {
        $admin = $this->admin();
        $inUse = MdmPolicy::factory()->create();
        MdmDevice::factory()->create(['mdm_policy_id' => $inUse->id]);
        $spare = MdmPolicy::factory()->create();

        Livewire::actingAs($admin)->test(PoliciesList::class)
            ->call('delete', $inUse->id)->assertDispatched('toast', type: 'error', message: 'This policy is still applied to 1 phone(s), so it cannot be deleted.')
            ->call('delete', $spare->id)->assertDispatched('toast', type: 'success', message: 'Policy deleted. If it was published, remove it from the Google admin console too.');

        $this->assertNotNull(MdmPolicy::query()->find($inUse->id));
        $this->assertNull(MdmPolicy::query()->find($spare->id));
        $this->assertTrue(AuditLog::query()->where('action', 'mdm_policy_deleted')->where('target_id', $spare->id)->exists());
    }

    public function test_ict_team_can_read_policies_but_not_manage_them(): void
    {
        $ict = $this->ictIn($this->district());
        $policy = MdmPolicy::factory()->create();

        Livewire::actingAs($ict)->test(PoliciesList::class)->assertSee($policy->name)->assertSee('Read only')->assertDontSee('New Policy');

        $this->actingAs($ict)->get(route('assets.mdm.policies.create'))->assertForbidden();
        $this->actingAs($ict)->get(route('assets.mdm.policies.edit', $policy->id))->assertForbidden();

        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);
        Livewire::actingAs($ict)->test(PoliciesList::class)->call('delete', $policy->id);
    }

    public function test_creating_a_policy_with_apps_and_frp_accounts_saves_and_audits_it(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(PolicyEditor::class)
            ->set('form.name', 'Regional Phone')
            ->set('frpEmailsText', "Security@GWCL.example\nit@gwcl.example, security@gwcl.example")
            ->set('form.camera_access', 'CAMERA_ACCESS_DISABLED')
            ->set('apps.0.package_name', 'com.whatsapp.w4b')
            ->set('apps.0.app_name', 'WhatsApp Business')
            ->call('addApp')
            ->set('apps.1.package_name', 'com.example.optional')
            ->set('apps.1.install_type', 'AVAILABLE')
            ->call('save')
            ->assertHasNoErrors();

        $policy = MdmPolicy::query()->where('name', 'Regional Phone')->with('apps')->firstOrFail();

        $this->assertSame(['security@gwcl.example', 'it@gwcl.example'], $policy->frp_admin_emails, 'lower-cased and de-duplicated');
        $this->assertSame('CAMERA_ACCESS_DISABLED', $policy->camera_access);
        $this->assertSame($admin->id, $policy->created_by);
        $this->assertEqualsCanonicalizing(['com.whatsapp.w4b', 'com.example.optional'], $policy->apps->pluck('package_name')->all());
        $this->assertSame('AVAILABLE', $policy->apps->firstWhere('package_name', 'com.example.optional')->install_type);
        $this->assertFalse($this->gateway->called('patchPolicy'), 'saving never reaches Google');

        $log = AuditLog::query()->where('action', 'mdm_policy_created')->firstOrFail();
        $this->assertSame($policy->id, $log->target_id);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_editing_a_policy_updates_apps_removes_deleted_rows_and_audits_old_and_new_values(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $policy = MdmPolicy::factory()->withForcedApp('com.keep.me')->create(['camera_access' => 'CAMERA_ACCESS_USER_CHOICE']);
        MdmPolicyApp::factory()->for($policy, 'policy')->create(['package_name' => 'com.drop.me']);

        $component = Livewire::test(PolicyEditor::class, ['policyId' => $policy->id]);
        $dropIndex = collect($component->get('apps'))->search(fn ($app) => $app['package_name'] === 'com.drop.me');

        $component->call('removeApp', $dropIndex)
            ->set('form.camera_access', 'CAMERA_ACCESS_DISABLED')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['com.keep.me'], $policy->fresh()->apps->pluck('package_name')->all());

        $log = AuditLog::query()->where('action', 'mdm_policy_updated')->firstOrFail();
        $this->assertSame('CAMERA_ACCESS_USER_CHOICE', $log->old_values['camera_access']);
        $this->assertSame('CAMERA_ACCESS_DISABLED', $log->new_values['camera_access']);
    }

    public function test_the_editor_validates_names_packages_and_choices(): void
    {
        $this->actingAs($this->admin());
        MdmPolicy::factory()->create(['name' => 'Taken']);

        Livewire::test(PolicyEditor::class)
            ->set('form.name', 'Taken')
            ->set('apps.0.package_name', 'not a package')
            ->set('form.camera_access', 'CAMERA_ACCESS_WHENEVER')
            ->call('save')
            ->assertHasErrors(['form.name', 'apps.0.package_name', 'form.camera_access']);

        Livewire::test(PolicyEditor::class)
            ->set('form.name', 'Fresh')
            ->set('frpEmailsText', 'not-an-email')
            ->set('apps.0.package_name', 'com.example.a')
            ->call('save')
            ->assertHasErrors(['frpEmailsText']);

        Livewire::test(PolicyEditor::class)
            ->set('form.name', 'Dupes')
            ->set('apps.0.package_name', 'com.example.a')
            ->call('addApp')->set('apps.1.package_name', 'com.example.a')
            ->call('save')
            ->assertHasErrors(['apps.0.package_name']);
    }

    public function test_publish_shows_a_diff_and_warnings_first_and_sends_nothing_until_confirmed(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $policy = MdmPolicy::factory()->withForcedApp('com.whatsapp.w4b')->create(['frp_admin_emails' => []]);

        $component = Livewire::test(PolicyEditor::class, ['policyId' => $policy->id])
            ->call('preparePublish')
            ->assertHasNoErrors()
            ->assertSet('showPublish', true)
            ->assertSee('Publish to Google?')
            ->assertSee('applications[com.whatsapp.w4b]')
            ->assertSee('playStoreMode')
            ->assertSee('No factory reset protection accounts are set');

        $this->assertFalse($this->gateway->called('patchPolicy'), 'the preview must not reach Google');
        $this->assertNull($policy->fresh()->google_policy_name);

        $component->call('publish')->assertHasNoErrors()->assertSet('showPublish', false)->assertDispatched('toast');

        $this->assertCount(1, $this->gateway->calls('patchPolicy'));
        $this->assertSame(1, $policy->fresh()->version);
        $this->assertTrue(AuditLog::query()->where('action', 'mdm_policy_published')->where('user_id', $admin->id)->exists());
    }

    public function test_a_second_publish_shows_only_what_changed(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $policy = MdmPolicy::factory()->withForcedApp()->create(['frp_admin_emails' => ['it@gwcl.example']]);
        app(PolicyService::class)->publish($policy, $admin);

        $component = Livewire::test(PolicyEditor::class, ['policyId' => $policy->id])
            ->set('form.screen_capture_disabled', true)
            ->call('preparePublish');

        $diff = collect($component->get('diff'));
        $this->assertSame(['screenCaptureDisabled'], $diff->pluck('path')->all());
        $this->assertSame('false', $diff->first()['before']);
        $this->assertSame('true', $diff->first()['after']);
    }

    public function test_publish_is_refused_for_a_policy_with_no_force_installed_app_and_shows_why(): void
    {
        $this->actingAs($this->admin());
        $policy = MdmPolicy::factory()->create();
        MdmPolicyApp::factory()->for($policy, 'policy')->available()->create();

        Livewire::test(PolicyEditor::class, ['policyId' => $policy->id])
            ->call('preparePublish')
            ->assertSet('showPublish', false)
            ->assertHasErrors(['apps'])
            ->assertSee('FORCE_INSTALLED');

        $this->assertFalse($this->gateway->called('patchPolicy'));
    }

    public function test_a_google_failure_during_publish_is_shown_and_records_nothing(): void
    {
        $this->actingAs($this->admin());
        $policy = MdmPolicy::factory()->withForcedApp()->create();
        $this->gateway->failures['patchPolicy'] = new MdmGatewayException('Google API error 403: permission denied', 403);

        Livewire::test(PolicyEditor::class, ['policyId' => $policy->id])
            ->call('preparePublish')
            ->call('publish')
            ->assertHasErrors(['publish'])
            ->assertSee('permission denied');

        $this->assertNull($policy->fresh()->published_at);
        $this->assertSame(0, $policy->fresh()->version);
        $this->assertFalse(AuditLog::query()->where('action', 'mdm_policy_published')->exists());
    }

    public function test_a_missing_enterprise_setting_is_reported_not_thrown_as_a_stack_trace(): void
    {
        config(['gwl.mdm_enterprise_name' => null]);
        $this->actingAs($this->admin());
        $policy = MdmPolicy::factory()->withForcedApp()->create();

        Livewire::test(PolicyEditor::class, ['policyId' => $policy->id])
            ->call('preparePublish')
            ->call('publish')
            ->assertHasErrors(['publish'])
            ->assertSee('ANDROID_MANAGEMENT_ENTERPRISE_ID is not set');
    }

    public function test_the_locked_publish_state_cannot_be_forced_open_from_the_client(): void
    {
        $this->actingAs($this->admin());

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(PolicyEditor::class)->set('showPublish', true);
    }

    public function test_the_editor_and_publish_are_closed_to_ict_team(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);

        Livewire::actingAs($this->ictIn($this->district()))->test(PolicyEditor::class);
    }
}
