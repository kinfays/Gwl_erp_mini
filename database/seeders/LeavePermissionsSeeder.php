<?php

use Illuminate\Database\Seeder;
use App\Models\Permission;

class LeavePermissionsSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
            'leave.view',
            'leave.apply',
            'leave.recommend',
            'leave.approve',
            'leave.manage_compulsory',
            'leave.reports',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}