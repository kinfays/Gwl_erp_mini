<div>
    @php
        $show = fn ($value, array $field) => is_bool($value) ? ($value ? 'On' : 'Off') : rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.').($field['unit'] !== '' ? ' '.$field['unit'] : '');
    @endphp

    <x-ui.page-header title="Commercial settings" description="Targets and thresholds the Commercial screens measure against. A change applies everywhere at once and is recorded in the audit log.">
        <x-slot:actions>
            <button type="button" class="btn btn-secondary" wire:click="resetAll" wire:confirm="Put every Commercial setting back to its default?">Reset everything to the defaults</button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert tone="warning" class="dash-row">
        The targets and thresholds here are <strong>placeholders</strong> until the Commercial team confirms official ones. Defaults come from the application settings (.env); a value you save here overrides its default, and "Reset" returns to it.
    </x-ui.alert>

    <form wire:submit="save">
        @foreach ($groups as $group => $meta)
            <x-ui.card :title="$meta['title']" :description="$meta['description']" class="dash-row" wire:key="group-{{ $group }}">
                <x-slot:actions>
                    @if ($meta['any_edited'])
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="resetGroup('{{ $group }}')">Reset {{ strtolower($meta['title']) }}</button>
                    @endif
                </x-slot:actions>

                @foreach ($meta['fields'] as $field)
                    <div class="form-row" style="align-items:flex-start;margin-bottom:12px" wire:key="field-{{ $field['field'] }}">
                        <div class="form-field" style="flex:2">
                            <label class="form-label" for="s-{{ $field['field'] }}">
                                {{ $field['label'] }}
                                @if ($field['edited'])<x-ui.badge tone="primary">Edited</x-ui.badge>@endif
                            </label>
                            <span class="form-hint">{{ $field['help'] }}</span>
                            @if ($field['edited'])
                                <span class="form-hint">Default {{ $show($field['default'], $field) }}; changed {{ $field['edited']->updated_at->format('d M Y H:i') }}@if ($field['edited']->updater) by {{ $field['edited']->updater->full_name }}@endif.</span>
                            @else
                                <span class="form-hint">Default {{ $show($field['default'], $field) }}.</span>
                            @endif
                        </div>
                        <div class="form-field" style="flex:1">
                            @if ($field['type'] === 'bool')
                                <label style="display:flex;gap:8px;align-items:center">
                                    <input type="checkbox" id="s-{{ $field['field'] }}" wire:model="values.{{ $field['field'] }}">
                                    <span>{{ $field['label'] }}</span>
                                </label>
                            @else
                                <div style="display:flex;gap:6px;align-items:center">
                                    @if ($group === 'exceptions')
                                        {{-- Live: the line under this group updates as the value is typed. --}}
                                        <input id="s-{{ $field['field'] }}" type="number" step="{{ $field['type'] === 'int' ? '1' : 'any' }}" min="{{ $field['min'] }}" max="{{ $field['max'] }}" class="form-input" wire:model.live.debounce.400ms="values.{{ $field['field'] }}">
                                    @else
                                        <input id="s-{{ $field['field'] }}" type="number" step="{{ $field['type'] === 'int' ? '1' : 'any' }}" min="{{ $field['min'] }}" max="{{ $field['max'] }}" class="form-input" wire:model="values.{{ $field['field'] }}">
                                    @endif
                                    @if ($field['unit'] !== '')<span class="ui-hint">{{ $field['unit'] }}</span>@endif
                                </div>
                            @endif
                            @error('values.'.$field['field']) <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @endforeach

                @if ($group === 'exceptions')
                    <p class="ui-hint" data-exception-preview wire:key="exception-preview" style="margin-top:4px">
                        @if ($preview)
                            With these values <strong>{{ $preview['with'] }} of {{ $preview['total'] }}</strong> routes in the latest billing snapshot ({{ $preview['label'] }}) would be flagged (now: {{ $preview['now'] }}).
                            Nothing changes until you save.
                        @else
                            Upload a billing report to see how many routes these values would flag.
                        @endif
                    </p>
                @endif
            </x-ui.card>
        @endforeach

        <div class="dash-row" style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Save settings</button>
        </div>
    </form>
</div>
