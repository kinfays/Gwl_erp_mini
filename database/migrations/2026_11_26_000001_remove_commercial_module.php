<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the Commercial module (billing and reading imports, analytics, KPIs, customer list, SMS, route master, billing
 * details, cross-file, trends, exports, settings and reminders). Only its name stays, as an inert card on the Home screen,
 * which needs nothing from the database.
 *
 * The module's own create/seed migrations are left untouched (they have already run in other environments, and the project
 * never edits a merged migration); this one undoes what they made, so a fresh `migrate` still ends in a clean state.
 * Same shape as 2026_11_12_000001_drop_credit_union_module_tables.
 *
 * What goes:
 *  - every commercial_* table and its data (customers, contacts, uploads, SMS, settings and overrides, targets, reminder
 *    states), children before parents;
 *  - any commercial_* / ops_route_id column left on a table that is not a Commercial one (none is known; guarded);
 *  - the commercial.* permissions and their role grants, every module_access row for 'commercial', and the three roles that
 *    existed only for this module (with their user, permission and module rows);
 *  - in-app notices of the module, queued or failed Commercial jobs (their classes no longer exist), and the module's
 *    uploaded and exported workbooks on the private `local` disk (they hold customer personal data).
 *
 * audit_logs rows with module = 'commercial' are deliberately KEPT as the historical record (the audit screen reads the
 * module as free text), exactly as for the Credit Union.
 */
