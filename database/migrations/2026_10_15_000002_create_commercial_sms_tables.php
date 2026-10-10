<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 (SMS to customers). THESE TABLES NEVER HOLD A PHONE NUMBER OR A MESSAGE WITH CUSTOMER DATA IN IT: a recipient is a
 * customer id plus a status; the number is read from the customer's contact row at send time, in memory. The campaign stores
 * the message TEMPLATE ("Dear {name}, you owe {balance}"), never the merged text.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_sms_templates')) {
            Schema::create('commercial_sms_templates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
                $table->string('name', 80);
                $table->text('body');                                   // merge fields only: {name} {account_no} {balance}; no customer data
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['region_id', 'name']);
            });
        }

        if (! Schema::hasTable('commercial_sms_campaigns')) {
            Schema::create('commercial_sms_campaigns', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 120)->nullable();
                // draft, scheduled, queued, running, cancelling, completed, cancelled, failed, blocked
                $table->string('status', 16)->default('queued');
                $table->string('audience_type', 12);                    // all | selected | group
                $table->json('audience');                               // the rules (group key, filters, selected customer ids): no numbers
                $table->boolean('include_second_number')->default(false);
                $table->boolean('include_missing')->default(false);     // accounts no longer in the latest file
                $table->string('sender_id', 20);
                $table->foreignId('template_id')->nullable()->constrained('commercial_sms_templates')->nullOnDelete();
                $table->text('body');                                   // the TEMPLATE
                $table->boolean('personalised')->default(false);        // uses merge fields: one request per recipient
                $table->string('encoding', 8)->default('gsm7');         // gsm7 | ucs2
                $table->unsignedTinyInteger('segments')->default(1);
                $table->unsignedInteger('accounts_total')->default(0);  // accounts matched (more than recipients when numbers are shared)
                $table->unsignedInteger('recipients_total')->default(0);
                $table->unsignedInteger('credits_estimated')->default(0);
                $table->unsignedInteger('credits_used')->default(0);
                $table->string('phase', 16)->default('new');            // new | materialising | sending | done
                $table->unsignedBigInteger('cursor')->default(0);       // last recipient id handled (resume point)
                $table->boolean('cancel_requested')->default(false);
                $table->string('error_message', 255)->nullable();       // sanitised: never a number
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->index(['region_id', 'status']);
                $table->index(['status', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('commercial_sms_recipients')) {
            Schema::create('commercial_sms_recipients', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('campaign_id')->constrained('commercial_sms_campaigns')->cascadeOnDelete();
                $table->unsignedBigInteger('customer_id');              // no foreign key: the customers table has millions of rows
                $table->unsignedTinyInteger('slot')->default(1);        // 1 = the customer's first number, 2 = the second
                // pending -> claimed -> submitted -> delivered | failed ; unknown = we cannot tell whether the provider took it
                $table->string('status', 12)->default('pending');
                $table->string('provider_message_id', 64)->nullable();
                $table->string('error_code', 32)->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('delivered_at')->nullable();

                $table->unique(['campaign_id', 'customer_id', 'slot']);
                $table->index(['campaign_id', 'status', 'id']);
                $table->index('provider_message_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['commercial_sms_recipients', 'commercial_sms_campaigns', 'commercial_sms_templates'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
