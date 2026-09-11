<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\IssueReports;
use App\Models\IctAssetIssueReport;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class IssueReportCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_type_must_be_one_of_the_fixed_categories(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(IssueReports::class)
            ->call('openCreate')
            ->set('form.title', 'Cannot log in')
            ->set('form.issue_type', 'Something Made Up')
            ->set('form.status', 'Open')
            ->call('save')
            ->assertHasErrors(['form.issue_type']);
    }

    public function test_a_valid_issue_category_saves_successfully(): void
    {
        $this->seedCoreAssetsAccess();
        $this->actingAs($this->superAdmin());

        Livewire::test(IssueReports::class)
            ->call('openCreate')
            ->set('form.title', 'Forgot BitLocker recovery key')
            ->set('form.issue_type', 'BitLocker')
            ->set('form.status', 'Open')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ict_asset_issue_reports', [
            'title' => 'Forgot BitLocker recovery key',
            'issue_type' => 'BitLocker',
        ]);
    }

    public function test_issue_type_options_match_the_fixed_vocabulary(): void
    {
        $this->assertSame([
            'Network',
            'Password Reset',
            'BitLocker',
            'Hardware Fault',
            'Software',
            'Other',
        ], IctAssetIssueReport::ISSUE_TYPES);
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
}
