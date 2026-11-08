<div>
    <x-ui.page-header :title="$title" :description="$blurb" />

    @include('livewire.finance.partials.location-picker')

    @if ($location)
        <x-ui.card title="When to remind" description="Reminders go out once a day at most (the scheduler must be running). Each is an in-app notice that carries only a count of vouchers and the longest wait, never a PV number, payee or amount." class="dash-row">
            <div class="ui-form-grid">
                @foreach ($rows as $key => $row)
                    @if ($row['type'] === 'bool')
                        <div class="span-2">
                            <x-ui.toggle :label="$row['label']" wire:model="values.{{ $key }}" :disabled="! $canManage"
                                :description="$row['help'].' Standard: '.($row['default'] ? 'on' : 'off').'.'.($row['overridden'] ? ' This location changed it.' : '')" />
                        </div>
                    @else
                        <x-ui.input :label="$row['label'].' (days)'" type="number" min="{{ $row['min'] }}" max="{{ $row['max'] }}" inputmode="numeric"
                            wire:model="values.{{ $key }}" :disabled="! $canManage"
                            :hint="$row['help'].' Standard: '.$row['default'].' days.'.($row['overridden'] ? ' This location changed it.' : '')" />
                    @endif
                @endforeach
            </div>
            @if ($canManage)
                <div class="ui-form-actions">
                    <x-ui.button variant="primary" icon="check" wire:click="save" loading="save">Save</x-ui.button>
                    <x-ui.button wire:click="resetToDefaults" wire:confirm="Go back to the standard values for this location?">Back to the standard values</x-ui.button>
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
