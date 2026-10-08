<div>
    <x-ui.page-header title="Fire extinguishers" description="Every extinguisher you may see, with the state its dates and last check put it in.">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button :href="route('health_safety.export', ['report' => 'extinguishers'] + $exportFilters)" icon="file-spreadsheet">Excel</x-ui.button>
            @endif
            @if ($canManage)
                <x-ui.button :href="route('health_safety.equipment-import')" icon="upload">Import</x-ui.button>
                <x-ui.button :href="route('health_safety.extinguishers.create')" variant="primary" icon="plus">Add extinguisher</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padded="false" class="dash-row">
        <div class="ui-toolbar" role="search" aria-label="Filter extinguishers">
            <input type="search" class="form-input" wire:model.live.debounce.300ms="search" placeholder="Asset code, serial, place or plate" aria-label="Search extinguishers">
            <select class="form-input" wire:model.live="state" aria-label="State">
                <option value="">Any state</option>
                @foreach ($states as $name => $definition)
                    <option value="{{ $name }}">{{ $definition['label'] }}</option>
                @endforeach
                <option value="ok">OK</option>
            </select>
            <select class="form-input" wire:model.live="expiring" aria-label="Expiring within">
                <option value="">Any expiry</option>
                @foreach (array_unique([(int) \App\Services\HealthSafety\HealthSafetySettings::value('hs_expiry_critical_days'), $warningDays, 90]) as $days)
                    <option value="{{ $days }}">Expiring within {{ $days }} days</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="type" aria-label="Type">
                <option value="">All types</option>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="districtId" aria-label="District">
                <option value="">All districts</option>
                @foreach ($districts as $district)
                    <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="siteId" aria-label="Site">
                <option value="">All sites</option>
                @foreach ($sites as $site)
                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="status" aria-label="Status">
                <option value="">Active units</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <x-ui.checkbox label="In vehicles only" wire:model.live="inVehicles" />
            @if ($canManage)
                <select class="form-input" wire:model.live="label" aria-label="Label">
                    <option value="">Any label status</option>
                    <option value="none">No label printed yet</option>
                </select>
            @endif
            <x-ui.button size="sm" variant="ghost" wire:click="resetFilters">Clear</x-ui.button>
        </div>
        @if ($canManage)
            @include('livewire.health_safety.partials.label-controls', ['total' => $units->total()])
        @endif

        <x-ui.table label="Fire extinguishers" pin-first>
            <x-slot:head>
                <tr>
                    <th>Asset code</th>
                    <th>Type</th>
                    <th>Where</th>
                    <th aria-sort="{{ $sort === 'expiry' ? 'ascending' : ($sort === '-expiry' ? 'descending' : 'none') }}">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="sortByExpiry">Expires {{ $sort === 'expiry' ? '▲' : ($sort === '-expiry' ? '▼' : '') }}</button>
                    </th>
                    <th>Next service</th>
                    <th>Last check</th>
                    <th>State</th>
                    @if ($canManage)<th>Label</th>@endif
                </tr>
            </x-slot:head>
            @forelse ($units as $unit)
                @php $state = $unit->state(); @endphp
                <tr wire:key="hs-ext-{{ $unit->id }}">
                    <td><a class="mono" href="{{ route('health_safety.extinguishers.show', $unit) }}">{{ $unit->asset_code }}</a></td>
                    <td>{{ $unit->typeLabel() }}@if ($unit->capacity) <span class="cell-muted">{{ $unit->capacity }}</span>@endif</td>
                    <td>
                        {{ $unit->locationLabel() }}
                        @if ($unit->district)<br><span class="ui-person-sub">{{ $unit->district->district_name }}</span>@endif
                    </td>
                    <td class="nowrap">{{ $unit->expiry_date?->format('d M Y') ?? '—' }}</td>
                    <td class="nowrap">{{ $unit->next_service_due?->format('d M Y') ?? '—' }}</td>
                    <td class="nowrap cell-muted">{{ $unit->last_checked_on?->format('d M Y') ?? 'Never' }}</td>
                    <td><x-ui.status-pill domain="hs-extinguisher" :status="$state ?? $unit->status" :label="$state ? \App\Models\HsFireExtinguisher::stateLabel($state) : \App\Models\HsFireExtinguisher::STATUSES[$unit->status]" /></td>
                    @if ($canManage)
                        <td class="nowrap">
                            <label><input type="checkbox" wire:model.live="selected" value="{{ $unit->id }}" aria-label="Select unit for a label"> <span class="cell-muted">{{ $unit->label_printed_at?->format('d M Y') ?? 'None yet' }}</span></label>
                        </td>
                    @endif
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="inbox" title="No extinguishers match these filters.">
                    <x-ui.button size="sm" wire:click="resetFilters">Clear filters</x-ui.button>
                    @if ($canManage)
                        <x-ui.button size="sm" :href="route('health_safety.extinguishers.create')">Add the first one</x-ui.button>
                    @endif
                </x-ui.empty-row>
            @endforelse

            @if ($units->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $units->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
