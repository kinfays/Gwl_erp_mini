<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Transport becomes region-scoped (docs/18-transport-region-scoping.md): a vehicle belongs to a place.
 *
 * `region_id` / `district_id` are copied from the person the vehicle is assigned to (the assigned user, else the
 * assigned driver), through their employee record. A vehicle nobody is assigned to, or whose person has no region, stays
 * NULL: only super_admin sees it until someone gives it a location on the vehicle form. Head Office is a district whose
 * staff share the Head Office district's region_id, so both columns are copied as they are and the "Head Office or
 * region" rule is applied at read time by TransportScope, never by region alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicles')) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table): void {
            if (! Schema::hasColumn('vehicles', 'region_id')) {
                $table->foreignId('region_id')->nullable()->after('department_id')->constrained('regions')->restrictOnDelete();
            }

            if (! Schema::hasColumn('vehicles', 'district_id')) {
                $table->foreignId('district_id')->nullable()->after('region_id')->constrained('districts')->restrictOnDelete();
            }
        });

        $placed = 0;

        DB::table('vehicles')
            ->whereNull('region_id')
            ->orderBy('id')
            ->select(['id', 'assigned_user_id', 'assigned_driver_id'])
            ->chunkById(200, function ($vehicles) use (&$placed): void {
                foreach ($vehicles as $vehicle) {
                    $location = $this->locationOf($vehicle->assigned_user_id) ?? $this->locationOf($vehicle->assigned_driver_id);

                    if ($location === null) {
                        continue;
                    }

                    DB::table('vehicles')->where('id', $vehicle->id)->update($location);
                    $placed++;
                }
            });

        $unplaced = DB::table('vehicles')->whereNull('region_id')->count();

        // Counts only: no plates, names or staff ids.
        Log::info('Transport vehicle location backfill finished.', ['placed' => $placed, 'unplaced' => $unplaced]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('vehicles')) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table): void {
            if (Schema::hasColumn('vehicles', 'district_id')) {
                $table->dropConstrainedForeignId('district_id');
            }

            if (Schema::hasColumn('vehicles', 'region_id')) {
                $table->dropConstrainedForeignId('region_id');
            }
        });
    }

    /** @return array{region_id: int, district_id: int|null}|null the person's employee location, or null when unknown */
    private function locationOf(?int $userId): ?array
    {
        if (! $userId) {
            return null;
        }

        $user = DB::table('users')->where('id', $userId)->first(['employee_id', 'staff_id']);

        if (! $user) {
            return null;
        }

        // Same resolution as User::employee ?? User::employeeByStaffId.
        $employee = $user->employee_id ? DB::table('employees')->where('id', $user->employee_id)->first(['region_id', 'district_id']) : null;
        $employee ??= $user->staff_id ? DB::table('employees')->where('staff_id', $user->staff_id)->first(['region_id', 'district_id']) : null;

        if (! $employee || ! $employee->region_id) {
            return null;
        }

        return [
            'region_id' => (int) $employee->region_id,
            'district_id' => $employee->district_id ? (int) $employee->district_id : null,
        ];
    }
};
