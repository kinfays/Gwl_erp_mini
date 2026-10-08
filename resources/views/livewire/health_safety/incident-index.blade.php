<div>
    <x-ui.page-header title="Incidents" description="Every report you may see, newest first.">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button :href="route('health_safety.export', ['report' => 'incidents'] + $exportFilters)" icon="file-spreadsheet">Excel</x-ui.button>
            @endif
            <x-ui.button :href="route('health_safety.report')" variant="primary" icon="circle-plus">Report an incident</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padded="false" class="dash-row">
        <div class="ui-toolbar" role="search" aria-label="Filter incidents">
            <input type="search" class="form-input" wire:model.live.debounce.300ms="search" placeholder="Reference or words in the report" aria-label="Search incidents">
            <select class="form-input" wire:model.live="status" aria-label="Status">
                <option value="">All statuses</option>
                <option value="open">All open</option>
                <option value="in_progress">In progress</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="type" aria-label="Type">
                <option value="">All types</option>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="overdue" aria-label="Overdue">
                <option value="">Any timing</option>
                <option value="ack">Not acknowledged in time</option>
                <option value="investigation">Investigation overdue</option>
            </select>
            <select class="form-input" wire:model.live="severity" aria-label="Severity">
                <option value="">All severities</option>
                <option value="unrated">Not rated yet</option>
                @foreach ($severities as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select class="form-input" wire:model.live="districtId" aria-label="District">
                <option value="">All districts</option>
                @foreach ($districts as $district)
                    <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                @endforeach
            </select>
            <input type="date" class="form-input" wire:model.live="from" aria-label="Occurred from">
            <input type="date" class="form-input" wire:model.live="to" aria-label="Occurred to">
            <x-ui.button size="sm" variant="ghost" wire:click="resetFilters">Clear</x-ui.button>
        </div>

        <x-ui.table label="Incident register" pin-first>
            <x-slot:head>
                <tr>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>Where</th>
                    <th>Occurred</th>
                    <th>Reported by</th>
                    <th>Severity</th>
                    <th>Status</th>
                </tr>
            </x-slot:head>
            @forelse ($incidents as $incident)
                @php $reporter = $visibility->reporterFor($viewer, $incident); @endphp
                <tr wire:key="hs-incident-{{ $incident->id }}">
                    <td>
                        <a class="mono" href="{{ route('health_safety.incidents.show', $incident) }}">{{ $incident->reference }}</a>
                        @if ($incident->is_urgent)<x-ui.badge tone="danger">Urgent</x-ui.badge>@endif
                    </td>
                    <td>{{ $incident->typeLabel() }}</td>
                    <td>{{ $incident->placeLabel() }}</td>
                    <td class="nowrap cell-muted">{{ $incident->occurred_on->format('d M Y') }}</td>
                    <td>{{ $reporter['name'] }}@if ($reporter['recorded_by']) <span class="cell-muted">(recorded by {{ $reporter['recorded_by'] }})</span>@endif</td>
                    <td>@if ($incident->severity)<x-ui.status-pill domain="severity" :status="$incident->severity" />@else<span class="cell-muted">Not rated</span>@endif</td>
                    <td>
                        <x-ui.status-pill domain="hs-incident" :status="$incident->status" />
                        @if ($incident->isAcknowledgementOverdue())<x-ui.badge tone="warning">Overdue</x-ui.badge>@endif
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="inbox" title="No incidents match these filters.">
                    <x-ui.button size="sm" wire:click="resetFilters">Clear filters</x-ui.button>
                </x-ui.empty-row>
            @endforelse

            @if ($incidents->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $incidents->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
