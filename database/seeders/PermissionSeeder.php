<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'leave' => [
                'leave.view_own',
                'leave.apply',
                'leave.view_zone',
                'leave.approve_recommend',
                'leave.approve_final',
                'leave.manage_compulsory',
                'leave.export',
                'leave.delete_own',
            ],
            'staff' => [
                'staff.view',
                'staff.create',
                'staff.edit',
                'staff.deactivate',
                'staff.import',
                'staff.export',
            ],
            'letters' => [
                'letters.view',
                'letters.create',
                'letters.forward',
                'letters.remark',
                'letters.close',
                'letters.export',
            ],
            'visitors' => [
                'visitors.kiosk',
                'visitors.receptionist_view',
                'visitors.checkout',
                'visitors.export',
            ],
            'uac' => [
                'uac.view_users',
                'uac.create_users',
                'uac.edit_users',
                'uac.assign_roles',
                'uac.manage_roles',
                'uac.manage_permissions',
                'uac.import_data',
                'uac.view_audit_log',
            ],
        ];

        foreach ($permissions as $module => $slugs) {
            foreach ($slugs as $slug) {
                $display = Str::of($slug)
                    ->after('.')
                    ->replace('_', ' ')
                    ->title()
                    ->toString();

                Permission::query()->updateOrCreate(
                    ['name' => $slug],
                    [
                        'display_name' => $display,
                        'module' => $module,
                        'description' => $display . ' permission for ' . strtoupper($module) . ' module',
                    ]
                );
            }
        }
    }
}