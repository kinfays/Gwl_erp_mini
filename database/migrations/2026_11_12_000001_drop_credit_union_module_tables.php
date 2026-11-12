<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the Credit Union module from the ERP. It was built behind
 * GWL_CREDIT_UNION_MODULE_ENABLED and is now deleted outright: the work continues as a
 * standalone Credit Union app, and "Claude outputs/credit-union-module-design-1.md" is kept
 * as its reference spec.
 *
 * The module's own create/seed migrations are left untouched (they have already run in other
 * environments); this one undoes what they made. audit_logs rows with module = 'credit_union'
 * are deliberately KEPT as the historical record: the audit screen reads the module as free
 * text and falls back to 'general', so nothing there needs the module to still exist.
 */
return new class extends Migration
{
    /**
     * Child first, so no foreign key ever blocks a drop and nothing has to be disabled
     * globally. credit_union_ledger_entries carries FKs to the deduction batches, withdrawal
     * requests and refunds, so it is dropped before all three.
     */
    private const TABLES = [
        'credit_union_interest_distribution_lines',
        'credit_union_interest_distributions',
        'credit_union_manual_receipts',
        'credit_union_ledger_entries',
        'credit_union_withdrawal_requests',
        'credit_union_refunds',
        'credit_union_loan_repayments',
        'credit_union_loan_guarantors',
        'credit_union_loans',
        'credit_union_deduction_batch_lines',
        'credit_union_deduction_batches',
        'credit_union_members',
    ];

    private const ROLES = ['credit_union_officer', 'credit_union_committee'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->where('module', 'credit_union')->pluck('id');

            if ($permissionIds->isNotEmpty() && Schema::hasTable('role_permissions')) {
                DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            }

            DB::table('permissions')->where('module', 'credit_union')->delete();
        }

        if (Schema::hasTable('roles')) {
            $roleIds = DB::table('roles')->whereIn('name', self::ROLES)->pluck('id');

            if ($roleIds->isNotEmpty()) {
                foreach (['user_roles', 'role_permissions', 'module_access'] as $table) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, 'role_id')) {
                        DB::table($table)->whereIn('role_id', $roleIds)->delete();
                    }
                }

                DB::table('roles')->whereIn('id', $roleIds)->delete();
            }
        }

        if (Schema::hasTable('module_access')) {
            DB::table('module_access')->where('module', 'credit_union')->delete();
        }
    }

    /**
     * Intentionally irreversible: the module's code is gone, so there would be nothing to
     * serve a restored schema. Recover from a database backup if this was a mistake.
     */
    public function down(): void
    {
        //
    }
};
