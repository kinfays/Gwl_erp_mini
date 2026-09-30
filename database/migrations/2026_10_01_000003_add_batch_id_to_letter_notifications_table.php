<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transmittal notifies its recipient once, not once per letter, so the notification points at the batch and has no
 * single letter: letter_id becomes nullable (its foreign key, cascade on delete, is kept) and batch_id is added.
 *
 * Engine note: the nullable change goes through Laravel's ->change(). SQLite (tests) rebuilds the table; MySQL/MariaDB
 * runs ALTER TABLE ... MODIFY on an unchanged unsigned bigint, which is allowed on a column that has a foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('letter_notifications')) {
            return;
        }

        if (Schema::hasColumn('letter_notifications', 'letter_id') && ! $this->letterIdIsNullable()) {
            Schema::table('letter_notifications', function (Blueprint $table) {
                $table->unsignedBigInteger('letter_id')->nullable()->change();
            });
        }

        if (Schema::hasTable('letter_dispatch_batches') && ! Schema::hasColumn('letter_notifications', 'batch_id')) {
            Schema::table('letter_notifications', function (Blueprint $table) {
                $table->foreignId('batch_id')
                    ->nullable()
                    ->after('letter_id')
                    ->constrained('letter_dispatch_batches')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('letter_notifications', 'batch_id')) {
            Schema::table('letter_notifications', function (Blueprint $table) {
                $table->dropConstrainedForeignId('batch_id');
            });
        }

        // letter_id stays nullable: making it NOT NULL again would fail once batch notifications exist.
    }

    private function letterIdIsNullable(): bool
    {
        $column = collect(Schema::getColumns('letter_notifications'))->firstWhere('name', 'letter_id');

        return (bool) ($column['nullable'] ?? false);
    }
};
