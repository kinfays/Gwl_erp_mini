<?php

namespace Tests\Feature\Assets\Mdm;

use App\Livewire\Assets\Mdm\EnrollPhone;
use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\MdmDevice;
use App\Models\MdmEnrollmentToken;
use App\Models\MdmPolicy;
use App\Models\Permission;
use App\Services\Assets\Mdm\EnrollmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
    }

    public function test_the_token_is_created_for_the_chosen_policy_with_the_asset_id_in_additional_data(): void
    {
        $district = $this->district();
        $actor = $this->ictIn($district);
        $asset = $this->phone($district);
        $policy = MdmPolicy::factory()->published()->create();

        $result = app(EnrollmentService::class)->generate($actor, $asset->id, $policy->id);

        [$enterprise, $request] = $this->gateway->calls('createEnrollmentToken')[0];

        $this->assertSame('enterprises/LC0test', $enterprise);
        $this->assertSame($policy->google_policy_name, $request['policyName']);
        $this->assertSame('PERSONAL_USAGE_DISALLOWED', $request['allowPersonalUsage']);
        $this->assertTrue($request['oneTimeOnly']);
        $this->assertSame('3600s', $request['duration']);
        $this->assertSame(['asset_id' => $asset->id, 'requested_by' => $actor->id], json_decode($request['additionalData'], true));

        $this->assertStringContainsString('PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME', $result['qr_payload']);
        $this->assertTrue($result['expires_at']->isFuture());
    }

    public function test_the_record_keeps_who_and_which_phone_but_never_the_token_value_or_qr(): void
    {
        $district = $this->district();
        $actor = $this->ictIn($district);
        $asset = $this->phone($district);
        $policy = MdmPolicy::factory()->published()->create();

        $result = app(EnrollmentService::class)->generate($actor, $asset->id, $policy->id);

        $token = $result['token']->fresh();

        $this->assertSame($asset->id, $token->ict_asset_id);
        $this->assertSame($policy->id, $token->mdm_policy_id);
        $this->assertSame($actor->id, $token->created_by);
        $this->assertNull($token->used_at);
        $this->assertNotNull($token->expires_at);

        $stored = json_encode(MdmEnrollmentToken::query()->get()->toArray());
        $this->assertStringNotContainsString('SECRETVALUE', $stored);
        $this->assertStringNotContainsString('PROVISIONING', $stored);
        $this->assertSame(0, AuditLog::query()->where('metadata', 'like', '%SECRETVALUE%')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'mdm_enrollment_token_created')->exists());
    }

    public function test_the_token_lifetime_comes_from_config(): void
    {
        config(['gwl.mdm_enrollment_token_minutes' => 15]);
        $district = $this->district();

        app(EnrollmentService::class)->generate($this->ictIn($district), $this->phone($district)->id, MdmPolicy::factory()->published()->create()->id);

        $this->assertSame('900s', $this->gateway->calls('createEnrollmentToken')[0][1]['duration']);
    }

    public function test_a_region_scoped_ict_user_cannot_enroll_a_phone_in_another_region(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $actor = $this->ictIn($accra);
        $foreign = $this->phone($kumasi);
        $policy = MdmPolicy::factory()->published()->create();

        try {
            app(EnrollmentService::class)->generate($actor, $foreign->id, $policy->id);
            $this->fail('Expected a validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('asset', $e->errors());
        }

        $this->assertFalse($this->gateway->called('createEnrollmentToken'));
        $this->assertSame(0, MdmEnrollmentToken::query()->count());
    }

    public function test_admin_and_super_admin_can_enroll_a_phone_in_any_region(): void
    {
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $policy = MdmPolicy::factory()->published()->create();

        app(EnrollmentService::class)->generate($this->admin(), $this->phone($kumasi)->id, $policy->id);
        app(EnrollmentService::class)->generate($this->superAdmin(), $this->phone($kumasi)->id, $policy->id);

        $this->assertCount(2, $this->gateway->calls('createEnrollmentToken'));
    }

    public function test_only_active_handsets_with_an_identity_that_are_not_already_enrolled_are_eligible(): void
    {
        $district = $this->district();
        $actor = $this->ictIn($district);
        $policy = MdmPolicy::factory()->published()->create();
        $service = app(EnrollmentService::class);

        $pos = $this->phone($district, ['asset_type' => 'POS']);
        $sim = $this->phone($district, ['asset_type' => 'SIM']);
        $retired = $this->phone($district, ['status' => IctAsset::STATUS_RETIRED]);
        $lost = $this->phone($district, ['status' => IctAsset::STATUS_LOST]);
        $noIdentity = $this->phone($district, ['serial_number' => null, 'imei' => null]);
        $laptop = IctAsset::factory()->create(['region_id' => $district->region_id]);
        $enrolled = $this->phone($district);
        MdmDevice::factory()->forAsset($enrolled)->create();

        foreach ([$pos, $sim, $retired, $lost, $noIdentity, $laptop, $enrolled] as $ineligible) {
            try {
                $service->generate($actor, $ineligible->id, $policy->id);
                $this->fail("Asset {$ineligible->id} ({$ineligible->asset_type}/{$ineligible->status}) should not be enrollable.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('asset', $e->errors());
            }
        }

        $this->assertFalse($this->gateway->called('createEnrollmentToken'));
    }

    public function test_a_phone_whose_device_was_deleted_can_be_enrolled_again(): void
    {
        $district = $this->district();
        $asset = $this->phone($district);
        MdmDevice::factory()->forAsset($asset)->create(['state' => 'DELETED']);

        $result = app(EnrollmentService::class)->generate($this->ictIn($district), $asset->id, MdmPolicy::factory()->published()->create()->id);

        $this->assertSame($asset->id, $result['token']->ict_asset_id);
    }

    public function test_an_unpublished_policy_cannot_be_used(): void
    {
        $district = $this->district();
        $policy = MdmPolicy::factory()->create();

        $this->expectException(ValidationException::class);

        app(EnrollmentService::class)->generate($this->ictIn($district), $this->phone($district)->id, $policy->id);
    }

    public function test_a_user_without_the_enroll_permission_is_refused(): void
    {
        $district = $this->district();
        $actor = $this->ictIn($district);
        $actor->roles->first()?->permissions()->detach(Permission::query()->where('name', 'assets.mdm_enroll')->value('id'));

        $this->expectException(AuthorizationException::class);

        app(EnrollmentService::class)->generate($actor->fresh(), $this->phone($district)->id, MdmPolicy::factory()->published()->create()->id);
    }

    public function test_the_livewire_screen_shows_the_qr_and_expiry_and_lists_only_phones_in_scope(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $actor = $this->ictIn($accra);
        $mine = $this->phone($accra, ['asset_name' => 'Accra Phone']);
        $foreign = $this->phone($kumasi, ['asset_name' => 'Kumasi Phone']);
        $policy = MdmPolicy::factory()->published()->create();

        $component = Livewire::actingAs($actor)->test(EnrollPhone::class)
            ->assertSee('Accra Phone')
            ->assertDontSee('Kumasi Phone')
            ->assertSet('policyId', $policy->id)
            ->set('assetId', $mine->id)
            ->call('generate')
            ->assertHasNoErrors()
            ->assertSee('Scan this code')
            ->assertSee('Valid until')
            ->assertSee('Rollout steps')
            ->assertSee('tap the screen')
            ->assertSee('Factory reset the phone');

        $this->assertStringContainsString('<svg', $component->get('qrSvg'));

        $component->call('newCode')->assertSet('qrSvg', null)->assertDontSee('Scan this code');
    }

    public function test_tampering_with_the_asset_id_in_livewire_cannot_enroll_another_regions_phone(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $actor = $this->ictIn($accra);
        $foreign = $this->phone($kumasi);
        $policy = MdmPolicy::factory()->published()->create();

        Livewire::actingAs($actor)->test(EnrollPhone::class)
            ->set('assetId', $foreign->id)
            ->set('policyId', $policy->id)
            ->call('generate')
            ->assertHasErrors(['asset'])
            ->assertSet('qrSvg', null);

        $this->assertFalse($this->gateway->called('createEnrollmentToken'));
    }

    public function test_the_qr_state_cannot_be_written_from_the_client(): void
    {
        $district = $this->district();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->ictIn($district))->test(EnrollPhone::class)->set('qrSvg', '<svg onload=alert(1)>');
    }
}
