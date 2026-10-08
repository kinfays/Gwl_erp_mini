<div>
    <x-ui.page-header title="Health &amp; Safety settings" description="The values the module measures against. A change applies everywhere at once and is recorded in the audit log.">
        <x-slot:actions>
            <button type="button" class="btn btn-secondary" wire:click="resetAll" wire:confirm="Put every Health &amp; Safety setting back to its default?">Reset everything to the defaults</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert tone="warning" class="dash-row">
        Most of these are <strong>placeholders</strong> until the EHS department confirms them, in particular the hydrostatic test interval. Defaults come from the application settings (.env); a value you save here overrides its default, and "Reset" returns to it.
    </x-ui.alert>

    <form wire:submit="save">
        @foreach ($groups as $group => $meta)
            <x-ui.card :title="$meta['title']" :description="$meta['description']" class="dash-row" wire:key="hs-group-{{ $group }}">
                <x-slot:actions>
                    @if ($meta['any_edited'])
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="resetGroup('{{ $group }}')">Reset {{ strtolower($meta['title']) }}</button>
                    @endif
                </x-slot:actions>

                @foreach ($meta['fields'] as $field)
                    <div class="form-row" style="align-items:flex-start;margin-bottom:12px" wire:key="hs-field-{{ $field['key'] }}">
                        <div class="form-field" style="flex:2">
                            <label class="form-label" for="s-{{ $field['key'] }}">
                                {{ $field['label'] }}
                                @if ($field['edited'])<x-ui.badge tone="primary">Edited</x-ui.badge>@endif
                            </label>
                            <span class="form-hint">{{ $field['help'] }}</span>
                            @if ($field['edited'])
                                <span class="form-hint">Default {{ $field['default_text'] }}; changed {{ $field['edited']->updated_at->format('d M Y H:i') }}@if ($field['edited']->updater) by {{ $field['edited']->updater->full_name }}@endif.</span>
                            @else
                                <span class="form-hint">Default {{ $field['default_text'] }}.</span>
                            @endif
                        </div>
                        <div class="form-field" style="flex:1">
                            @if ($field['type'] === 'bool')
                                <label style="display:flex;gap:8px;align-items:center">
                                    <input type="checkbox" id="s-{{ $field['key'] }}" wire:model="values.{{ $field['key'] }}">
                                    <span>On</span>
                                </label>
                            @elseif ($field['type'] === 'int')
                                <div style="display:flex;gap:6px;align-items:center">
                                    <input id="s-{{ $field['key'] }}" type="number" step="1" min="{{ $field['min'] }}" max="{{ $field['max'] }}" class="form-input" wire:model="values.{{ $field['key'] }}">
                                    @if ($field['unit'] !== '')<span class="ui-hint">{{ $field['unit'] }}</span>@endif
                                </div>
                            @elseif ($field['type'] === 'text')
                                <textarea id="s-{{ $field['key'] }}" rows="2" maxlength="{{ $field['max'] }}" class="form-input" wire:model="values.{{ $field['key'] }}"></textarea>
                            @else
                                <input id="s-{{ $field['key'] }}" type="text" inputmode="numeric" class="form-input" wire:model="values.{{ $field['key'] }}" placeholder="60, 30, 7, 0, -7">
                            @endif
                            @error('values.'.$field['key']) <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @endforeach
            </x-ui.card>
        @endforeach

        <div class="dash-row" style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Save settings</button>
        </div>
    </form>
</div>
