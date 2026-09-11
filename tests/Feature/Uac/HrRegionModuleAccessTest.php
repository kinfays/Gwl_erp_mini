<?php

namespace Tests\Feature\Uac;

use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrRegionModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_region_role_gets_all_modules_except_the_non_hr_ones_by_default(): void
    {
        $this->seed([
            RoleSeeder::class,
            ModuleAccessSeeder::class,
        ]);

        $this->assertRoleHasExpectedHrModules('hr_region');
    }

    public function test_hr_headoffice_role_gets_all_modules_except_the_non_hr_ones_by_default(): void
    {
        $this->seed([
            RoleSeeder::class,
            ModuleAccessSeeder::class,
        ]);

        $this->assertRoleHasExpectedHrModules('hr_headoffice');
    }

    protected function assertRoleHasExpectedHrModules(string $roleName): void
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $accessibleModules = ModuleAccess::query()
            ->where('role_id', $role->id)
            ->where('can_access', true)
            ->pluck('module')
            ->all();

        $nonHrModules = [
            Permission::MODULE_UAC,
            Permission::MODULE_ASSETS,
            Permission::MODULE_TRANSPORT,
            Permission::MODULE_CREDIT_UNION,
        ];

        $expectedModules = array_values(array_filter(
            Permission::MODULES,
            fn (string $module) => ! in_array($module, $nonHrModules, true)
        ));

        $this->assertEqualsCanonicalizing($expectedModules, $accessibleModules);

        foreach ($nonHrModules as $module) {
            $this->assertNotContains($module, $accessibleModules);
        }
    }
}
