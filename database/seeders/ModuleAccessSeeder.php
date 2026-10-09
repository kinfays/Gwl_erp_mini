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
                Permission::MODULE_COMMERCIAL,
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
            'commercial_officer' => [Permission::MODULE_COMMERCIAL],
            'commercial_manager' => [Permission::MODULE_COMMERCIAL],
            'hs_officer' => [],
            'hs_manager' => [],
            'pr_officer' => [],
            'hr_headoffice' => $hrModules,
            'hr_region' => $hrModules,
            'secretary' => [Permission::MODULE_LETTERS],
            'manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'departmental_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS],
            'district_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS, Permission::MODULE_COMMERCIAL],
            'chief_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS, Permission::MODULE_COMMERCIAL],
            'regional_chief_manager' => [Permission::MODULE_LEAVE, Permission::MODULE_STAFF, Permission::MODULE_LETTERS, Permission::MODULE_COMMERCIAL],
            'managing_director' => [Permission::MODULE_LEAVE],
            'employee' => [Permission::MODULE_LEAVE, Permission::MODULE_TRANSPORT, Permission::MODULE_CREDIT_UNION],
            'receptionist' => [Permission::MODULE_VISITORS],
        ];

        foreach ($accessMap as $roleSlug => $modules) {
            // Every member of staff reports incidents, so every role may enter Health & Safety; its permissions decide what
            // they then see.
            $modules[] = Permission::MODULE_HEALTH_SAFETY;

            // Likewise every member of staff reads the blog of their own region; only blog.manage_posts (PR Officer) writes.
            $modules[] = Permission::MODULE_BLOG;

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
