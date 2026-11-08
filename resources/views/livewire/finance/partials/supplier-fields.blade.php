{{-- The fields of a supplier, shared by the Suppliers screen and the PV form's "add a supplier" panel.
     Needs $prefix (the wire:model path of the array holding name/code/tin/phone), $matches (look-alike suppliers of the typed name)
     and $ackModel (the property holding "this is a different supplier"). Validation errors are keyed "{$prefix}.name" etc. --}}
<div class="ui-form-grid">
    <x-ui.input label="Supplier name" required wire:model.live.debounce.500ms="{{ $prefix }}.name" maxlength="200" />
    <x-ui.input label="Supplier code" wire:model="{{ $prefix }}.code" maxlength="40" hint="Optional." />
    <x-ui.input label="TIN" wire:model="{{ $prefix }}.tin" maxlength="14" hint="Optional. One letter and ten digits, for example P0012345678." />
    <x-ui.input label="Phone" inputmode="tel" wire:model="{{ $prefix }}.phone" maxlength="30" hint="Optional. 9 to 15 digits." />
</div>

@if ($matches)
    <x-ui.alert tone="warning" title="Already on the list?" role="status" class="dash-row">
        <ul>
            @foreach ($matches as $match)
                <li wire:key="match-{{ $prefix }}-{{ $match['id'] }}">{{ $match['name'] }}@unless ($match['active']) <span class="cell-muted">(switched off)</span>@endunless</li>
            @endforeach
        </ul>
        If your supplier is one of these, pick it instead of adding it again.
        <x-ui.checkbox label="This is a different supplier" wire:model="{{ $ackModel }}" />
    </x-ui.alert>
@endif
