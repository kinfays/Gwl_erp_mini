<?php

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class LeaveRolePermissionSeeder extends Seeder
{
    public function run()
    {
        Role::where('name', 'staff')->first()
            ?->givePermissionTo(['leave.view', 'leave.apply']);

        Role::where('name', 'manager')->first()
            ?->givePermissionTo(['leave.view', 'leave.apply', 'leave.recommend']);

        Role::where('name', 'chief_manager')->first()
            ?->givePermissionTo(['leave.view', 'leave.approve']);

        Role::where('name', 'hr_officer')->first()
            ?->givePermissionTo([
                'leave.view',
                'leave.manage_compulsory',
                'leave.reports'
            ]);

        Role::where('name', 'super_admin')->first()
            ?->givePermissionTo(Permission::all());
    }
}