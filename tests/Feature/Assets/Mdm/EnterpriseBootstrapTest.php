<?php

namespace Tests\Feature\Assets\Mdm;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Assets\Mdm\EnterpriseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

class EnterpriseBootstrapTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
        config(['gwl.mdm_enterprise_name' => null]);
    }

    public function test_signup_command_prints_the_google_url_and_uses_a_callback_built_from_app_url(): void
    {
        config(['app.url' => 'https://erp.example.test']);
        URL::forceRootUrl('https://erp.example.test');

        $this->artisan('mdm:enterprise-signup')
            ->expectsOutputToContain('https://enterprise.google.example/signup')
            ->assertExitCode(0);

        [$projectId, $callback] = $this->gateway->calls('createSignupUrl')[0];

        $this->assertSame('gwl-test-project', $projectId);
        $this->assertSame('https://erp.example.test/assets/mdm/enterprise/callback', $callback);
        $this->assertNotNull(Cache::get(EnterpriseService::SIGNUP_URL_CACHE_KEY), 'the signup URL name must survive until enterprises.create');
    }

    public function test_create_command_binds_the_enterprise_and_prints_the_env_line(): void
    {
        $this->artisan('mdm:enterprise-signup')->assertExitCode(0);

        $this->artisan('mdm:enterprise-create', ['token' => 'ENTERPRISE-TOKEN'])
            ->expectsOutputToContain('ANDROID_MANAGEMENT_ENTERPRISE_ID=enterprises/LC0fake')
            ->assertExitCode(0);

        [$project, $signupName, $token, $body] = $this->gateway->calls('createEnterprise')[0];

        $this->assertSame('gwl-test-project', $project);
        $this->assertStringStartsWith('signupUrls/fake-', $signupName);
        $this->assertSame('ENTERPRISE-TOKEN', $token);
        $this->assertSame('projects/gwl-test-project/topics/amapi', $body['pubsubTopic']);
        $this->assertSame(['ENROLLMENT', 'STATUS_REPORT', 'COMMAND', 'USAGE_LOGS'], $body['enabledNotificationTypes']);
        $this->assertNull(Cache::get(EnterpriseService::SIGNUP_URL_CACHE_KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'mdm_enterprise_created', 'module' => 'assets']);
    }

    public function test_create_command_explains_when_the_signup_memory_has_expired(): void
    {
        $this->artisan('mdm:enterprise-create', ['token' => 'X'])
            ->expectsOutputToContain('signup URL name is unknown')
            ->assertExitCode(1);

        $this->assertFalse($this->gateway->called('createEnterprise'));
    }

    public function test_notifications_command_patches_the_topic_and_types_onto_the_configured_enterprise(): void
    {
        config(['gwl.mdm_enterprise_name' => 'enterprises/LC0test']);

        $this->artisan('mdm:enterprise-notifications')->assertExitCode(0);

        [$name, $body, $mask] = $this->gateway->calls('patchEnterprise')[0];

        $this->assertSame('enterprises/LC0test', $name);
        $this->assertSame('pubsubTopic,enabledNotificationTypes', $mask);
        $this->assertSame('projects/gwl-test-project/topics/amapi', $body['pubsubTopic']);
        $this->assertContains('COMMAND', $body['enabledNotificationTypes']);
        $this->assertTrue(AuditLog::query()->where('action', 'mdm_enterprise_notifications_configured')->exists());
    }

    public function test_every_mdm_command_refuses_to_run_while_the_feature_flag_is_off(): void
    {
        config(['gwl.mdm_enabled' => false]);

        foreach (['mdm:enterprise-signup', 'mdm:enterprise-notifications', 'mdm:sync-devices', 'mdm:poll-events'] as $command) {
            $this->artisan($command)->expectsOutputToContain('MDM is disabled')->assertExitCode(1);
        }

        $this->assertSame([], $this->gateway->calls);
    }

    public function test_the_callback_page_is_super_admin_only_and_creation_is_a_post(): void
    {
        $this->superAdmin();
        $this->actingAs($this->userWithRole('ict_team', 'ICT9', $this->district()))
            ->get(route('assets.mdm.enterprise.callback', ['enterpriseToken' => 'T']))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('assets.mdm.enterprise.callback', ['enterpriseToken' => 'T']))
            ->assertForbidden();

        $superAdmin = User::query()->where('staff_id', 'SA001')->firstOrFail();

        $this->actingAs($superAdmin)
            ->get(route('assets.mdm.enterprise.callback', ['enterpriseToken' => 'T']))
            ->assertOk()
            ->assertSee('Create enterprise');

        $this->assertFalse($this->gateway->called('createEnterprise'), 'a GET must never create the enterprise');
    }

    public function test_the_callback_form_creates_the_enterprise_and_shows_the_name(): void
    {
        $superAdmin = $this->superAdmin();
        app(EnterpriseService::class)->startSignup('https://erp.example.test/assets/mdm/enterprise/callback');

        $this->actingAs($superAdmin)
            ->post(route('assets.mdm.enterprise.create'), ['enterpriseToken' => 'TOKEN-1'])
            ->assertOk()
            ->assertSee('ANDROID_MANAGEMENT_ENTERPRISE_ID=enterprises/LC0fake', false);

        $this->assertTrue($this->gateway->called('createEnterprise'));
    }
}
