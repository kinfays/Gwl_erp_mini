<div>
    <x-ui.page-header title="Settings" description="When stock counts as low and when a payment request counts as overdue for your location. Reminders use these values." />

    @include('livewire.gwater.partials.location-picker')

    @if ($location)
        <x-ui.card class="dash-row">
            <div class="ui-form-grid">
                @foreach ($settings as $key => $setting)
                    <div wire:key="setting-{{ $key }}">
                        <x-ui.input :label="$setting['label'].' ('.$setting['unit'].')'" type="number" step="1" inputmode="numeric" wire:model.defer="values.{{ $key }}"
                            :hint="$setting['help'].' Default: '.number_format($setting['default']).($setting['overridden'] ? '. Changed for your location.' : '.')" />
                        @if ($setting['overridden'])
                            <x-ui.button size="sm" variant="ghost" wire:click="resetToDefault('{{ $key }}')">Use the default</x-ui.button>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="ui-form-actions">
                <x-ui.button variant="primary" icon="check" wire:click="save" loading="save">Save settings</x-ui.button>
            </div>
        </x-ui.card>
    @endif
</div>
