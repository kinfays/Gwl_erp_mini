<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The serial-number prefix of a region becomes data (regions.letter_prefix, unique) instead of being re-derived from
 * the region's name on every letter, where two one-word regions such as Ashanti and Ahafo both came out as "A".
 *
 * Backfill: every region without a prefix gets what the old initials rule produced, in id order; on a clash the
 * region id is appended, so the first region keeps the plain initials. Serial numbers already issued are not touched.
 * Re-running only fills regions that still have no prefix.
 */
return new class extends Migration
{
    private const WIDTH = 10;

    public function up(): void
    {
        if (! Schema::hasTable('regions')) {
            return;
        }

        if (! Schema::hasColumn('regions', 'letter_prefix')) {
            Schema::table('regions', function (Blueprint $table) {
                $table->string('letter_prefix', self::WIDTH)->nullable()->after('region_name');
            });
        }

        $this->backfill();

        if (! Schema::hasIndex('regions', 'regions_letter_prefix_unique')) {
            Schema::table('regions', function (Blueprint $table) {
                $table->unique('letter_prefix');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('regions', 'regions_letter_prefix_unique')) {
            Schema::table('regions', function (Blueprint $table) {
                $table->dropUnique('regions_letter_prefix_unique');
            });
        }

        if (Schema::hasColumn('regions', 'letter_prefix')) {
            Schema::table('regions', function (Blueprint $table) {
                $table->dropColumn('letter_prefix');
            });
        }
    }

    private function backfill(): void
    {
        $taken = DB::table('regions')->whereNotNull('letter_prefix')->pluck('letter_prefix')->flip()->all();

        foreach (DB::table('regions')->whereNull('letter_prefix')->orderBy('id')->get(['id', 'region_name']) as $region) {
            $prefix = $this->unique($this->initials((string) $region->region_name), (int) $region->id, $taken);

            $taken[$prefix] = true;
            DB::table('regions')->where('id', $region->id)->update(['letter_prefix' => $prefix]);
        }
    }

    /** The rule LetterWorkflowService used: the first letter of each word, upper-cased; "REG" when there are none. */
    private function initials(string $name): string
    {
        $initials = '';

        foreach (preg_split('/\s+/', trim($name)) ?: [] as $word) {
            if ($word !== '') {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            }
        }

        return mb_substr($initials !== '' ? $initials : 'REG', 0, self::WIDTH);
    }

    /** @param  array<string, true>  $taken */
    private function unique(string $initials, int $regionId, array $taken): string
    {
        if (! isset($taken[$initials])) {
            return $initials;
        }

        $withId = mb_substr($initials, 0, self::WIDTH - strlen((string) $regionId)).$regionId;

        if (! isset($taken[$withId])) {
            return $withId;
        }

        // Only reachable when an id-suffixed prefix was already taken by another region: still deterministic.
        for ($n = 1;; $n++) {
            $candidate = mb_substr('R'.$regionId, 0, self::WIDTH - strlen((string) $n) - 1).'N'.$n;

            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }
    }
};
