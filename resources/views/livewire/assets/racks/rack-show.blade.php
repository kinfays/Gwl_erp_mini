<div>
    @php
        $dash = fn ($value) => filled($value) ? $value : '—';
        $rangeLabel = fn (array $range) => $range[0] === $range[1] ? 'U'.$range[0] : 'U'.$range[0].'-U'.$range[1];
    @endphp

    <x-ui.page-header :title="$rack->name" :description="($rack->location ?: 'No location note').' · '.($rack->district?->district_name ?: 'No district')">
        <x-slot:actions>
            <x-ui.status-pill domain="rack" :status="$rack->status" />
            @if ($canManage)
                <a href="{{ route('assets.racks.edit', $rack) }}" class="btn btn-secondary">
                    <x-ui.icon name="pencil" />
                    Edit rack
                </a>
            @endif
            <a href="{{ route('assets.racks') }}" class="btn btn-ghost">Back to racks</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-grid ui-grid-2 dash-row">
        <x-ui.card title="Rack">
            <dl class="ui-dl">
                <div><dt>Name</dt><dd>{{ $rack->name }}</dd></div>
                <div><dt>Code</dt><dd class="mono">{{ $dash($rack->code) }}</dd></div>
                <div><dt>Location</dt><dd>{{ $dash($rack->location) }}</dd></div>
                <div><dt>District</dt><dd>{{ $dash($rack->district?->district_name) }}@if ($rack->region) <span class="ui-person-sub">{{ $rack->region->region_name }}</span>@endif</dd></div>
                <div><dt>Status</dt><dd>{{ $rack->status }}</dd></div>
                <div><dt>Notes</dt><dd>{{ $dash($rack->notes) }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Space">
            <dl class="ui-dl">
                <div><dt>Height</dt><dd>{{ $rack->total_units }} U</dd></div>
                <div><dt>Used</dt><dd>{{ $usedUnits }} U</dd></div>
                <div><dt>Free</dt><dd>{{ $freeUnits }} U</dd></div>
                <div><dt>Devices</dt><dd>{{ $devices->count() }}</dd></div>
                <div>
                    <dt>Free stretches</dt>
                    <dd>
                        @if ($freeRanges === [])
                            Full
                        @else
                            {{ collect($freeRanges)->map($rangeLabel)->implode(', ') }}
                            <span class="ui-person-sub">Unit 1 is the bottom of the rack.</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-ui.card>
    </div>

    <x-ui.card title="Mounted devices" :description="$devices->count().' '.($devices->count() === 1 ? 'device' : 'devices').', bottom to top'" :padded="false">
        <x-ui.table label="Devices mounted in {{ $rack->name }}">
            <x-slot:head>
                <tr>
                    <th>Position</th>
                    <th>Device</th>
                    <th>Type / Model</th>
                    <th>Status</th>
                    <th class="actions"><span class="sr-only-text">Actions</span></th>
                </tr>
            </x-slot:head>

            @forelse ($devices as $device)
                <tr wire:key="rack-device-{{ $device->id }}">
                    <td class="mono">{{ $device->rackPositionLabel() ?: 'No position' }}</td>
                    <td>
                        <span class="ui-cell-stack">
                            <a href="{{ route('assets.show', $device) }}" class="ui-person-name">{{ $device->asset_name }}</a>
                            <span class="ui-person-sub mono">{{ $device->serial_number ?: 'No serial' }}</span>
                        </span>
                    </td>
                    <td>
                        <span class="ui-cell-stack">
                            <span>{{ $typeLabels[$device->asset_type] ?? $device->asset_type }}</span>
                            <span class="ui-person-sub">{{ $device->assetModel?->name ?: 'No model' }}</span>
                        </span>
                    </td>
                    <td><x-ui.status-pill domain="asset" :status="$device->status" /></td>
                    <td class="actions">
                        <a href="{{ route('assets.show', $device) }}" class="btn btn-ghost btn-sm btn-icon" title="View" aria-label="View {{ $device->asset_name }}">
                            <x-ui.icon name="eye" />
                        </a>
                        @if ($canEditDevices)
                            <a href="{{ route($categoryRoutes[$device->device_category] ?? 'assets.assets', ['q' => $device->serial_number ?: $device->asset_name]) }}" class="btn btn-ghost btn-sm btn-icon" title="Open in list to edit" aria-label="Open {{ $device->asset_name }} in its list to edit">
                                <x-ui.icon name="pencil" />
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" icon="server" title="Nothing is mounted in this rack yet." description="Pick this rack on a server, router or switch from the Assets or Network list." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
