<?php

namespace Database\Seeders;

use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class ModuleAccessSeeder extends Seeder
{
    public function run(): void
    {
        $accessMap = [
            'super_admin' => Permission::MODULES,
            'admin' => Permission::MODULES,
            'hr_headoffice' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_UAC],
            'hr_region' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_UAC],
            'manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'departmental_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'district_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'chief_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'regional_chief_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'employee' => [Permission::MODULE_LEAVE],
            'receptionist' => [Permission::MODULE_VISITORS],
        ];

        foreach ($accessMap as $roleSlug => $modules) {
            $role = Role::query()->where('name', $roleSlug)->first();

            if (! $role) {
                continue;
            }

            foreach (Permission::MODULES as $module) {
                ModuleAccess::query()->updateOrCreate(
                    [
                        'role_id' => $role->id,
                        'module' => $module,
                    ],
                    [
                        'can_access' => in_array($module, $modules, true),
                    ]
                );
            }
        }
    }
}