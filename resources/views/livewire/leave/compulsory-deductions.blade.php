<div>
    <x-ui.page-header title="Compulsory Leave" description="Deduct annual leave for everyone in the selected categories over the closure period." />

    <x-ui.alert tone="warning" title="Compulsory leave window">
        Choose dates only between {{ \Carbon\Carbon::parse($rangeStartLimit)->format('d M Y') }} and {{ \Carbon\Carbon::parse($rangeEndLimit)->format('d M Y') }}.
    </x-ui.alert>

    <x-ui.card title="Deduction">
        <div class="ui-form-grid compulsory-grid">
            <x-ui.field label="Leave Year" for="compulsory-year" error="year">
                <input type="number" id="compulsory-year" value="{{ $year }}" readonly class="form-input ui-input">
            </x-ui.field>

            <div class="compulsory-days" aria-live="polite">
                <span>Deduction Days</span>
                <strong>{{ $deductionDays }}</strong>
                <small>Calculated working days</small>
            </div>

            <x-ui.input type="date" label="Start Date" wire:model.live="startDate" min="{{ $rangeStartLimit }}" max="{{ $rangeEndLimit }}" id="compulsory-start" />
            <x-ui.input type="date" label="End Date" wire:model.live="endDate" min="{{ $rangeStartLimit }}" max="{{ $rangeEndLimit }}" id="compulsory-end" />

            <x-ui.select label="Exclude Location Type" wire:model.live="excludeLocationType" id="compulsory-exclude">
                <option value="">None</option>
                <option value="HeadOffice">Head Office</option>
                <option value="Region">Region</option>
                <option value="District">District</option>
            </x-ui.select>

            <fieldset class="ui-field span-2 compulsory-categories">
                <legend class="ui-label">Employee Categories</legend>
                <div class="check-grid">
                    @foreach ($availableCategories as $category)
                        <x-ui.checkbox :label="$category" :id="'category-'.\Illuminate\Support\Str::slug($category)" wire:model.live="categories" value="{{ $category }}" />
                    @endforeach
                </div>
                @error('categories')
                    <p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>
                @enderror
            </fieldset>
        </div>

        <x-ui.alert tone="info" class="compulsory-affected" role="status">
            <strong>{{ $affectedCount }}</strong> employees will be affected. Annual leave will be deducted for the selected year.
        </x-ui.alert>

        <x-ui.textarea label="Notes" wire:model="notes" rows="3" id="compulsory-notes" />

        @if ($errors->has('confirmOverride'))
            <x-ui.alert tone="danger" role="alert" class="compulsory-override">
                {{ $errors->first('confirmOverride') }}
                <div class="compulsory-override-check">
                    <x-ui.checkbox label="Confirm override existing deduction" id="compulsory-override" wire:model="confirmOverride" />
                </div>
            </x-ui.alert>
        @endif

        <x-slot:footer>
            <div class="ui-form-actions">
                <button
                    type="button"
                    class="btn btn-danger-solid"
                    x-data
                    x-on:click.prevent="$dispatch('confirm-action', {
                        title: 'Apply compulsory deduction?',
                        message: 'Annual leave balances for affected employees will be reduced by the selected working days.',
                        confirmLabel: 'Apply Deduction',
                        variant: 'danger',
                        action: () => $wire.apply()
                    })"
                >
                    <x-ui.icon name="calendar-x" />
                    Apply Deduction
                </button>
            </div>
        </x-slot:footer>
    </x-ui.card>
</div>
