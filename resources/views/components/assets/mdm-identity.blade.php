{{--
    Who exactly a destructive action would hit: asset tag, serial, IMEI and the assigned employee, read from the
    ERP asset record (not from the phone). Shown inside the Lost Mode and Wipe confirmation modals so nobody
    confirms against the wrong device.
--}}
@props([
    'device',
])

@php
    $asset = $device->asset;
@endphp

<dl {{ $attributes->class(['ui-dl', 'mdm-identity']) }}>
    <div>
        <dt>Asset tag</dt>
        <dd>{{ $asset?->asset_name ?? 'No linked asset' }}</dd>
    </div>
    <div>
        <dt>Serial number</dt>
        <dd class="mono">{{ $asset?->serial_number ?: ($device->hardware_info['serialNumber'] ?? '—') }}</dd>
    </div>
    <div>
        <dt>IMEI</dt>
        <dd class="mono">{{ $asset?->imei ?: ($device->hardware_info['imei'] ?? '—') }}</dd>
    </div>
    <div>
        <dt>Assigned to</dt>
        <dd>{{ $asset?->assignedTo?->full_name ?? 'Unassigned' }}</dd>
    </div>
</dl>
