<?php

namespace App\Livewire\Assets\Concerns;

use App\Models\Employee;

/**
 * Picking "Assigned To" on the Assets and Phones forms suggests the person's district as the Location. It is a default,
 * never a lock: the person can change the Location afterwards, and nothing runs on page load or when an existing asset
 * is opened (updatedForm() only fires on an explicit change).
 *
 *  - Every explicit pick of an employee re-suggests THEIR district (the simple rule: no "did they edit it?" tracking).
 *  - Clearing Assigned To leaves the Location alone.
 *  - A district outside the actor's own region (the only districts the Location list offers and the save accepts) is
 *    NOT put in the field: the Location stays as it was and a small hint says where the employee is based.
 *
 * Only a form default. The assignment itself is AssetAssignmentService's, and save() still validates the final
 * Location against the actor's region (actorRegionDistrictRule()), whatever the form sent. Needs $form and
 * ScopesAssetsByActor.
 */
trait PrefillsLocationFromAssignee
{
    /** Shown under Location when the picked employee is based outside the actor's region. */
    public ?string $locationHint = null;

    protected function prefillLocationFromAssignee(mixed $employeeId): void
    {
        $this->locationHint = null;

        if (blank($employeeId)) {
            return;
        }

        $employee = Employee::query()->with('district')->find((int) $employeeId, ['id', 'district_id', 'full_name']);

        if (! $employee || ! $employee->district_id) {
            return;
        }

        if ($this->actorRegionDistricts()->contains('id', $employee->district_id)) {
            $this->form['district_id'] = $employee->district_id;

            return;
        }

        $this->locationHint = 'This person is based in '.($employee->district?->district_name ?? 'another location').', outside your region. The location was left as it is.';
    }
}
