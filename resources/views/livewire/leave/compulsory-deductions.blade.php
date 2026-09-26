<div class="space-y-6">
    <div class="compulsory-alert">
        <strong>Compulsory leave window</strong>
        <span>Choose dates only between {{ \Carbon\Carbon::parse($rangeStartLimit)->format('d M Y') }} and {{ \Carbon\Carbon::parse($rangeEndLimit)->format('d M Y') }}.</span>
    </div>

    <div class="erp-card space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="text-sm font-medium">Leave Year</label>
                <input type="number" value="{{ $year }}" readonly class="w-full border rounded-sm p-2 bg-slate-100 text-slate-600 cursor-not-allowed">
                @error('year') <div class="text-xs text-red-700 mt-1">{{ $message }}</div> @enderror
            </div>

            <div>
                <label class="text-sm font-medium">Start Date</label>
                <input type="date" wire:model.live="startDate" min="{{ $rangeStartLimit }}" max="{{ $rangeEndLimit }}" class="w-full border rounded-sm p-2">
                @error('startDate') <div class="text-xs text-red-700 mt-1">{{ $message }}</div> @enderror
            </div>

            <div>
                <label class="text-sm font-medium">End Date</label>
                <input type="date" wire:model.live="endDate" min="{{ $rangeStartLimit }}" max="{{ $rangeEndLimit }}" class="w-full border rounded-sm p-2">
                @error('endDate') <div class="text-xs text-red-700 mt-1">{{ $message }}</div> @enderror
            </div>

            <div class="compulsory-days">
                <span>Deduction Days</span>
                <strong>{{ $deductionDays }}</strong>
                <small>Calculated working days</small>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-medium">Exclude Location Type</label>
                <select wire:model.live="excludeLocationType" class="w-full border rounded-sm p-2">
                    <option value="">None</option>
                    <option value="HeadOffice">Head Office</option>
                    <option value="Region">Region</option>
                    <option value="District">District</option>
                </select>
            </div>

            <div>
                <label class="text-sm font-medium">Employee Categories</label>
                <div class="flex flex-wrap gap-3 mt-2">
                    @foreach($availableCategories as $category)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model.live="categories" value="{{ $category }}">
                            {{ $category }}
                        </label>
                    @endforeach
                </div>
                @error('categories') <div class="text-xs text-red-700 mt-1">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="p-3 bg-slate-50 border rounded-sm text-sm dark:bg-slate-900 dark:border-slate-700">
            <strong>{{ $affectedCount }}</strong> employees will be affected. Annual leave will be deducted for the selected year.
        </div>

        <div>
            <label class="text-sm font-medium">Notes</label>
            <textarea wire:model="notes" class="w-full border rounded-sm p-2" rows="3"></textarea>
        </div>

        @if($errors->has('confirmOverride'))
            <div class="p-3 bg-red-50 border border-red-200 rounded-sm text-sm text-red-700">
                {{ $errors->first('confirmOverride') }}
                <div class="mt-2">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model="confirmOverride">
                        Confirm override existing deduction
                    </label>
                </div>
            </div>
        @endif

        <div>
            <button
                type="button"
                class="btn btn-danger"
                x-data
                x-on:click.prevent="$dispatch('confirm-action', {
                    title: 'Apply compulsory deduction?',
                    message: 'Annual leave balances for affected employees will be reduced by the selected working days.',
                    confirmLabel: 'Apply Deduction',
                    variant: 'danger',
                    action: () => $wire.apply()
                })"
            >
                Apply Deduction
            </button>
        </div>
    </div>
</div>
