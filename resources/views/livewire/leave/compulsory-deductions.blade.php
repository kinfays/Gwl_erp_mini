<div class="space-y-6">

    <div class="p-4 bg-amber-50 border border-amber-200 rounded">
        <strong>Warning:</strong> This action permanently deducts Annual leave from multiple employees.
    </div>

    <div class="bg-white p-6 border rounded-xl space-y-4">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="text-sm font-medium">Year</label>
                <input type="number" wire:model="year" class="w-full border rounded p-2">
            </div>

            <div>
                <label class="text-sm font-medium">Deduction Days</label>
                <input type="number" wire:model="deductionDays" class="w-full border rounded p-2">
            </div>

            <div>
                <label class="text-sm font-medium">Exclude Location Type</label>
                <select wire:model="excludeLocationType" class="w-full border rounded p-2">
                    <option value="">None</option>
                    <option value="HeadOffice">Head Office</option>
                    <option value="Region">Region</option>
                    <option value="District">District</option>
                </select>
            </div>
        </div>

        <div>
            <label class="text-sm font-medium">Employee Categories</label>
            <div class="flex flex-wrap gap-3 mt-1">
                @foreach($availableCategories as $c)
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model="categories" value="{{ $c }}">
                        {{ $c }}
                    </label>
                @endforeach
            </div>
        </div>

        <div class="p-3 bg-slate-50 border rounded text-sm">
            <strong>{{ $affectedCount }}</strong> employees will be affected.
        </div>

        <div>
            <label class="text-sm font-medium">Notes</label>
            <textarea wire:model="notes" class="w-full border rounded p-2" rows="3"></textarea>
        </div>

        @if($errors->has('confirmOverride'))
            <div class="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
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
            <button wire:click="apply"
                    class="px-6 py-2 bg-red-600 text-white rounded hover:bg-red-700">
                Apply Deduction
            </button>
        </div>
    </div>

</div>