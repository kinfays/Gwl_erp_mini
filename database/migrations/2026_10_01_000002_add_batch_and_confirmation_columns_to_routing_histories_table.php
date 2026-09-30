<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * routing_histories learns which transmittal a hop belongs to and when / by whom it was confirmed. received_confirm
 * stays (the list, the timeline and every existing query use it). resolution, resolved_at and resolution_note are
 * for recall/reject and are not written yet.
 *
 * Backfill: only the recipient could ever confirm a hop, and the row's updated_at is when they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('routing_histories')) {
            return;
        }

        if (Schema::hasTable('letter_dispatch_batches') && ! Schema::hasColumn('routing_histories', 'batch_id')) {
            Schema::table('routing_histories', function (Blueprint $table) {
                $table->foreignId('batch_id')
                    ->nullable()
                    ->after('to_secretariat_id')
                    ->constrained('letter_dispatch_batches')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('routing_histories', 'confirmed_at')) {
            Schema::table('routing_histories', function (Blueprint $table) {
                $table->timestamp('confirmed_at')->nullable()->after('received_confirm');
            });
        }

        if (! Schema::hasColumn('routing_histories', 'confirmed_by_id')) {
            Schema::table('routing_histories', function (Blueprint $table) {
                $table->foreignId('confirmed_by_id')
                    ->nullable()
                    ->after('confirmed_at')
                    ->constrained('employees')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('routing_histories', 'resolution')) {
            Schema::table('routing_histories', function (Blueprint $table) {
                $table->string('resolution', 20)->nullable()->after('confirmed_by_id'); // null | recalled | rejected
            });
        }

        if (! Schema::hasColumn('routing_histories', 'resolved_at')) {
            Schema::table('routing_histories', function (Blueprint $table) {
                $table->timestamp('resolved_at')->nullable()->after('resolution');
            });
        }

        if (! Schema::hasColumn('routing_histories', 'resolution_note')) {
            Schema::table('routing_histories', function (Blueprint $table) {
                $table->string('resolution_note', 500)->nullable()->after('resolved_at');
            });
        }

        DB::table('routing_histories')
            ->where('received_confirm', true)
            ->whereNull('confirmed_at')
            ->update([
                'confirmed_at' => DB::raw('updated_at'),
                'confirmed_by_id' => DB::raw('to_secretariat_id'),
            ]);
    }

    public function down(): void
    {
        foreach (['batch_id', 'confirmed_by_id'] as $foreignKey) {
            if (Schema::hasColumn('routing_histories', $foreignKey)) {
                Schema::table('routing_histories', function (Blueprint $table) use ($foreignKey) {
                    $table->dropConstrainedForeignId($foreignKey);
                });
            }
        }

        foreach (['confirmed_at', 'resolution', 'resolved_at', 'resolution_note'] as $column) {
            if (Schema::hasColumn('routing_histories', $column)) {
                Schema::table('routing_histories', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
