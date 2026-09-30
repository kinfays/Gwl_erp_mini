{{--
    Recipient picker shared by the single dispatch tab and the dispatch-selected drawer.
    In: $picker (LetterWorkflowService::recipientPicker()), $workflow, and the names of the three Livewire properties it
    drives: $toModel (the chosen employee id), $searchModel, $scopeModel, plus $scopeValue (current chip).
    "Return to previous holder" and recent recipients are pinned on top; the rest is grouped Secretaries / Managers with
    "Name · Department · Location" labels. The service re-checks whoever is chosen.
--}}
@php
    $scopes = ['mine' => 'My location', 'head_office' => 'Head Office', 'any' => 'Any'];
    $hasOptions = $picker['previous'] || $picker['recent']->isNotEmpty() || $picker['secretaries']->isNotEmpty() || $picker['managers']->isNotEmpty();
@endphp

<div class="ui-stack recipient-picker">
    @if ($picker['previous'] || $picker['recent']->isNotEmpty())
        <div class="recipient-pins" aria-label="Quick picks">
            @if ($picker['previous'])
                <button type="button" class="btn btn-sm" wire:click="$set('{{ $toModel }}', {{ $picker['previous']->id }})" title="{{ $workflow->recipientLabel($picker['previous']) }}">
                    <x-ui.icon name="undo-2" class="icon-sm" />
                    Return to previous holder: {{ $picker['previous']->full_name }}
                </button>
            @endif
            @foreach ($picker['recent'] as $recent)
                <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('{{ $toModel }}', {{ $recent->id }})" title="{{ $workflow->recipientLabel($recent) }}">
                    {{ $recent->full_name }}
                </button>
            @endforeach
        </div>
    @endif

    <div class="tabs" role="group" aria-label="Where to look for a recipient">
        @foreach ($scopes as $key => $text)
            <button type="button" wire:click="$set('{{ $scopeModel }}', '{{ $key }}')" @class(['tab', 'active' => $scopeValue === $key]) aria-pressed="{{ $scopeValue === $key ? 'true' : 'false' }}">{{ $text }}</button>
        @endforeach
    </div>

    <div class="ui-form-grid">
        <x-ui.input label="Search" wire:model.live="{{ $searchModel }}" placeholder="Name, staff ID or department" icon="search" />
        <x-ui.select label="Recipient" wire:model="{{ $toModel }}">
            <option value="">{{ $hasOptions ? 'Select recipient' : 'No matching recipient' }}</option>
            @if ($picker['previous'])
                <optgroup label="Previous holder">
                    <option value="{{ $picker['previous']->id }}">{{ $workflow->recipientLabel($picker['previous']) }}</option>
                </optgroup>
            @endif
            @if ($picker['recent']->isNotEmpty())
                <optgroup label="Recent recipients">
                    @foreach ($picker['recent'] as $recent)
                        <option value="{{ $recent->id }}">{{ $workflow->recipientLabel($recent) }}</option>
                    @endforeach
                </optgroup>
            @endif
            @if ($picker['secretaries']->isNotEmpty())
                <optgroup label="Secretaries">
                    @foreach ($picker['secretaries'] as $person)
                        <option value="{{ $person->id }}">{{ $workflow->recipientLabel($person) }}</option>
                    @endforeach
                </optgroup>
            @endif
            @if ($picker['managers']->isNotEmpty())
                <optgroup label="Managers">
                    @foreach ($picker['managers'] as $person)
                        <option value="{{ $person->id }}">{{ $workflow->recipientLabel($person) }}</option>
                    @endforeach
                </optgroup>
            @endif
        </x-ui.select>
    </div>
</div>
