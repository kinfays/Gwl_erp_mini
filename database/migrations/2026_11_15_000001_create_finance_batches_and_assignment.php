<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance and Audit, change request 2, step F (docs/20-module-finance-audit.md): BATCHES and ASSIGNMENT BY THE AUDIT MANAGER.
 *
 *  - fin_pv_batches: a CONTAINER for sending, assigning, tracking and printing a group of vouchers. It is not a unit of approval
 *    (Audit still ticks or queries each voucher on its own) and it has NO stored progress: its state is computed, in one place
 *    (FinPvBatch::computeState).
 *  - fin_batch_counters: the per-location, per-year counter behind batch_no (read with a write lock by BatchWorkflowService).
 *  - fin_batch_events: the batch's immutable trail (a model hook refuses update/delete).
 *  - fin_pvs gains batch_id (a voucher is in at most one batch) and who it is assigned to / by / when.
 *  - fin_pv_events gains batch_id (a batch send), from_user_id and to_user_id (an assignment); actions `assigned` and `reassigned`
 *    are new values of the existing action column.
 *  - the permission audit.assign (only audit_manager and super_admin).
 *
 * EXISTING DATA: every voucher that was already sent to Audit when this runs has NO officer, so it shows as "Awaiting assignment"
 * and is invisible to Audit officers until the Audit manager assigns it (an officer still sees a voucher they have ticked, queried or
 * withdrawn a tick on). Nothing is assigned automatically. Idempotent; Database\Seeders\FinanceAuditRolePermissionSeeder repeats
 * the grants for fresh installs and tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_pv_batches')) {
            Schema::create('fin_pv_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_no', 40)->unique();
                $table->string('title', 150)->nullable();
                $table->text('remarks')->nullable();
                // The batch's location: Head Office (its district) OR a region without it, copied from its creator.
                $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
                $table->string('status', 12)->index(); // draft | sent | cancelled
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('sent_at')->nullable();
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('cancel_reason')->nullable();
                $table->unsignedInteger('lock_version')->default(1);
                $table->timestamps();

                $table->index(['region_id', 'district_id', 'status']);
            });
        }

        if (! Schema::hasTable('fin_batch_counters')) {
            Schema::create('fin_batch_counters', function (Blueprint $table) {
                $table->id();
                $table->string('unit', 12); // ho | r<region id>, the same key as the books version
                $table->unsignedSmallInteger('year');
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamps();

                $table->unique(['unit', 'year']);
            });
        }

        if (! Schema::hasTable('fin_batch_events')) {
            Schema::create('fin_batch_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('fin_pv_batches')->restrictOnDelete();
                $table->string('action', 20); // created | pv_added | pv_removed | sent | assigned | reassigned | cancelled
                $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('pv_id')->nullable()->constrained('fin_pvs')->restrictOnDelete();
                $table->foreignId('from_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('to_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('comment')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['batch_id', 'id']);
            });
        }

        if (! Schema::hasColumn('fin_pvs', 'batch_id')) {
            Schema::table('fin_pvs', function (Blueprint $table) {
                $table->foreignId('batch_id')->nullable()->constrained('fin_pv_batches')->restrictOnDelete();
                $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
            });
        }

        if (! Schema::hasColumn('fin_pv_events', 'batch_id')) {
            Schema::table('fin_pv_events', function (Blueprint $table) {
                $table->foreignId('batch_id')->nullable()->constrained('fin_pv_batches')->restrictOnDelete();
                $table->foreignId('from_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            });
        }

        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'audit.assign'],
            ['display_name' => 'Assign PVs', 'module' => 'audit', 'description' => 'Assign PVs permission for AUDIT module', 'created_at' => $now, 'updated_at' => $now]
        );

        $permissionId = DB::table('permissions')->where('name', 'audit.assign')->value('id');

        foreach (['audit_manager', 'super_admin'] as $role) {
            $roleId = DB::table('roles')->where('name', $role)->value('id');

            if ($roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'audit.assign')->value('id');

        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        if (Schema::hasColumn('fin_pv_events', 'batch_id')) {
            Schema::table('fin_pv_events', function (Blueprint $table) {
                $table->dropConstrainedForeignId('batch_id');
                $table->dropConstrainedForeignId('from_user_id');
                $table->dropConstrainedForeignId('to_user_id');
            });
        }

        if (Schema::hasColumn('fin_pvs', 'batch_id')) {
            Schema::table('fin_pvs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('batch_id');
                $table->dropConstrainedForeignId('assigned_to_user_id');
                $table->dropConstrainedForeignId('assigned_by_user_id');
                $table->dropColumn('assigned_at');
            });
        }

        foreach (['fin_batch_events', 'fin_batch_counters', 'fin_pv_batches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