return new class extends Migration
{
    /**
     * SQLite ignores `PRAGMA foreign_keys` inside a transaction, so the foreign-key switch below only works when the
     * migration is not wrapped in one (MySQL never wraps DDL in a transaction anyway).
     */
    public $withinTransaction = false;

    /** Every table the Commercial migrations created, children before parents. Others found by name are added. */
    private const TABLES = [
        'commercial_sms_recipients',
        'commercial_sms_campaigns',
        'commercial_sms_templates',
        'commercial_billing_detail_transitions',
        'commercial_billing_detail_district_matrix',
        'commercial_billing_detail_domestic_bands',
        'commercial_billing_detail_accounts',
        'commercial_billing_detail_issues',
        'commercial_billing_detail_bands',
        'commercial_billing_detail_rollups',
        'commercial_reading_route_stats',
        'commercial_customer_consumption_history',
        'commercial_customer_connections',
        'commercial_customer_debt_stats',
        'commercial_customer_quality',
        'commercial_customer_consumption',
        'commercial_customer_issue_accounts',
        'commercial_customer_rollups_district',
        'commercial_customer_rollups',
        'commercial_customer_staging',
        'commercial_customer_diff',
        'commercial_customer_undo',
        'commercial_customer_changes',
        'commercial_customer_contacts',
        'commercial_customers',
        'commercial_customer_batches',
        'commercial_customer_uploads',
        'commercial_customer_categories',
        'commercial_customer_statuses',
        'commercial_meter_statuses',
        'commercial_billing_bands',
        'commercial_billing_routes',
        'commercial_reading_stats',
        'commercial_reading_strengths',
        'commercial_location_aliases',
        'commercial_district_targets',
        'commercial_reminder_states',
        'commercial_settings',
        'commercial_routes',
        'commercial_import_batches',
    ];

    private const ROLES = ['commercial_officer', 'commercial_manager', 'district_commercial_viewer'];

    /** Basenames of the deleted App\Jobs\Commercial\* classes, to clear them from the queue tables. */
    private const JOBS = [
        'ApplySmsDeliveryReport',
        'BuildCustomerListExport',
        'ProcessBillingDetailsBatch',
        'ProcessCustomerListBatch',
        'SendSmsCampaignChunk',
        'VoidCustomerListBatch',
        'VoidCustomerListUpload',
    ];

    public function up(): void
    {
        $this->dropTables();
        $this->dropLeftoverColumns();
        $this->removeAccess();
        $this->removeLeftovers();
    }

    private function dropTables(): void
    {
        $tables = collect(self::TABLES)
            ->merge($this->commercialTablesFound())
            ->unique()
            ->filter(fn (string $table) => Schema::hasTable($table))
            ->values()
            ->all();

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($this->childrenFirst($tables) as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * Unqualified names of the commercial_* tables in THIS database only (never another schema on the same server).
     *
     * @return array<int, string>
     */
    private function commercialTablesFound(): array
    {
        return collect(Schema::getTables($this->schema()))
            ->pluck('name')
            ->filter(fn (string $name) => str_starts_with($name, 'commercial_'))
            ->values()
            ->all();
    }

    private function schema(): ?string
    {
        $connection = Schema::getConnection();

        return match ($connection->getDriverName()) {
            'sqlite' => 'main',
            'mysql', 'mariadb' => $connection->getDatabaseName(),
            default => null,
        };
    }

    /**
     * A table is dropped only once no table still left references it, so no foreign key ever blocks a drop even where the
     * switch above has no effect. A cycle (none is known) falls back to the listed order.
     *
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function childrenFirst(array $tables): array
    {
        $referencedBy = [];

        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $parent = $foreignKey['foreign_table'];

                if ($parent !== $table && in_array($parent, $tables, true)) {
                    $referencedBy[$parent][$table] = true;
                }
            }
        }

        $left = $tables;
        $ordered = [];

        while ($left !== []) {
            $free = array_values(array_filter(
                $left,
                fn (string $table) => array_intersect(array_keys($referencedBy[$table] ?? []), $left) === []
            ));

            if ($free === []) {
                return array_merge($ordered, $left);
            }

            $ordered = array_merge($ordered, $free);
            $left = array_values(array_diff($left, $free));
        }

        return $ordered;
    }

    /** Columns the module may have put on tables that are not its own. None is known; this only makes sure. */
    private function dropLeftoverColumns(): void
    {
        foreach (Schema::getTables($this->schema()) as $table) {
            $name = $table['name'];

            if (str_starts_with($name, 'commercial_')) {
                continue;
            }

            $columns = array_values(array_filter(
                Schema::getColumnListing($name),
                fn (string $column) => $column === 'ops_route_id' || str_starts_with($column, 'commercial_')
            ));

            if ($columns !== []) {
                Schema::table($name, fn ($blueprint) => $blueprint->dropColumn($columns));
            }
        }
    }

    private function removeAccess(): void
    {
        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')
                ->where(fn ($query) => $query->where('module', 'commercial')->orWhere('name', 'like', 'commercial.%'))
                ->pluck('id');

            if ($permissionIds->isNotEmpty()) {
                if (Schema::hasTable('role_permissions')) {
                    DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
                }

                DB::table('permissions')->whereIn('id', $permissionIds)->delete();
            }
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
            DB::table('module_access')->where('module', 'commercial')->delete();
        }
    }

    private function removeLeftovers(): void
    {
        // GeneralDatabaseNotification stores {"title","message","url","module"}; the module's notices carry "commercial".
        if (Schema::hasTable('notifications')) {
            DB::table('notifications')
                ->where(fn ($query) => $query->where('data', 'like', '%"module":"commercial"%')->orWhere('data', 'like', '%"module": "commercial"%'))
                ->delete();
        }

        foreach (['jobs', 'failed_jobs'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'payload')) {
                DB::table($table)
                    ->where(function ($query) {
                        foreach (self::JOBS as $job) {
                            $query->orWhere('payload', 'like', '%'.$job.'%');
                        }
                    })
                    ->delete();
            }
        }

        // Uploaded workbooks (commercial/imports, billing-details, customer-imports) and generated exports
        // (commercial/customer-exports). Personal data of customers; nothing reads them any more.
        // Plus any customer-list download left behind at the disk root (commercial-export-*.xlsx).
        $disk = Storage::disk('local');
        $disk->deleteDirectory('commercial');
        $disk->delete(array_values(array_filter(
            $disk->files(),
            fn (string $file) => str_starts_with($file, 'commercial-export-')
        )));
    }

    /**
     * Intentionally irreversible: the data cannot be restored (the tables and files are gone, and so is the code that served
     * them). Recover from the database and storage backup taken before deploying if this was a mistake.
     */
    public function down(): void
    {
        //
    }
};
