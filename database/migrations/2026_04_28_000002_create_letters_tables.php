<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_letters', function (Blueprint $table) {
            $table->id();
            $table->string('sn_number')->unique();
            $table->string('subject');
            $table->string('ref_no')->nullable();
            $table->enum('type', ['Internal', 'External']);
            $table->foreignId('memo_sender_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('company_sender')->nullable();
            $table->date('date_on_letter');
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('employees')->restrictOnDelete();
            $table->timestamps();

            $table->index(['region_id', 'created_at']);
            $table->index(['type', 'date_on_letter']);
        });

        Schema::create('letter_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained('mail_letters')->cascadeOnDelete();
            $table->foreignId('secretariat_id')->constrained('employees')->restrictOnDelete();
            $table->enum('status', ['Received', 'In Review', 'Dispatched', 'Closed'])->default('Received');
            $table->boolean('is_closed')->default(false);
            $table->date('out_date')->nullable();
            $table->timestamps();

            $table->index(['secretariat_id', 'status', 'is_closed']);
            $table->index(['letter_id', 'secretariat_id']);
        });

        Schema::create('routing_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained('mail_letters')->cascadeOnDelete();
            $table->foreignId('from_secretariat_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('to_secretariat_id')->constrained('employees')->restrictOnDelete();
            $table->boolean('received_confirm')->default(false);
            $table->timestamps();

            $table->index(['to_secretariat_id', 'received_confirm']);
            $table->index(['letter_id', 'created_at']);
        });

        Schema::create('letter_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained('mail_letters')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('remark_secretariat_id')->constrained('employees')->restrictOnDelete();
            $table->text('remark_content');
            $table->foreignId('created_by_id')->constrained('employees')->restrictOnDelete();
            $table->timestamps();

            $table->index(['letter_id', 'created_at']);
        });

        Schema::create('letter_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->foreignId('secretariat_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('letter_id')->constrained('mail_letters')->cascadeOnDelete();
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['secretariat_id', 'is_read', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_notifications');
        Schema::dropIfExists('letter_remarks');
        Schema::dropIfExists('routing_histories');
        Schema::dropIfExists('letter_status_logs');
        Schema::dropIfExists('mail_letters');
    }
};
