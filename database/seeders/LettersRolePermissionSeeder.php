<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class LettersRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = Permission::query()
            ->where('module', Permission::MODULE_LETTERS)
            ->pluck('id', 'name');

        $map = [
            'secretary' => ['letters.view', 'letters.create', 'letters.forward', 'letters.remark', 'letters.close', 'letters.export'],
            'manager' => ['letters.view', 'letters.forward', 'letters.remark'],
            'departmental_manager' => ['letters.view', 'letters.forward', 'letters.remark'],
            'district_manager' => ['letters.view', 'letters.forward', 'letters.remark'],
            'chief_manager' => ['letters.view', 'letters.forward', 'letters.remark', 'letters.close'],
            'regional_chief_manager' => ['letters.view', 'letters.forward', 'letters.remark', 'letters.close'],
            'admin' => ['letters.view', 'letters.create', 'letters.forward', 'letters.remark', 'letters.close', 'letters.export'],
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
