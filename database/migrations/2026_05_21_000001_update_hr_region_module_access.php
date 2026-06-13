<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->syncHrRegionModules(
            array_values(array_filter(
                Permission::MODULES,
                fn (string $module) => $module !== Permission::MODULE_UAC
            ))
        );
    }

    public function down(): void
    {
        $this->syncHrRegionModules([
            Permission::MODULE_LEAVE,
            Permission::MODULE_STAFF,
        ]);
    }

    protected function syncHrRegionModules(array $allowedModules): void
    {
        $roleId = Role::query()->where('name', 'hr_region')->value('id');

        if (! $roleId) {
            return;
        }

        $now = now();

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
};
