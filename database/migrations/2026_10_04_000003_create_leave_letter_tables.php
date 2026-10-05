<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave approval letters.
 *
 *  leave_letter_settings  the one company record: bankers, board of directors, registered office, telephone, website, e-mail
 *  leave_letterheads      one row per location: a region, or Head Office (region_id null: Head Office is a district, not
 *                         a region). The right-hand address block and the HR person who signs "for" the chief manager
 *  leave_letters          one per finally-approved request, with a frozen snapshot of everything printed on it
 *
 * Seeded from the company template (leave templetes.docx): the bankers, the board, the registered office, telephone, website and
 * e-mail, and the Accra West Region address block. Every other location starts with no address and is flagged "address not
 * set" on the Letter Settings page until Head Office or that region's HR fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_letter_settings')) {
            Schema::create('leave_letter_settings', function (Blueprint $table) {
                $table->id();
                $table->json('bankers')->nullable();
                $table->json('board_members')->nullable();
                $table->text('registered_office')->nullable();
                $table->string('telephone')->nullable();
                $table->string('website')->nullable();
                $table->string('email')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('leave_letterheads')) {
            Schema::create('leave_letterheads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('region_id')->nullable()->unique()->constrained()->cascadeOnDelete();
                $table->string('region_name');
                $table->json('address_lines')->nullable();
                $table->foreignId('hr_signatory_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('default_cc')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('leave_letters')) {
            Schema::create('leave_letters', function (Blueprint $table) {
                $table->id();
                $table->foreignId('leave_request_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('reference_no')->nullable();
                $table->timestamp('issued_at');
                $table->json('snapshot');
                $table->string('signatory_mode', 10); // self | for | acting
                $table->foreignId('signer_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('signature_authorized')->default(false);
                $table->foreignId('signature_id')->nullable()->constrained('user_signatures')->nullOnDelete();
                $table->unsignedInteger('printed_count')->default(0);
                $table->timestamp('first_printed_at')->nullable();
                $table->foreignId('last_printed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        $this->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_letters');
        Schema::dropIfExists('leave_letterheads');
        Schema::dropIfExists('leave_letter_settings');
    }

    private function seed(): void
    {
        $now = now();

        if (DB::table('leave_letter_settings')->count() === 0) {
            DB::table('leave_letter_settings')->insert([
                'bankers' => json_encode(['GCB Bank Limited', 'Societe Generale Ghana', 'National Investment Bank']),
                'board_members' => json_encode([
                    ['name' => 'Hon. Patrick Yaw Boamah', 'role' => 'Chairman'],
                    ['name' => 'Ing. Dr. Clifford A. Braimah', 'role' => 'Managing Director'],
                    ['name' => 'Mr. Noah Tumfo', 'role' => 'Member'],
                    ['name' => 'Mr. Michael Ayesu', 'role' => 'Member'],
                    ['name' => 'Hon. Akwasi Konadu', 'role' => 'Member'],
                    ['name' => 'Chief Kabachewura Ewuntomah Zakaria', 'role' => 'Member'],
                    ['name' => 'Hon. Kwame Amporfo Twumasi', 'role' => 'Member'],
                    ['name' => 'Surv. Prof. Forster Kum-Ankama Sarpong', 'role' => 'Member'],
                    ['name' => 'Mrs. Vida Duti', 'role' => 'Member'],
                    ['name' => 'Mr. Joseph Acolatse', 'role' => 'Member'],
                    ['name' => 'Ing. Dr. Hadisu Alhassan', 'role' => 'Member'],
                ]),
                'registered_office' => '28th February Road, (Near Independence Square)',
                'telephone' => '233-508-300-537',
                'website' => 'www.gwcl.com.gh',
                'email' => 'info@gwcl.com.gh',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Head Office first, then every region that exists today; later regions get theirs on first use.
        if (! DB::table('leave_letterheads')->whereNull('region_id')->exists()) {
            DB::table('leave_letterheads')->insert([
                'region_id' => null,
                'region_name' => 'Head Office',
                'address_lines' => json_encode([]),
                'default_cc' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (DB::table('regions')->get(['id', 'region_name']) as $region) {
            // The template's own letterhead is Accra West Region's.
            $accraWest = stripos($region->region_name, 'accra west') !== false;

            DB::table('leave_letterheads')->updateOrInsert(
                ['region_id' => $region->id],
                [
                    'region_name' => $accraWest ? 'Accra West Region' : $region->region_name,
                    'address_lines' => json_encode($accraWest ? ['Post Office Box DC 998', 'Dansoman, Accra - Ghana', 'West Africa'] : []),
                    'default_cc' => json_encode([]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
};
