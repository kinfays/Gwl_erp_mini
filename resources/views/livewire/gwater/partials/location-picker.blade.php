{{-- super_admin has no location of their own: they choose the books they are working in. Everyone else is shown whose books these are. --}}
@if ($isSuperAdmin)
    <x-ui.card class="dash-row">
        <x-ui.select label="Location (the books you are working in)" wire:model.live="locationId">
            <option value="">Choose a location</option>
            @foreach ($locationChoices as $district)
                <option value="{{ $district->id }}">{{ $district->district_name }} - {{ $district->region?->region_name }}</option>
            @endforeach
        </x-ui.select>
    </x-ui.card>
@endif

@unless ($location)
    <x-ui.alert tone="warning" role="status" class="dash-row">
        @if ($isSuperAdmin)
            Choose a location above to see its books.
        @else
            Your account has no location (employee record with a region), so there are no books for you here. Ask an administrator.
        @endif
    </x-ui.alert>
@endunless
