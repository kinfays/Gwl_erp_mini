<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_remarks', function (Blueprint $table) {
            $table->foreignId('manager_id')
                ->nullable()
                ->after('remark_secretariat_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('chief_manager_id')
                ->nullable()
                ->after('manager_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->text('secretary_remark_content')
                ->nullable()
                ->after('remark_content');
        });
    }

    public function down(): void
    {
        Schema::table('letter_remarks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chief_manager_id');
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn('secretary_remark_content');
        });
    }
};
