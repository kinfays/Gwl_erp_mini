<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per serial-number prefix and year holding the last number issued, so the next one is a locked increment
 * rather than "the highest string we can find + 1" (which sorted 999 above 1000 and could not be made safe against two
 * secretaries saving at once). Keyed on the prefix, not the region: regions that ever share a prefix share a counter.
 *
 * Seeded from the letters already issued by parsing the numeric suffix of each sn_number in PHP (no SQL string
 * functions, so it behaves the same on SQLite and MySQL). It never lowers an existing counter, so it is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('letter_sn_counters')) {
            Schema::create('letter_sn_counters', function (Blueprint $table) {
                $table->id();
                $table->string('prefix', 10);
                $table->unsignedSmallInteger('year');
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamps();

                $table->unique(['prefix', 'year']);
            });
        }

        $this->seedFromIssuedLetters();
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_sn_counters');
    }

    private function seedFromIssuedLetters(): void
    {
        if (! Schema::hasTable('mail_letters')) {
            return;
        }

        /** @var array<string, array{prefix: string, year: int, max: int}> $highest */
        $highest = [];

        DB::table('mail_letters')->select(['id', 'sn_number'])->orderBy('id')->chunkById(500, function ($letters) use (&$highest) {
            foreach ($letters as $letter) {
                // PREFIX-YYYY-NNN; the prefix itself may contain hyphens, the last two parts never do.
                if (! preg_match('/^(.+)-(\d{4})-(\d+)$/', (string) $letter->sn_number, $m) || strlen($m[1]) > 10) {
                    continue;
                }

                $key = $m[1].'|'.$m[2];
                $number = (int) $m[3];

                if (! isset($highest[$key]) || $number > $highest[$key]['max']) {
                    $highest[$key] = ['prefix' => $m[1], 'year' => (int) $m[2], 'max' => $number];
                }
            }
        });

        $now = now();

        foreach ($highest as $row) {
            $existing = DB::table('letter_sn_counters')->where('prefix', $row['prefix'])->where('year', $row['year'])->first();

            if (! $existing) {
                DB::table('letter_sn_counters')->insert([
                    'prefix' => $row['prefix'],
                    'year' => $row['year'],
                    'last_number' => $row['max'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } elseif ($existing->last_number < $row['max']) {
                DB::table('letter_sn_counters')->where('id', $existing->id)->update(['last_number' => $row['max'], 'updated_at' => $now]);
            }
        }
    }
};
