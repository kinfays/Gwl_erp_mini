{{-- Read-only region on the Assets, Phones and Network forms: always the actor's own region, stamped server-side on save. --}}
@props([
    'region',
    'label' => 'Region',
])

<div class="form-field">
    <label class="form-label">{{ $label }}</label>
    @if ($region)
        <div style="padding:6px 0;font-size:13px">{{ $region->region_name }}</div>
    @else
        <div class="txt-err" style="padding:6px 0">No region on file</div>
    @endif
    <div style="font-size:10px;color:var(--color-text-secondary)">Set automatically from your staff record.</div>
    @error('form.region_id') <div class="txt-err">{{ $message }}</div> @enderror
</div>
