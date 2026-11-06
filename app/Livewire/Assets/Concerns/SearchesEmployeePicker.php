<?php

namespace App\Livewire\Assets\Concerns;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server side of the "Assigned To" / "Actual holder" pickers (<x-form.searchable-select search="searchEmployees">):
 * at most 20 employees matched on name or staff ID. The pool is the one these forms always offered: everyone for
 * a user who may see every region, the actor's own region for a regional ICT user. Wildcards in the term are escaped.
 * Needs ScopesAssetsByActor.
 */
trait SearchesEmployeePicker
{
    /** Employees the actor may pick (the same pool the old dropdowns listed). */
    protected function employeePickerQuery(): Builder
    {
        return Employee::query()
            ->when($this->actorIsRegionScopedIct(), fn (Builder $query) => $query->where('region_id', $this->actorRegionId()));
    }

    /** @return array<int, array{value: int, label: string, description: string}> */
    protected function employeeSearchOptions(string $term): array
    {
        $term = trim(mb_substr($term, 0, 100));

        return $this->employeePickerQuery()
            ->when($term !== '', function (Builder $query) use ($term) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

                $query->where(function (Builder $inner) use ($like) {
                    $inner->whereRaw("full_name LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("staff_id LIKE ? ESCAPE '!'", [$like]);
                });
            })
            ->orderBy('full_name')
            ->limit(20)
            ->get(['id', 'full_name', 'staff_id'])
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => (string) $employee->full_name,
                'description' => 'Staff ID: '.$employee->staff_id,
            ])
            ->all();
    }

    /**
     * The name shown in the picker for an id that is already chosen. Deliberately not limited to the pool: an asset
     * assigned to someone outside it (an older record) must still show who holds it instead of looking empty.
     */
    protected function employeeLabel(int|string|null $employeeId): string
    {
        return $employeeId ? (string) Employee::query()->whereKey((int) $employeeId)->value('full_name') : '';
    }
}
