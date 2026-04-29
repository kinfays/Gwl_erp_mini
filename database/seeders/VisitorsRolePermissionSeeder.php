<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class VisitorsRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::query()
            ->where('module', Permission::MODULE_VISITORS)
            ->pluck('id', 'name');

        $map = [
            'receptionist' => ['visitors.kiosk', 'visitors.receptionist_view', 'visitors.checkout', 'visitors.export'],
            'admin' => ['visitors.receptionist_view', 'visitors.checkout', 'visitors.export'],
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
