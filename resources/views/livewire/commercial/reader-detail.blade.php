<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    @php $pct = fn ($value) => $value === null ? '–' : number_format($value, 1).'%'; @endphp

    <x-ui.page-header :title="$reader['name']" :description="'Reader '.$reader['staff_id'].' · home district '.($reader['district'] ?? 'unknown').' (from the staff directory)'">
        <x-slot:actions>
            <a href="{{ route('commercial.reading', array_filter(['tab' => 'readers', 'from' => $from, 'to' => $to])) }}" class="btn btn-secondary">Back to readers</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($reader['match_status'] === 'unmatched')
        <x-ui.alert tone="warning" class="dash-row">
            This reader's staff ID is not in the staff directory, so there is no home district.
            @if ($canResolve)
                <a href="{{ route('commercial.batches.show', $batchId) }}">Link them to an employee on the batch screen.</a>
            @endif
        </x-ui.alert>
    @endif

    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Visited" :value="number_format($totals['visited'])" icon="users" />
        <x-ui.stat-tile label="Read" :value="number_format($totals['read'])" icon="check" tone="success" />
        <x-ui.stat-tile label="Skip rate" :value="$pct($totals['skip_rate'])" icon="triangle-alert" tone="warning" :meta="'Configured target: '.number_format((float) config('gwl.commercial_target_skip_rate_pct'), 0).'%'" />
        <x-ui.stat-tile label="Months with visits" :value="$totals['months_active'].' of '.count($months)" icon="calendar-days" />
    </div>

    <div class="ui-grid ui-grid-2 dash-row">
        <x-ui.card title="Visits per month" description="Read plus skipped.">
            <div wire:key="reader-visits-{{ md5(json_encode($months)) }}">
                <x-ui.chart type="bar" label="Visits per month" :stacked="true" :labels="$labels"
                    :series="[
                        ['label' => 'Read', 'data' => array_column($months, 'read'), 'color' => 'series-1'],
                        ['label' => 'Skipped', 'data' => array_column($months, 'skipped'), 'color' => 'warning'],
                    ]" height="240" />
            </div>
        </x-ui.card>

        <x-ui.card title="Skip rate" description="Skipped ÷ visited.">
            <div wire:key="reader-skip-{{ md5(json_encode($months)) }}">
                <x-ui.chart type="line" label="Skip rate per month" unit="%" :labels="$labels"
                    :series="[['label' => 'Skip rate', 'data' => array_column($months, 'skip_rate'), 'color' => 'warning']]" height="240" />
            </div>
        </x-ui.card>
    </div>

    <x-ui.card title="Month by month" :padded="false" class="dash-row">
        <x-ui.table label="Reader's months" :sticky="false">
            <x-slot:head>
                <tr><th>Month</th><th class="num">Visited</th><th class="num">Read</th><th class="num">Skipped</th><th class="num">Read rate</th><th class="num">Skip rate</th></tr>
            </x-slot:head>
            @foreach ($months as $row)
                <tr wire:key="rd-{{ $row['month'] }}">
                    <td>{{ $row['label'] }} @if ($row['in_progress'])<x-ui.badge tone="warning">In progress</x-ui.badge>@endif</td>
                    <td class="num">{{ number_format($row['visited']) }}</td>
                    <td class="num">{{ number_format($row['read']) }}</td>
                    <td class="num">{{ number_format($row['skipped']) }}</td>
                    <td class="num">{{ $pct($row['read_rate']) }}</td>
                    <td class="num">{{ $pct($row['skip_rate']) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
