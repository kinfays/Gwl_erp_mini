<div>
    <x-ui.page-header title="First aid kits" description="Every kit you may see, with the state its contents and last check put it in.">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button :href="route('health_safety.export', ['report' => 'kits'] + $exportFilters)" icon="file-spreadsheet">Excel</x-ui.button>
            @endif
            @if ($canManage)
                <x-ui.button :href="route('health_safety.equipment-import')" icon="upload">Import</x-ui.button>
                <x-ui.button :href="route('health_safety.kits.create')" variant="primary" icon="plus">Add kit</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($noTemplates)
        <x-ui.alert tone="info" class="dash-row">
            No kit templates exist yet, so new kits start empty. <a href="{{ route('health_safety.kit-templates') }}">Set up the kit templates</a> first to have each new kit filled with its usual contents.
        </x-ui.alert>
    @endif

    <x-ui.card :padded="false" class="dash-row">
        <div class="ui-toolbar" role="search" aria-label="Filter kits">
            <input type="search" class="form-input" wire:model.live.debounce.300ms="search" placeholder="Asset code, place or plate" aria-label="Search kits">
            <select class="form-input" wire:model.live="state" aria-label="State">
                <option value="">Any state</option>
                @foreach ($states as $name => $definition)
                    <option value="{{ $name }}">{{ $definition['label'] }}</option>
                @endforeach
                <option value="ok">OK</option>
            </select>
            <select class="form-input" wire:model.live="expiring" aria-label="Item expiring within">
                <option value="">Any expiry</option>
                @foreach (array_unique([(int) \App\Services\HealthSafety\HealthSafetySettings::value('hs_expiry_critical_days'), $warningDays, 90]) as $days)
                    <option value="{{ $days }}">An item expires within {{ $days }} days</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="type" aria-label="Kit type">
                <option value="">All kit types</option>
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
                <option value="">Active kits</option>
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
            @include('livewire.health_safety.partials.label-controls', ['total' => $kits->total()])
        @endif

        <x-ui.table label="First aid kits" pin-first>
            <x-slot:head>
                <tr>
                    <th>Asset code</th>
                    <th>Type</th>
                    <th>Where</th>
                    <th class="num">Items</th>
                    <th>Earliest expiry</th>
                    <th>Last check</th>
                    <th>State</th>
                    @if ($canManage)<th>Label</th>@endif
                </tr>
            </x-slot:head>
            @forelse ($kits as $kit)
                @php $state = $kit->state(); $earliest = $kit->items->whereNotNull('expiry_date')->min('expiry_date'); @endphp
                <tr wire:key="hs-kit-{{ $kit->id }}">
                    <td><a class="mono" href="{{ route('health_safety.kits.show', $kit) }}">{{ $kit->asset_code }}</a></td>
                    <td>{{ $kit->typeLabel() }}</td>
                    <td>{{ $kit->locationLabel() }}@if ($kit->district)<br><span class="ui-person-sub">{{ $kit->district->district_name }}</span>@endif</td>
                    <td class="num">{{ $kit->items->count() }}</td>
                    <td class="nowrap">{{ $earliest ? \Illuminate\Support\Carbon::parse($earliest)->format('d M Y') : '—' }}</td>
                    <td class="nowrap cell-muted">{{ $kit->last_checked_on?->format('d M Y') ?? 'Never' }}</td>
                    <td><x-ui.status-pill domain="hs-kit" :status="$state ?? $kit->status" :label="$state ? \App\Models\HsFirstAidKit::stateLabel($state) : $statuses[$kit->status]" /></td>
                    @if ($canManage)
                        <td class="nowrap">
                            <label><input type="checkbox" wire:model.live="selected" value="{{ $kit->id }}" aria-label="Select kit for a label"> <span class="cell-muted">{{ $kit->label_printed_at?->format('d M Y') ?? 'None yet' }}</span></label>
                        </td>
                    @endif
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="inbox" title="No kits match these filters.">
                    <x-ui.button size="sm" wire:click="resetFilters">Clear filters</x-ui.button>
                    @if ($canManage)
                        <x-ui.button size="sm" :href="route('health_safety.kits.create')">Add the first one</x-ui.button>
                    @endif
                </x-ui.empty-row>
            @endforelse

            @if ($kits->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $kits->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
