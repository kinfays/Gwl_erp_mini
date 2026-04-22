//Warning Banner
<div class="warn-box">
    This action deducts days from Annual Leave balances for all matching staff.
    It cannot be undone without manual correction.
</div>

//form

<div class="pg">
    <div class="pg-head">
        <span class="pg-title">Apply deduction</span>
    </div>

    <div class="p-4">
        <input type="number" wire:model="year" class="form-input">

        <input type="number" wire:model="deductionDays"
               min="1" max="31" class="form-input">

        <select multiple wire:model="categories" class="form-input">
            <option value="Senior Staff">Senior Staff</option>
            <option value="Management">Management</option>
            <option value="Junior Staff">Junior Staff</option>
        </select>

        <select wire:model="excludeLocationType" class="form-input">
            <option value="">None</option>
            <option value="District">Exclude District</option>
            <option value="Region">Exclude Region</option>
        </select>

        <textarea wire:model="notes" class="form-input"></textarea>

        <div class="mt-2 text-sm">
            Estimated staff affected:
            <strong>{{ $this->affectedStaffCount }}</strong>
        </div>

        @if ($this->existingDeduction())
            <label class="mt-2 block">
                <input type="checkbox" wire:model="confirmOverride">
                Confirm override existing deduction
            </label>
        @endif

        <button wire:click="apply" class="btn btn-warn mt-3">
            Apply compulsory deduction
        </button>
    </div>
</div>

//History Table
<table>
    <thead>
        <tr>
            <th>Year</th>
            <th>Days</th>
            <th>Applied by</th>
            <th>Staff affected</th>
            <th>Date</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($history as $row)
            <tr>
                <td>{{ $row->year }}</td>
                <td>{{ $row->deduction_days }}</td>
                <td>{{ $row->appliedBy->full_name }}</td>
                <td>{{ count($row->applies_to_categories) }}</td>
                <td>{{ $row->applied_at?->toFormattedDateString() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
