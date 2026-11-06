<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\AssetsList;
use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\PhonesList;
use App\Models\Department;
use App\Models\IctAsset;
use App\Services\Assets\AssetRecordService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Assets\Concerns\BuildsAssetFixtures;
use Tests\TestCase;

class AssetNameUniquenessTest extends TestCase
{
    use BuildsAssetFixtures;
    use RefreshDatabase;

    private const MESSAGE = 'An asset with this name already exists.';

    public function test_creating_an_asset_with_an_existing_name_is_refused_whatever_the_case_or_spacing(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $this->makeAsset($district, ['asset_name' => 'Front Desk PC']);

        foreach (['Front Desk PC', 'front desk pc', '  FRONT DESK PC  '] as $typed) {
            $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district)
                ->set('form.asset_name', $typed)
                ->call('save')
                ->assertHasErrors(['form.asset_name'])
                ->assertSee(self::MESSAGE);
        }

        $this->assertSame(1, IctAsset::query()->count());
    }

    public function test_the_name_is_trimmed_when_saved(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));

        $this->fillForm(Livewire::test(AssetsList::class)->call('openCreate'), $district)
            ->set('form.asset_name', '   Reception Laptop   ')
            ->call('save')
            ->assertHasNoErrors();

        $asset = IctAsset::query()->firstOrFail();
        $this->assertSame('Reception Laptop', $asset->asset_name);
        $this->assertSame('reception laptop', $asset->asset_name_key);
    }

    public function test_editing_an_asset_keeps_its_own_name_but_cannot_take_another_assets_name(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $one = $this->makeAsset($district, ['asset_name' => 'Server Room PC', 'asset_type' => 'PC']);
        $this->makeAsset($district, ['asset_name' => 'Boardroom PC', 'asset_type' => 'PC']);

        $component = Livewire::test(AssetsList::class)->call('openEdit', $one->id);
        $this->completeEditForm($component, $district);

        // Saving with its own name (even re-cased) is not a clash.
        $component->set('form.asset_name', 'SERVER ROOM PC')->call('save')->assertHasNoErrors();
        $this->assertSame('SERVER ROOM PC', $one->fresh()->asset_name);

        $component = Livewire::test(AssetsList::class)->call('openEdit', $one->id);
        $this->completeEditForm($component, $district);
        $component->set('form.asset_name', ' boardroom pc ')->call('save')
            ->assertHasErrors(['form.asset_name'])
            ->assertSee(self::MESSAGE);

        $this->assertSame('SERVER ROOM PC', $one->fresh()->asset_name);
    }

    public function test_the_rule_holds_across_phones_and_network_devices_which_share_the_table(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $this->makeAsset($district, ['asset_name' => 'Shared Name']);

        Livewire::test(PhonesList::class)->call('openCreate')
            ->set('form.asset_name', 'shared name')
            ->call('save')
            ->assertHasErrors(['form.asset_name']);

        Livewire::test(NetworkList::class)->call('openCreate')
            ->set('form.asset_name', 'SHARED NAME')
            ->call('save')
            ->assertHasErrors(['form.asset_name']);
    }

    public function test_the_service_refuses_a_duplicate_even_when_the_form_check_was_skipped(): void
    {
        $this->seedCoreAssetsAccess();
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->actingAs($this->superAdmin($district));
        $this->makeAsset($district, ['asset_name' => 'Core Switch']);

        try {
            app(AssetRecordService::class)->save([
                'asset_name' => 'core switch ',
                'serial_number' => 'SW-1',
                'asset_type' => 'SW',
                'status' => 'Active',
                'region_id' => $district->region_id,
                'district_id' => $district->id,
            ], IctAsset::DEVICE_CATEGORY_NETWORK);

            $this->fail('A duplicate name was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame([self::MESSAGE], $e->errors()['form.asset_name']);
        }

        $this->assertSame(1, IctAsset::query()->count());
    }

    public function test_the_database_index_is_the_last_line_of_defence(): void
    {
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->makeAsset($district, ['asset_name' => 'Edge Router']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeAsset($district, ['asset_name' => '  edge router']);
    }

    public function test_the_duplicates_command_lists_clashes_without_changing_anything(): void
    {
        $district = $this->makeDistrict('Accra Central District', 'Greater Accra');
        $this->artisan('assets:duplicate-names')->expectsOutputToContain('No duplicate asset names.')->assertSuccessful();

        // Simulate legacy data: two rows that share a name (written around the model so the key stays distinct).
        $a = $this->makeAsset($district, ['asset_name' => 'Legacy A']);
        $b = $this->makeAsset($district, ['asset_name' => 'Legacy B']);
        DB::table('ict_assets')->where('id', $b->id)->update(['asset_name' => ' legacy a ']);

        $this->artisan('assets:duplicate-names')
            ->expectsOutputToContain('used more than once')
            ->assertFailed();

        $this->artisan('assets:duplicate-names', ['--add-index' => true])
            ->expectsOutputToContain('Rename the assets above first')
            ->assertFailed();

        $this->assertSame('Legacy A', $a->fresh()->asset_name);
        $this->assertSame(' legacy a ', $b->fresh()->asset_name);

        DB::table('ict_assets')->where('id', $b->id)->update(['asset_name' => 'Legacy B']);
        $this->artisan('assets:duplicate-names', ['--add-index' => true])
            ->expectsOutputToContain('already in place')
            ->assertSuccessful();
    }

    protected function fillForm($component, $district)
    {
        $assignee = \App\Models\Employee::query()->where('staff_id', '100001')->first() ?? $this->makeEmployee('100001', 'Assigned Employee', $district);
        $department = Department::query()->firstOrCreate(['department_name' => 'ICT']);

        return $component
            ->set('form.serial_number', 'PC-'.uniqid())
            ->set('form.asset_type', 'PC')
            ->set('form.ict_asset_model_id', $this->makeModel()->id)
            ->set('form.assigned_to_employee_id', $assignee->id)
            ->set('form.department_id', $department->id)
            ->set('form.district_id', $district->id)
            ->set('form.status', IctAsset::STATUS_ACTIVE);
    }

    /** An existing asset has no assignee/department/model in these fixtures, and the form requires them. */
    protected function completeEditForm($component, $district)
    {
        $assignee = \App\Models\Employee::query()->where('staff_id', '100001')->first() ?? $this->makeEmployee('100001', 'Assigned Employee', $district);

        return $component
            ->set('form.ict_asset_model_id', $this->makeModel()->id)
            ->set('form.assigned_to_employee_id', $assignee->id)
            ->set('form.department_id', Department::query()->firstOrCreate(['department_name' => 'ICT'])->id);
    }
}
