<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health & Safety, Phase 1: sites and incident reporting (docs/health-safety-module-design.md section 2.2).
 *
 * Nothing is ever deleted: incidents are cancelled, sites deactivated. Person and lookup references are restricted,
 * child rows cascade, and the optional link from an incident to a site is nulled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hs_sites')) {
            Schema::create('hs_sites', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                // head_office | regional_office | district_office | pay_point | depot | other
                $table->string('kind', 30);
                $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
                $table->string('address')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->unique(['region_id', 'kind', 'name']);
            });
        }

        if (! Schema::hasTable('hs_incidents')) {
            Schema::create('hs_incidents', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 40)->unique();

                // near_miss | injury | property_damage | environmental | incident | other
                $table->string('incident_type', 30);
                $table->string('other_type_text')->nullable();
                // Set at triage: low | medium | high | critical
                $table->string('severity', 20)->nullable();
                // regional_office | district_office | pay_point | field_work
                $table->string('context', 30);

                $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
                $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete();
                $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
                $table->foreignId('site_id')->nullable()->constrained('hs_sites')->nullOnDelete();
                // The pay point / place typed when "Other" was chosen; an officer links it to a site later.
                $table->string('site_name_raw')->nullable();
                $table->string('location_detail')->nullable();

                $table->date('occurred_on');
                $table->time('occurred_time')->nullable();
                $table->text('description');
                // yes | no | no_need
                $table->string('first_aid', 10);

                $table->string('witness_name')->nullable();
                $table->string('witness_contact')->nullable();
                $table->boolean('no_witness')->default(false);

                $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('reported_by_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
                // Recorded on behalf of someone with no login: who they are, in words.
                $table->string('reporter_name_raw')->nullable();
                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->boolean('is_confidential')->default(false);
                // The reporter's "this needs attention now" tick; with Injury / Environmental it triggers mail.
                $table->boolean('is_urgent')->default(false);

                // reported | acknowledged | investigating | pending_closure | closed | cancelled
                $table->string('status', 30)->default('reported')->index();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('acknowledged_at')->nullable();
                $table->foreignId('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();

                // human | equipment | process | environment | management
                $table->string('root_cause_category', 30)->nullable();
                // Internal: never shown to the reporter.
                $table->text('findings')->nullable();
                // Plain-language outcome: shown to the reporter.
                $table->text('closure_note')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('cancel_reason')->nullable();
                $table->unsignedSmallInteger('reopened_count')->default(0);

                $table->boolean('reportable_externally')->default(false);
                $table->string('external_ref')->nullable();
                $table->date('external_reported_on')->nullable();

                $table->timestamps();

                $table->index(['region_id', 'status']);
                $table->index(['district_id', 'occurred_on']);
                $table->index('reported_by_user_id');
                $table->index(['incident_type', 'occurred_on']);
            });
        }

        if (! Schema::hasTable('hs_incident_persons')) {
            Schema::create('hs_incident_persons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained('hs_incidents')->cascadeOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
                $table->string('name_raw')->nullable();
                // staff | contractor | visitor | public
                $table->string('person_type', 20)->default('staff');
                $table->string('injury_type')->nullable();
                $table->string('body_part')->nullable();
                // none | first_aid | clinic | hospital
                $table->string('treatment', 20)->default('none');
                $table->string('first_aider_name')->nullable();
                $table->unsignedSmallInteger('lost_time_days')->default(0);
                $table->date('returned_to_work_on')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hs_incident_actions')) {
            Schema::create('hs_incident_actions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained('hs_incidents')->cascadeOnDelete();
                $table->text('description');
                $table->foreignId('assigned_to_employee_id')->constrained('employees')->restrictOnDelete();
                $table->date('due_on');
                // open | done | verified
                $table->string('status', 20)->default('open')->index();
                $table->date('completed_on')->nullable();
                $table->text('completion_note')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hs_incident_attachments')) {
            Schema::create('hs_incident_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained('hs_incidents')->cascadeOnDelete();
                // On the private disk, never a public URL.
                $table->string('path');
                $table->string('original_name');
                $table->string('mime', 100)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hs_incident_status_logs')) {
            Schema::create('hs_incident_status_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained('hs_incidents')->cascadeOnDelete();
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30);
                $table->text('note')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_incident_status_logs');
        Schema::dropIfExists('hs_incident_attachments');
        Schema::dropIfExists('hs_incident_actions');
        Schema::dropIfExists('hs_incident_persons');
        Schema::dropIfExists('hs_incidents');
        Schema::dropIfExists('hs_sites');
    }
};
