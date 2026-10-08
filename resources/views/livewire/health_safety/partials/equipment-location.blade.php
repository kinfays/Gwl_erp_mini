{{-- Shared by the extinguisher and first aid kit forms. Needs the PicksEquipmentLocation properties and locationOptions(). --}}
<fieldset class="chip-group">
    <legend class="ui-label">Where is it? <span class="ui-req" aria-hidden="true">*</span></legend>
    <div class="chip-options">
        <label class="chip-option"><input type="radio" name="locationMode" value="site" wire:model.live="locationMode"><span>At a site</span></label>
        <label class="chip-option"><input type="radio" name="locationMode" value="vehicle" wire:model.live="locationMode"><span>In a vehicle</span></label>
    </div>
    @error('location')<p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>@enderror
</fieldset>

<div class="ui-form-grid" style="margin-top:14px">
    @if ($locationMode === 'site')
        <x-ui.select label="Site" wire:model="siteId" error="site_id" required hint="Not listed? Add it under Sites first.">
            <option value="">Select site</option>
            @foreach ($locationSites as $site)
                <option value="{{ $site->id }}">{{ $site->name }} ({{ $site->kindLabel() }}@if ($site->district), {{ $site->district->district_name }}@endif)</option>
            @endforeach
        </x-ui.select>
    @else
        <div>
            @if ($vehicleId)
                <span class="ui-label">Vehicle</span>
                <p>{{ $vehicleLabel }} <x-ui.button size="sm" variant="ghost" wire:click="clearVehicle">Change</x-ui.button></p>
            @else
                <x-ui.input label="Find the vehicle by number plate" wire:model.live.debounce.300ms="vehicleSearch" error="vehicle_id" required />
                @foreach ($vehicleMatches as $match)
                    <x-ui.button size="sm" variant="ghost" wire:key="hs-veh-{{ $match->id }}" wire:click="chooseVehicle({{ $match->id }})">{{ $match->number_plate }} <span class="cell-muted">{{ $match->brand }} {{ $match->model }}</span></x-ui.button>
                @endforeach
            @endif
        </div>
        @if ($locationRegions->isNotEmpty())
            <x-ui.select label="Region the vehicle is based in" wire:model.live="regionId" error="region_id" required>
                <option value="">Select region</option>
                @foreach ($locationRegions as $region)
                    <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                @endforeach
            </x-ui.select>
        @endif
        <x-ui.select label="District (optional)" wire:model="districtId" error="district_id" hint="A vehicle has no district of its own, so say where it is based.">
            <option value="">None</option>
            @foreach ($locationDistricts as $district)
                <option value="{{ $district->id }}">{{ $district->district_name }}</option>
            @endforeach
        </x-ui.select>
    @endif

    <x-ui.input label="Where exactly (optional)" wire:model="locationDetail" hint="For example: ground floor corridor, driver's door pocket." />

    <div>
        @if ($responsibleEmployeeId)
            <span class="ui-label">Responsible person</span>
            <p>{{ $responsibleLabel }} <x-ui.button size="sm" variant="ghost" wire:click="clearResponsible">Change</x-ui.button></p>
        @else
            <x-ui.input label="Responsible person (optional)" wire:model.live.debounce.300ms="responsibleSearch" hint="Name or staff ID. They may record checks on this item." />
            @foreach ($responsibleMatches as $match)
                <x-ui.button size="sm" variant="ghost" wire:key="hs-resp-{{ $match->id }}" wire:click="chooseResponsible({{ $match->id }})">{{ $match->full_name }} <span class="mono">{{ $match->staff_id }}</span></x-ui.button>
            @endforeach
        @endif
    </div>
</div>
