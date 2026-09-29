<?php

namespace Tests\Feature\Assets\Mdm;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Models\IctAsset;
use App\Models\MdmDevice;
use App\Models\User;
use App\Services\Assets\Mdm\MdmAccessGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Assets\Mdm\Concerns\BuildsMdmFixtures;
use Tests\TestCase;

/**
 * MdmAccessGuard is the service-level twin of the Livewire ScopesAssetsByActor trait (jobs have no Auth::user()).
 * These tests pin the two together so a change to one cannot silently loosen the other.
 */
class ScopingParityTest extends TestCase
{
    use BuildsMdmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMdm();
    }

    public function test_the_guard_and_the_assets_trait_agree_for_every_kind_of_actor(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        foreach ([$accra, $kumasi, $accra, $kumasi] as $district) {
            $this->phone($district);
        }
        IctAsset::factory()->phone()->create(['asset_type' => 'Ph', 'region_id' => null]); // no region at all

        $actors = [
            'super_admin' => $this->superAdmin($accra),
            'admin' => $this->admin(),
            'ict in accra' => $this->ictIn($accra, 'ICT001'),
            'ict in kumasi' => $this->ictIn($kumasi, 'ICT002'),
            'ict with no employee record' => $this->userWithRole('ict_team', 'ICT003'),
            'hr' => $this->userWithRole('hr_headoffice', 'HR001', $accra),
        ];

        $viaTrait = new class
        {
            use ScopesAssetsByActor {
                scopeAssetsForActor as public scoped;
            }
        };

        foreach ($actors as $label => $actor) {
            $this->actingAs($actor);

            $trait = $viaTrait->scoped(IctAsset::query())->orderBy('id')->pluck('id')->all();
            $guard = app(MdmAccessGuard::class)->assets($actor)->orderBy('id')->pluck('id')->all();

            // The trait has no notion of "not an MDM operator" (its routes are role-gated), so an HR user is the one
            // deliberate difference: the guard is stricter and returns nothing.
            $expected = $label === 'hr' ? [] : $trait;

            $this->assertSame($expected, $guard, "guard and trait disagree for: {$label}");
        }
    }

    public function test_devices_are_scoped_by_their_assets_region(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $inAccra = MdmDevice::factory()->forAsset($this->phone($accra))->create();
        $inKumasi = MdmDevice::factory()->forAsset($this->phone($kumasi))->create();
        $unlinked = MdmDevice::factory()->unlinked()->create();
        $guard = app(MdmAccessGuard::class);

        $ids = fn (User $user) => $guard->devices($user)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$inAccra->id], $ids($this->ictIn($accra, 'ICT001')));
        $this->assertSame([$inKumasi->id], $ids($this->ictIn($kumasi, 'ICT002')));
        $this->assertSame([$inAccra->id, $inKumasi->id, $unlinked->id], $ids($this->admin()));
        $this->assertSame([$inAccra->id, $inKumasi->id, $unlinked->id], $ids($this->superAdmin()));
        $this->assertSame([], $ids($this->userWithRole('ict_team', 'ICT003')), 'no employee record, no region, no devices');
        $this->assertSame([], $ids($this->userWithRole('employee', 'EMP001', $accra)));
    }

    public function test_a_region_scoped_user_moving_region_loses_access_immediately(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $device = MdmDevice::factory()->forAsset($this->phone($accra))->create();
        $user = $this->ictIn($accra);
        $guard = app(MdmAccessGuard::class);

        $this->assertTrue($guard->canAccessDevice($user, $device));

        $user->employee->update(['region_id' => $kumasi->region_id, 'district_id' => $kumasi->id]);

        $this->assertFalse($guard->canAccessDevice($user->fresh(), $device));
    }

    public function test_the_enrollable_list_excludes_everything_outside_the_actors_scope_or_state(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $guard = app(MdmAccessGuard::class);

        $eligible = $this->phone($accra);
        $foreign = $this->phone($kumasi);
        $this->phone($accra, ['asset_type' => 'SIM']);
        $this->phone($accra, ['status' => IctAsset::STATUS_RETIRED]);
        $this->phone($accra, ['serial_number' => null, 'imei' => null]);
        MdmDevice::factory()->forAsset($this->phone($accra))->create();

        $this->assertSame([$eligible->id], $guard->enrollableAssets($this->ictIn($accra))->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$eligible->id, $foreign->id], $guard->enrollableAssets($this->admin())->pluck('id')->all());
    }

    public function test_device_or_fail_is_a_404_for_other_regions(): void
    {
        $accra = $this->district('Accra Central District', 'Greater Accra');
        $kumasi = $this->district('Kumasi Central District', 'Ashanti');
        $foreign = MdmDevice::factory()->forAsset($this->phone($kumasi))->create();

        $this->expectException(ModelNotFoundException::class);

        app(MdmAccessGuard::class)->deviceOrFail($this->ictIn($accra), $foreign->id);
    }

    public function test_only_unscoped_users_with_the_command_permission_may_clear_a_review_flag(): void
    {
        $guard = app(MdmAccessGuard::class);

        $this->assertTrue($guard->canReview($this->superAdmin()));
        $this->assertTrue($guard->canReview($this->admin()));
        $this->assertFalse($guard->canReview($this->ictIn($this->district())));
    }
}
