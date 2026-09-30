<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            ModuleAccessSeeder::class,
            StaffRolePermissionSeeder::class,
            LettersRolePermissionSeeder::class,
            VisitorsRolePermissionSeeder::class,
            UacRolePermissionSeeder::class,
            LeaveApprovalRolePermissionSeeder::class,
            AssetsRolePermissionSeeder::class,
            TransportRolePermissionSeeder::class,
            CreditUnionRolePermissionSeeder::class,
            MdmStarterPolicySeeder::class,
            TransportSeeder::class,
            HolidaySeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
