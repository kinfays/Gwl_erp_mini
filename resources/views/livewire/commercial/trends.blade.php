<div>
    @php $parts = $r['parts']; @endphp

    <x-ui.page-header :title="$r['code'].' · '.$r['title']" :description="$r['question']">
        <x-slot:actions>
            @if ($canExport && $r['available'])
                <a href="{{ route('commercial.export', ['report' => 'trends-'.$trend, 'format' => 'excel', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.file-icon type="excel" /> Excel</a>
                <a href="{{ route('commercial.export', ['report' => 'trends-'.$trend, 'format' => 'pdf', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.file-icon type="pdf" /> PDF</a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <nav class="ui-toolbar dash-row" aria-label="Trends" data-trend-tabs>
        @foreach ($trends as $key => $t)
            <a href="{{ route('commercial.trends.show', ['trend' => $key]) }}" @class(['btn', 'btn-sm', 'btn-primary' => $key === $trend, 'btn-secondary' => $key !== $trend]) @if ($key === $trend) aria-current="page" @endif title="{{ $t['question'] }}">{{ $t['code'] }} {{ $t['title'] }}</a>
        @endforeach
    </nav>

    @if ($r['region_id'] === null)
        <x-ui.card>
            <x-ui.empty-state icon="trending-up" title="Nothing has been loaded yet." description="Upload the reading report by route, the Detailed Billing Report or the customer list, and the trends appear here." />
        </x-ui.card>
    @else
        <div class="ui-toolbar dash-row" role="search" aria-label="Trend range and filters">
            @if (count($r['regions']) > 1)
                <select wire:model.live="region" class="form-input" aria-label="Region">
                    @foreach ($r['regions'] as $id => $name)<option value="{{ $id }}" @selected($id === $r['region_id'])>{{ $name }}</option>@endforeach
                </select>
            @endif
            @if ($trend !== 't8')
                <select wire:model.live="preset" class="form-input" aria-label="A whole month">
                    <option value="">A whole month…</option>
                    @foreach ($monthOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                <input type="text" wire:model.live.debounce.500ms="dateFrom" class="form-input" style="max-width:11rem" placeholder="From date 2026-09-01" aria-label="From date (YYYY-MM-DD)" autocomplete="off">
                <input type="text" wire:model.live.debounce.500ms="dateTo" class="form-input" style="max-width:11rem" placeholder="To date 2026-09-30" aria-label="To date (YYYY-MM-DD)" autocomplete="off">
            @endif
            @if (in_array($trend, ['t7', 't8'], true))
                <select wire:model.live="month" class="form-input" aria-label="Month">
                    <option value="">Latest month with an upload</option>
                    @foreach ($monthOptions as $value => $label)<option value="{{ $value }}" @selected($r['month'] === $value)>{{ $label }}</option>@endforeach
                </select>
            @endif
            @if (in_array($trend, ['t2', 't6'], true))
                <select wire:model.live="kind" class="form-input" aria-label="Which report">
                    <option value="all">All reports</option>
                    <option value="reading">Reading by route</option>
                    <option value="billing">Detailed billing</option>
                    <option value="customers">Customer list</option>
                </select>
            @endif
            @if (count($r['districts']) > 1 || $r['district_id'])
                <select wire:model.live="district" class="form-input" aria-label="District">
                    <option value="">{{ $r['district_limited'] ? 'Your districts' : 'All districts' }}</option>
                    @foreach ($r['districts'] as $id => $name)<option value="{{ $id }}" @selected($id === $r['district_id'])>{{ $name }}</option>@endforeach
                </select>
            @endif
            @if (in_array($trend, ['t2', 't4', 't6', 't7'], true))
                <input type="text" wire:model.live.debounce.500ms="route" class="form-input" style="max-width:9rem" placeholder="Route id" aria-label="Route id" inputmode="numeric" autocomplete="off">
                @if ($r['named'])
                    <input type="text" wire:model.live.debounce.500ms="cca" class="form-input" style="max-width:9rem" placeholder="CCA staff id" aria-label="CCA staff id" autocomplete="off">
                @endif
            @endif
            @if ($dateFrom !== '' || $dateTo !== '' || $r['district_id'] || $route !== '' || $cca !== '' || $month !== '' || $kind !== 'all')
                <button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">Clear filters</button>
            @endif
        </div>

        @if ($r['hidden'] !== [])
            <div class="ui-hint dash-row" data-hidden-line>
                <strong>Hidden from you:</strong>
                @foreach ($r['hidden'] as $h){{ $h['title'] }} ({{ $h['reason'] }}){{ ! $loop->last ? ' ' : '' }}@endforeach
            </div>
        @endif

        @if (! $r['any'])
            <x-ui.card><x-ui.empty-state icon="trending-up" title="No upload to compare yet." description="None of the reports you may see has an upload for this region." /></x-ui.card>
        @else
            @include('livewire.commercial.trends.'.($trend === 't6' ? 't2' : $trend))
        @endif
    @endif
</div>
