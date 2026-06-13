<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->syncHrModules(
            array_values(array_filter(
                Permission::MODULES,
                fn (string $module) => ! in_array($module, [Permission::MODULE_UAC, Permission::MODULE_ASSETS], true)
            ))
        );
    }

    public function down(): void
    {
        $this->syncHrModules([
            Permission::MODULE_LEAVE,
            Permission::MODULE_STAFF,
        ]);
    }

    protected function syncHrModules(array $allowedModules): void
    {
        $roleIds = Role::query()
            ->whereIn('name', ['hr_headoffice', 'hr_region'])
            ->pluck('id')
            ->all();

        if (empty($roleIds)) {
            return;
        }

        $now = now();

        foreach ($roleIds as $roleId) {
            foreach (Permission::MODULES as $module) {
                DB::table('module_access')->updateOrInsert(
                    ['role_id' => $roleId, 'module' => $module],
                    [
                        'can_access' => in_array($module, $allowedModules, true),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }
};
