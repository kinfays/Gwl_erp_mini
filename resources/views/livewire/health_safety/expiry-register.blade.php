<div>
    <x-ui.page-header title="Expiry register" description="Every dated item you may see, most urgent first: extinguisher expiry, service and hydrostatic test dates, first aid kit items and PPE replacements. Checks are not listed here (see the extinguisher and kit lists).">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button :href="route('health_safety.export', ['report' => 'expiry-register'] + $exportFilters)" icon="file-spreadsheet">Excel</x-ui.button>
                <x-ui.button :href="route('health_safety.export', ['report' => 'expiry-register-pdf'] + $exportFilters)" icon="file-text">Site walk-round PDF</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-stat-grid dash-row">
        @foreach ($buckets as $key => $label)
            <x-ui.stat-tile :label="$label" :value="$counts['by_bucket'][$key]" :icon="$key === 'later' ? 'calendar-days' : ($key === 'due_soon' ? 'hourglass' : 'triangle-alert')"
                :tone="$counts['by_bucket'][$key] > 0 ? ($key === 'later' ? 'muted' : ($key === 'due_soon' ? 'warning' : 'danger')) : 'muted'"
                :meta="match ($key) { 'overdue' => 'Past the date', 'critical' => 'Within '.\App\Services\HealthSafety\HealthSafetySettings::value('hs_expiry_critical_days').' days', 'due_soon' => 'Within '.\App\Services\HealthSafety\HealthSafetySettings::value('hs_expiry_warning_days').' days', default => 'Further out' }"
                :href="route('health_safety.expiry-register', array_merge($exportFilters, ['bucket' => $key]))" />
        @endforeach
    </div>

    <x-ui.card :padded="false" class="dash-row">
        <div class="ui-toolbar" role="search" aria-label="Filter the register">
            <select class="form-input" wire:model.live="type" aria-label="Type">
                <option value="">All types ({{ array_sum($counts['by_type']) }})</option>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}">{{ $label }} ({{ $counts['by_type'][$value] }})</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="bucket" aria-label="Bucket">
                <option value="">Any urgency</option>
                @foreach ($buckets as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="horizon" aria-label="Horizon">
                <option value="overdue">Overdue only</option>
                @foreach ($horizons as $days)
                    <option value="{{ $days }}">Due within {{ $days }} days</option>
                @endforeach
            </select>
            @if ($seesAll)
                <select class="form-input" wire:model.live="regionId" aria-label="Region">
                    <option value="">All regions</option>
                    @foreach ($regions as $region)
                        <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                    @endforeach
                </select>
            @endif
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
            <x-ui.button size="sm" variant="ghost" wire:click="resetFilters">Clear</x-ui.button>
        </div>

        <x-ui.table label="Expiry register">
            <x-slot:head>
                <tr>
                    <th>What</th>
                    <th>Type</th>
                    <th>Where</th>
                    <th>Due</th>
                    <th class="num">Days</th>
                    <th>Bucket</th>
                    <th>Responsible</th>
                </tr>
            </x-slot:head>
            @forelse ($rows as $row)
                <tr wire:key="hs-expiry-{{ $row['key'] }}">
                    <td><a href="{{ $row['url'] }}">{{ $row['what'] }}</a></td>
                    <td>{{ $row['type_label'] }}</td>
                    <td>{{ $row['where'] }}</td>
                    <td class="nowrap">{{ $row['due_on']->format('d M Y') }}</td>
                    <td class="num">{{ $row['days'] }}</td>
                    <td><x-ui.status-pill domain="hs-expiry" :status="$row['bucket']" /></td>
                    <td class="cell-muted">{{ $row['responsible'] ?? '—' }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="inbox" title="Nothing is due in this window.">
                    <x-ui.button size="sm" wire:click="resetFilters">Clear filters</x-ui.button>
                </x-ui.empty-row>
            @endforelse

            @if ($rows->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $rows->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
