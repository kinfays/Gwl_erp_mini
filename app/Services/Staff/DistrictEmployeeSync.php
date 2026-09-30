<?php

namespace App\Services\Staff;

use App\Models\District;
use App\Models\Employee;

/**
 * Keeps employees in step with their district after the district is edited.
 *
 * An employee's location_type is derived from the district's NAME and their region_id is copied from the district,
 * both when the employee is saved. Editing the district on the locations screen therefore left its employees stale
 * until each was next saved: rename "Head Office" and its staff stayed HeadOffice (and kept the Head Office-only
 * roles), or move a district to another region and its staff stayed in the old one.
 *
 * Each employee is re-saved through Eloquent, so the same code path as any other move runs: location_type is
 * re-derived, and EmployeeObserver removes the Head Office-only roles (Global Admin, Head Office HR, Chief Manager)
 * from anyone who is no longer at Head Office, audited and announced. Call it inside the caller's transaction.
 */
class DistrictEmployeeSync
{
    /** @return int how many employees actually changed */
    public function sync(District $district): int
    {
        $changed = 0;

        Employee::query()
            ->where('district_id', $district->id)
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($district, &$changed) {
                foreach ($employees as $employee) {
                    // The edited district, not whatever the relation cached: Employee::saving reads its name.
                    $employee->setRelation('district', $district);
                    $employee->region_id = $district->region_id;
                    $employee->save();

                    if ($employee->wasChanged()) {
                        $changed++;
                    }
                }
            });

        return $changed;
    }
}
