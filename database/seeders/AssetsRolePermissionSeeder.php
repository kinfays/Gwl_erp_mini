<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AssetsRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::query()
            ->where('module', Permission::MODULE_ASSETS)
            ->pluck('id', 'name');

        $map = [
            'super_admin' => [
                'assets.view_dashboard',
                'assets.view_inventory',
                'assets.create',
                'assets.edit',
                'assets.manage_models',
                'assets.manage_maintenance',
                'assets.manage_reports',
                'assets.view_agent_reports',
                'assets.link_agent_reports',
                'assets.agent_ingest',
            ],
            'ict_team' => [
                'assets.view_dashboard',
                'assets.view_inventory',
                'assets.create',
                'assets.edit',
                'assets.manage_maintenance',
                'assets.manage_reports',
                'assets.agent_ingest',
            ],
        ];

        foreach ($map as $roleName => $slugs) {
            $role = Role::query()->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $role->permissions()->syncWithoutDetaching(
                collect($slugs)
                    ->map(fn (string $slug) => $permissions[$slug] ?? null)
                    ->filter()
                    ->values()
                    ->all()
            );
        }
    }
}
