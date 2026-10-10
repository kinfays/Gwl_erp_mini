<div>
    <x-ui.page-header title="SMS settings" description="Limits and defaults for SMS campaigns. Saved values replace the server defaults; saving the default again removes the override.">
        <x-slot:actions><a href="{{ route('commercial.sms') }}" class="btn btn-secondary">Back</a></x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="Set on the server (.env), shown here for information" class="dash-row">
        <ul class="ui-hint">
            <li>Region lock: <strong>{{ $regions }}</strong> (customers and staff of these regions only).</li>
            <li>Approved sender IDs: <strong>{{ $senders ?: 'none set' }}</strong></li>
            <li>Provider key: <strong>{{ $keySet ? 'set (never shown)' : 'NOT set' }}</strong> · delivery webhook token: <strong>{{ $webhook ? 'set' : 'not set (the webhook is off)' }}</strong></li>
        </ul>
    </x-ui.card>

    <x-ui.card title="Limits and defaults" class="dash-row">
        @foreach ($fields as $field)
            <div class="dash-row" wire:key="s-{{ $field['key'] }}">
                <label for="f-{{ $field['key'] }}"><strong>{{ $field['label'] }}</strong> @if ($field['edited'])<x-ui.badge tone="info">Edited</x-ui.badge>@endif</label>
                <div class="ui-toolbar">
                    <input id="f-{{ $field['key'] }}" type="number" step="{{ $field['type'] === 'float' ? '0.01' : '1' }}" min="{{ $field['min'] }}" max="{{ $field['max'] }}" wire:model="values.{{ $field['key'] }}" class="form-input" style="width: 9rem">
                    <span class="ui-hint">{{ $field['unit'] }} · server default {{ $field['default'] }}</span>
                </div>
                <span class="ui-hint">{{ $field['help'] }}</span>
                @error('values.'.$field['key'])<span class="form-error">{{ $message }}</span>@enderror
            </div>
        @endforeach
        <div class="dash-row">
            <button type="button" class="btn btn-primary" wire:click="save">Save</button>
            <button type="button" class="btn btn-ghost" wire:click="resetAll" wire:confirm="Put every SMS setting back to its server default?">Back to the defaults</button>
        </div>
    </x-ui.card>
</div>
