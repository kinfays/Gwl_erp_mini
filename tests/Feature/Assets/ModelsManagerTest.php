<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\NetworkList;
use App\Livewire\Assets\Settings\ModelsManager;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
use App\Models\JobTitle;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ModelsManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_super_admin_can_create_a_model_with_a_manufacturer_and_image(): void
    {
        $this->seedCoreAssetsAccess();
        $manufacturer = $this->manufacturer('HP');
        $this->actingAs($this->superAdmin());

        Livewire::test(ModelsManager::class)
            ->set('name', 'HP EliteBook 840 G9')
            ->set('category', 'Laptop')
            ->set('manufacturer_id', $manufacturer->id)
            ->set('image', UploadedFile::fake()->image('elitebook.png', 320, 240))
            ->call('save')
            ->assertHasNoErrors();

        $model = IctAssetModel::query()->where('name', 'HP EliteBook 840 G9')->firstOrFail();

        $this->assertSame($manufacturer->id, $model->ict_asset_manufacturer_id);
        $this->assertTrue($model->is_active);
        $this->assertStringStartsWith(IctAssetModel::IMAGE_DIRECTORY.'/', (string) $model->image_path);
        Storage::disk('public')->assertExists($model->image_path);
        $this->assertTrue(AuditLog::query()->where('action', 'create_asset_model')->where('target_id', $model->id)->exists());
    }

    public function test_image_and_manufacturer_are_optional_when_creating_a_model(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(ModelsManager::class)
            ->set('name', 'Generic Desktop')
            ->set('category', 'PC')
            ->call('save')
            ->assertHasNoErrors();

        $model = IctAssetModel::query()->where('name', 'Generic Desktop')->firstOrFail();
        $this->assertNull($model->image_path);
        $this->assertNull($model->ict_asset_manufacturer_id);
    }

    #[DataProvider('invalidImages')]
    public function test_model_image_must_be_a_jpg_png_or_webp_of_at_most_2mb(string $filename, int $kilobytes): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        $file = str_ends_with($filename, '.pdf')
            ? UploadedFile::fake()->create($filename, $kilobytes, 'application/pdf')
            : UploadedFile::fake()->image($filename)->size($kilobytes);

        Livewire::test(ModelsManager::class)
            ->set('name', 'Rejected Upload Model')
            ->set('category', 'PC')
            ->set('image', $file)
            ->call('save')
            ->assertHasErrors(['image']);

        $this->assertFalse(IctAssetModel::query()->where('name', 'Rejected Upload Model')->exists());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public static function invalidImages(): array
    {
        return [
            'pdf document' => ['datasheet.pdf', 100],
            'gif image' => ['animated.gif', 100],
            'png over 2MB' => ['huge.png', 2049],
        ];
    }

    public function test_replacing_a_model_image_deletes_the_old_file(): void
    {
        $this->seedCoreAssetsAccess();
        $model = $this->modelWithStoredImage('ict-asset-models/original.png');
        $this->actingAs($this->superAdmin());

        Livewire::test(ModelsManager::class)
            ->call('edit', $model->id)
            ->set('editingImage', UploadedFile::fake()->image('replacement.jpg'))
            ->call('update')
            ->assertHasNoErrors();

        $model->refresh();

        $this->assertNotSame('ict-asset-models/original.png', $model->image_path);
        Storage::disk('public')->assertExists($model->image_path);
        Storage::disk('public')->assertMissing('ict-asset-models/original.png');
    }

    public function test_editing_a_model_without_a_new_image_keeps_the_existing_file(): void
    {
        $this->seedCoreAssetsAccess();
        $model = $this->modelWithStoredImage('ict-asset-models/original.png');
        $this->actingAs($this->superAdmin());

        Livewire::test(ModelsManager::class)
            ->call('edit', $model->id)
            ->set('editingName', 'HP ProDesk 400 G7 Renamed')
            ->call('update')
            ->assertHasNoErrors();

        $model->refresh();

        $this->assertSame('HP ProDesk 400 G7 Renamed', $model->name);
        $this->assertSame('ict-asset-models/original.png', $model->image_path);
        Storage::disk('public')->assertExists('ict-asset-models/original.png');
    }

    public function test_removing_a_model_image_deletes_the_file_and_clears_the_path(): void
    {
        $this->seedCoreAssetsAccess();
        $model = $this->modelWithStoredImage('ict-asset-models/original.png');
        $this->actingAs($this->superAdmin());

        Livewire::test(ModelsManager::class)
            ->call('edit', $model->id)
            ->call('removeImage', $model->id)
            ->assertHasNoErrors();

        $this->assertNull($model->refresh()->image_path);
        Storage::disk('public')->assertMissing('ict-asset-models/original.png');
        $this->assertTrue(AuditLog::query()->where('action', 'remove_asset_model_image')->where('target_id', $model->id)->exists());
    }

    public function test_deleting_a_model_also_deletes_its_image_file(): void
    {
        $this->seedCoreAssetsAccess();
        $model = $this->modelWithStoredImage('ict-asset-models/original.png');
        $this->actingAs($this->superAdmin());

        Livewire::test(ModelsManager::class)
            ->call('delete', $model->id);

        $this->assertDatabaseMissing('ict_asset_models', ['id' => $model->id]);
        Storage::disk('public')->assertMissing('ict-asset-models/original.png');
    }

    public function test_a_model_still_used_by_assets_is_not_deleted_and_keeps_its_image(): void
    {
        $this->seedCoreAssetsAccess();
        $model = $this->modelWithStoredImage('ict-asset-models/original.png');

        IctAsset::query()->create([
            'asset_name' => 'Front Desk PC',
            'serial_number' => 'PC-0001',
            'asset_type' => 'PC',
            'device_category' => IctAsset::DEVICE_CATEGORY_ASSET,
            'status' => IctAsset::STATUS_ACTIVE,
            'ict_asset_model_id' => $model->id,
        ]);

        $this->actingAs($this->superAdmin());

        Livewire::test(ModelsManager::class)
            ->call('delete', $model->id);

        $this->assertDatabaseHas('ict_asset_models', ['id' => $model->id]);
        Storage::disk('public')->assertExists('ict-asset-models/original.png');
    }

    public function test_inactive_manufacturers_are_not_offered_or_accepted_for_new_models(): void
    {
        $this->seedCoreAssetsAccess();
        $active = $this->manufacturer('Dell');
        $inactive = $this->manufacturer('Compaq', false);
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(ModelsManager::class);
        $offered = $component->viewData('manufacturers')->pluck('id')->all();

        $this->assertContains($active->id, $offered);
        $this->assertNotContains($inactive->id, $offered);

        $component
            ->set('name', 'Compaq Presario')
            ->set('category', 'PC')
            ->set('manufacturer_id', $inactive->id)
            ->call('save')
            ->assertHasErrors(['manufacturer_id' => 'exists']);
    }

    public function test_models_and_manufacturers_are_shared_across_every_region(): void
    {
        $this->seedCoreAssetsAccess();
        $manufacturer = $this->manufacturer('Cisco');
        $model = IctAssetModel::query()->create([
            'name' => 'Cisco ISR 1100',
            'category' => 'RT',
            'ict_asset_manufacturer_id' => $manufacturer->id,
            'is_active' => true,
        ]);

        foreach (['ICT001' => 'Greater Accra', 'ICT002' => 'Ashanti'] as $staffId => $regionName) {
            $this->actingAs($this->ictTeamMember($staffId, $this->district($regionName.' District', $regionName)));

            $offered = Livewire::test(NetworkList::class)->viewData('models');

            $this->assertTrue($offered->contains('id', $model->id), "{$regionName} ICT should see the shared model.");
            $this->assertSame('Cisco', $offered->firstWhere('id', $model->id)->manufacturer->name);
        }
    }

    public function test_ict_team_member_without_manage_models_permission_cannot_open_the_screen(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->ictTeamMember('ICT001', $this->district('Accra Central District', 'Greater Accra')));

        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);

        Livewire::test(ModelsManager::class);
    }

    protected function modelWithStoredImage(string $path): IctAssetModel
    {
        Storage::disk('public')->put($path, 'image-bytes');

        return IctAssetModel::query()->create([
            'name' => 'HP ProDesk 400 G7',
            'category' => 'PC',
            'image_path' => $path,
            'is_active' => true,
        ]);
    }

    protected function manufacturer(string $name, bool $isActive = true): IctAssetManufacturer
    {
        return IctAssetManufacturer::query()->create(['name' => $name, 'is_active' => $isActive]);
    }

    protected function seedCoreAssetsAccess(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            AssetsRolePermissionSeeder::class,
        ]);
    }

    protected function superAdmin(): User
    {
        $user = $this->user('SA001');
        $user->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());

        return $user;
    }

    protected function ictTeamMember(string $staffId, District $homeDistrict): User
    {
        $user = $this->user($staffId);
        $user->roles()->attach(Role::query()->where('name', 'ict_team')->firstOrFail());
        $this->createEmployee($staffId, 'ICT '.$staffId, $homeDistrict);

        return $user;
    }

    protected function user(string $staffId, array $overrides = []): User
    {
        return User::query()->create([
            'staff_id' => $staffId,
            'full_name' => 'User '.$staffId,
            'email' => strtolower($staffId).'@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
            ...$overrides,
        ]);
    }

    protected function district(string $districtName, string $regionName): District
    {
        $region = Region::query()->firstOrCreate(['region_name' => $regionName]);

        return District::query()->firstOrCreate(
            ['district_name' => $districtName],
            ['region_id' => $region->id]
        );
    }

    protected function createEmployee(string $staffId, string $fullName, District $district): Employee
    {
        $department = Department::query()->firstOrCreate(['department_name' => 'Administration']);
        $jobTitle = JobTitle::query()->firstOrCreate(['job_title_name' => 'Officer']);

        return Employee::query()->create([
            'staff_id' => $staffId,
            'full_name' => $fullName,
            'gender' => 'Male',
            'category' => 'Senior Staff',
            'email' => strtolower($staffId).'@example.com',
            'job_title_id' => $jobTitle->id,
            'department_id' => $department->id,
            'region_id' => $district->region_id,
            'district_id' => $district->id,
            'location_type' => 'District',
            'date_of_birth' => '1990-01-01',
            'date_joined' => '2026-01-01',
            'present_appointment' => '2026-01-01',
            'is_active' => true,
        ]);
    }
}
