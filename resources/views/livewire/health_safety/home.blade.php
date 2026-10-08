<div>
    <x-ui.page-header title="Health & Safety" description="Incidents and near misses waiting for you, with the rows behind each number.">
        <x-slot:actions>
            <x-ui.button :href="route('health_safety.report')" variant="primary" icon="circle-plus">Report an incident</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-stat-grid dash-row">
        @foreach ($tiles as $tile)
            <x-ui.stat-tile :label="$tile['label']" :value="$tile['value']" :icon="$tile['icon']" :tone="$tile['tone']" :meta="$tile['meta']" :href="$canSeeRegister ? $tile['href'] : null" />
        @endforeach
    </div>

    @if ($equipmentTiles !== [])
        <h2 class="ui-label dash-row">Equipment</h2>
        <div class="ui-stat-grid">
            @foreach ($equipmentTiles as $tile)
                <x-ui.stat-tile :label="$tile['label']" :value="$tile['value']" :icon="$tile['icon']" :tone="$tile['tone']" :meta="$tile['meta']" :href="$tile['href']" />
            @endforeach
        </div>
    @endif

    @if ($ppeTiles !== [])
        <h2 class="ui-label dash-row">PPE</h2>
        <div class="ui-stat-grid">
            @foreach ($ppeTiles as $tile)
                <x-ui.stat-tile :label="$tile['label']" :value="$tile['value']" :icon="$tile['icon']" :tone="$tile['tone']" :meta="$tile['meta']" :href="$tile['href']" />
            @endforeach
        </div>
    @endif

    <x-ui.card title="Latest reports" description="The most recent reports you may see." :padded="false" class="dash-row">
        <x-ui.table label="Latest incident reports">
            <x-slot:head>
                <tr>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>Where</th>
                    <th>Occurred</th>
                    <th>Severity</th>
                    <th>Status</th>
                </tr>
            </x-slot:head>
            @forelse ($recent as $incident)
                <tr wire:key="hs-home-{{ $incident->id }}">
                    <td><a class="mono" href="{{ route('health_safety.incidents.show', $incident) }}">{{ $incident->reference }}</a></td>
                    <td>{{ $incident->typeLabel() }}</td>
                    <td>{{ $incident->placeLabel() }}</td>
                    <td class="nowrap cell-muted">{{ $incident->occurred_on->format('d M Y') }}</td>
                    <td>@if ($incident->severity)<x-ui.status-pill domain="severity" :status="$incident->severity" />@else<span class="cell-muted">Not rated</span>@endif</td>
                    <td><x-ui.status-pill domain="hs-incident" :status="$incident->status" /></td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="6" icon="inbox" title="No incident reports yet." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
