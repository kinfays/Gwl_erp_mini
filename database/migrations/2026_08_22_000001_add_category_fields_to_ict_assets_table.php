<?php

use App\Models\IctAsset;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ict_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('ict_assets', 'device_category')) {
                $table->string('device_category')->default(IctAsset::DEVICE_CATEGORY_ASSET)->index()->after('asset_type');
            }
            if (! Schema::hasColumn('ict_assets', 'imei')) {
                $table->string('imei')->nullable()->index()->after('serial_number');
            }
            if (! Schema::hasColumn('ict_assets', 'user_phone_number')) {
                $table->string('user_phone_number')->nullable();
            }
            if (! Schema::hasColumn('ict_assets', 'device_phone_number')) {
                $table->string('device_phone_number')->nullable()->index();
            }
            if (! Schema::hasColumn('ict_assets', 'device_username')) {
                $table->string('device_username')->nullable();
            }
            if (! Schema::hasColumn('ict_assets', 'login_password')) {
                $table->text('login_password')->nullable();
            }
            if (! Schema::hasColumn('ict_assets', 'ssid')) {
                $table->string('ssid')->nullable();
            }
            if (! Schema::hasColumn('ict_assets', 'ssid_password')) {
                $table->text('ssid_password')->nullable();
            }
            if (! Schema::hasColumn('ict_assets', 'actual_location')) {
                $table->string('actual_location')->nullable();
            }
        });

        $this->backfillDeviceCategory();
        $this->seedPermissions();
    }

    public function down(): void
    {
        Schema::table('ict_assets', function (Blueprint $table) {
            foreach ([
                'device_category',
                'imei',
                'user_phone_number',
                'device_phone_number',
                'device_username',
                'login_password',
                'ssid',
                'ssid_password',
                'actual_location',
            ] as $column) {
                if (Schema::hasColumn('ict_assets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        foreach (['assets.manage_ip_ranges', 'assets.view_network_secrets'] as $slug) {
            $permissionId = DB::table('permissions')->where('name', $slug)->value('id');

            if ($permissionId) {
                DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }
    }

    /**
     * asset_type is free text; classify existing rows into the new
     * device_category bucket using a defensive keyword match against the
     * phone/network vocabulary, defaulting everything else to 'asset'.
     */
    protected function backfillDeviceCategory(): void
    {
        $phoneTypes = ['POS', 'SIM', 'Ph', 'Phone'];
        $networkTypes = ['RT', 'SW', 'AP', 'MiFi', 'P2P', 'P2P Radio', '4GRT', '4G Router', 'Router', 'Switch', 'Access Point'];

        DB::table('ict_assets')->whereIn('asset_type', $phoneTypes)->update(['device_category' => IctAsset::DEVICE_CATEGORY_PHONE]);
        DB::table('ict_assets')->whereIn('asset_type', $networkTypes)->update(['device_category' => IctAsset::DEVICE_CATEGORY_NETWORK]);
        DB::table('ict_assets')
            ->whereNotIn('asset_type', array_merge($phoneTypes, $networkTypes))
            ->update(['device_category' => IctAsset::DEVICE_CATEGORY_ASSET]);
    }

    protected function seedPermissions(): void
    {
        $now = now();

        $permissions = [
            'assets.manage_ip_ranges' => 'Manage Ip Ranges',
            'assets.view_network_secrets' => 'View Network Secrets',
        ];

        foreach ($permissions as $slug => $displayName) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $slug],
                [
                    'display_name' => $displayName,
                    'module' => Permission::MODULE_ASSETS,
                    'description' => $displayName.' permission for ASSETS module',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_keys($permissions))
            ->pluck('id', 'name');

        $roleGrants = [
            'super_admin' => ['assets.manage_ip_ranges', 'assets.view_network_secrets'],
            'ict_team' => ['assets.view_network_secrets'],
        ];

        foreach ($roleGrants as $roleName => $slugs) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($slugs as $slug) {
                $permissionId = $permissionIds[$slug] ?? null;

                if (! $permissionId) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }
};
