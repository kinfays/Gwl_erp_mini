{{-- Read-only region on the Assets, Phones and Network forms: always the actor's own region, stamped server-side on save. --}}
@props([
    'region',
    'label' => 'Region',
])

<div {{ $attributes->class(['ui-field']) }}>
    <span class="ui-label">{{ $label }}</span>
    @if ($region)
        <p class="readonly-value">{{ $region->region_name }}</p>
    @else
        <p class="readonly-value is-missing">No region on file</p>
    @endif
    <p class="ui-hint">Set automatically from your staff record.</p>
    @error('form.region_id')
        <p class="ui-error">
            <x-ui.icon name="circle-alert" class="icon-sm" />
            <span>{{ $message }}</span>
        </p>
    @enderror
</div>
