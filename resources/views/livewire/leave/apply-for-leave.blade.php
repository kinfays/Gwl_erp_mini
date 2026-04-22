<div>
    <div class="form-row">
        <div class="form-field">
            <label class="form-label">Requesting for</label>
            <select wire:model="requesterId" class="form-input">
                @foreach ($employees as $emp)
                    <option value="{{ $emp->id }}">
                        {{ $emp->full_name }}
                        @if ($emp->id === auth()->user()->employee->id)
                            (yourself)
                        @endif
                    </option>
                @endforeach
            </select>
        </div>

        <div class="form-field">
            <label class="form-label">Leave type</label>
            <select wire:model="leaveType" class="form-input">
                <option value="">Select leave type</option>
                @foreach ($leaveTypes as $type)
                    <option value="{{ $type->value }}">{{ $type->value }}</option>
                @endforeach
            </select>
            @error('leaveType') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
        </div>
    </div>

    <div class="form-row">
        <input type="date" wire:model="startDate" class="form-input">
        <input type="date" wire:model="endDate" class="form-input">
    </div>

    <div class="day-counter">
        <div>
            <div class="day-label">Working days applied for</div>
        </div>
        <div class="day-num">{{ $workingDays }}</div>
    </div>

    <textarea wire:model="reason" class="form-input"></textarea>

    <input type="file" wire:model="attachment">

    <div class="flex justify-end gap-2">
        <button wire:click="saveAsPlanned" class="btn">Save as planned</button>
        <button wire:click="submit" class="btn btn-primary">Submit request</button>
    </div>
</div>