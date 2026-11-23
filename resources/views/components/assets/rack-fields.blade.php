{{--
    The optional Rack / Starting U / U height fields of the Assets form (servers) and the Network form (routers and
    switches). Rendered only where IctAsset::supportsRack() is true and the person may see racks. The Livewire component
    (trait HandlesRackPlacement) supplies $rackOptions; AssetRecordService decides whether the position fits.
--}}
@props([
    'racks',
    'chosen' => false,
])

<div class="span-2">
    <x-ui.select label="Rack" wire:model.live="form.rack_id" hint="Optional. The cabinet this device is mounted in.">
        <option value="">Not in a rack</option>
        @foreach ($racks as $rack)
            <option value="{{ $rack->id }}">{{ $rack->name }}@if ($rack->location) &mdash; {{ $rack->location }}@endif ({{ $rack->total_units }}U)@if ($rack->status !== \App\Models\IctRack::STATUS_ACTIVE) &mdash; {{ $rack->status }}@endif</option>
        @endforeach
    </x-ui.select>
</div>

@if ($chosen)
    <x-ui.input label="Starting U" type="number" min="1" inputmode="numeric" wire:model.defer="form.rack_unit" hint="1 is the bottom of the rack." />
    <x-ui.input label="U height" type="number" min="1" inputmode="numeric" wire:model.defer="form.rack_unit_span" hint="How many U the device takes up (a 1U switch is 1)." />
@endif
