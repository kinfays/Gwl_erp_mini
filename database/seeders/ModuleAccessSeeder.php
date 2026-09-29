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
        $hrModules = array_values(array_filter(
            Permission::MODULES,
            fn (string $module) => ! in_array($module, [
                Permission::MODULE_UAC,
                Permission::MODULE_ASSETS,
                Permission::MODULE_TRANSPORT,
                Permission::MODULE_CREDIT_UNION,
            ], true)
        ));

        $accessMap = [
            'super_admin' => Permission::MODULES,
            // Assets access lets admin reach the MDM screens (their routes are the only Assets routes that list admin).
            'admin' => [Permission::MODULE_UAC, Permission::MODULE_ASSETS],
            'ict_team' => [Permission::MODULE_UAC, Permission::MODULE_ASSETS],
            'transport_manager' => [Permission::MODULE_TRANSPORT],
            'driver' => [Permission::MODULE_TRANSPORT],
            'credit_union_officer' => [Permission::MODULE_CREDIT_UNION],
            'credit_union_committee' => [Permission::MODULE_CREDIT_UNION],
            'hr_headoffice' => $hrModules,
            'hr_region' => $hrModules,
            'secretary' => [Permission::MODULE_LETTERS],
            'manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'departmental_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'district_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'chief_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'regional_chief_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'employee' => [Permission::MODULE_LEAVE, Permission::MODULE_TRANSPORT, Permission::MODULE_CREDIT_UNION],
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
