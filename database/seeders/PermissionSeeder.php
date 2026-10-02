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
                'leave.manage_hr_contacts',
            ],
            'staff' => [
                'staff.view',
                'staff.create',
                'staff.edit',
                'staff.deactivate',
                'staff.import',
                'staff.export',
                'staff.view_reports',
                'staff.manage_departments',
                'staff.manage_regions',
                'staff.manage_locations',
                'staff.manage_job_titles',
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
            'assets' => [
                'assets.view_dashboard',
                'assets.view_inventory',
                'assets.create',
                'assets.edit',
                'assets.manage_models',
                'assets.manage_manufacturers',
                'assets.manage_maintenance',
                'assets.manage_reports',
                'assets.view_agent_reports',
                'assets.link_agent_reports',
                'assets.agent_ingest',
                'assets.manage_ip_ranges',
                'assets.view_network_secrets',
                'assets.mdm_view',
                'assets.mdm_manage_policies',
                'assets.mdm_enroll',
                'assets.mdm_command',
                'assets.mdm_wipe',
            ],
            'transport' => [
                'transport.view_dashboard',
                'transport.view_vehicles',
                'transport.create_vehicles',
                'transport.edit_vehicles',
                'transport.assign_vehicles',
                'transport.import_vehicles',
                'transport.view_own_vehicle',
                'transport.log_mileage',
                'transport.report_issues',
                'transport.manage_issues',
                'transport.manage_maintenance',
                'transport.manage_expenses',
                'transport.export_expenses',
                'transport.view_reports',
                'transport.renew_documents',
            ],
            'credit_union' => [
                'credit_union.view_dashboard',
                'credit_union.view_own_statement',
                'credit_union.apply_membership',
                'credit_union.manage_members',
                'credit_union.approve_membership',
                'credit_union.manage_deductions',
                'credit_union.manage_loans',
                'credit_union.approve_loans',
                'credit_union.manage_withdrawals',
                'credit_union.approve_withdrawals',
                'credit_union.manage_refunds',
                'credit_union.manage_receipts',
                'credit_union.manage_interest_distribution',
                'credit_union.approve_interest_distribution',
                'credit_union.view_reports',
                'credit_union.export_reports',
                'credit_union.manage_settings',
            ],
        ];

        foreach ($permissions as $module => $slugs) {
            foreach ($slugs as $slug) {
                $display = match ($slug) {
                    'staff.view_reports' => 'View Staff Reports',
                    default => Str::of($slug)
                        ->after('.')
                        ->replace('_', ' ')
                        ->title()
                        ->toString(),
                };

                Permission::query()->updateOrCreate(
                    ['name' => $slug],
                    [
                        'display_name' => $display,
                        'module' => $module,
                        'description' => $display.' permission for '.strtoupper($module).' module',
                    ]
                );
            }
        }
    }
}
