<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * District KPI targets are YEARLY (one figure per district, KPI and calendar year, spread evenly over the twelve months). The table
 * was first built for monthly targets that applied "from a month until a later row replaced them"; a row is now the target of the
 * year of its `effective_from`, which is kept as 1 January of that year. Existing rows are moved there: where one district and KPI
 * has several rows in a year, the latest one is kept (it was the one in force at the end of the year) and the earlier ones go.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commercial_district_targets')) {
            return;
        }

        $rows = DB::table('commercial_district_targets')->orderBy('effective_from')->orderBy('id')->get();
        $latest = [];   // district|metric|year => id of the row to keep

        foreach ($rows as $row) {
            $latest[$row->district_id.'|'.$row->metric.'|'.substr((string) $row->effective_from, 0, 4)] = $row->id;
        }

        $keep = array_values($latest);

        foreach ($rows as $row) {
            if (! in_array($row->id, $keep, true)) {
                DB::table('commercial_district_targets')->where('id', $row->id)->delete();
            }
        }

        foreach (DB::table('commercial_district_targets')->get() as $row) {
            $start = substr((string) $row->effective_from, 0, 4).'-01-01';

            if (substr((string) $row->effective_from, 0, 10) !== $start) {
                DB::table('commercial_district_targets')->where('id', $row->id)->update(['effective_from' => $start]);
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: a year's row is still a valid "from 1 January" row of the old monthly design.
    }
};
