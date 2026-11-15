<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    @php
        $pct = fn ($value, $digits = 1) => $value === null ? '–' : number_format($value, $digits).'%';
        $num = fn ($value) => $value === null ? '–' : number_format($value);
        $money = fn ($value) => $value === null ? '–' : number_format($value, 2);
        $parts = $r['parts'];
    @endphp

    <x-ui.page-header :title="$r['code'].' · '.$r['title']" :description="$r['question']">
        <x-slot:actions>
            @if ($canExport && $r['available'])
                <a href="{{ route('commercial.export', ['report' => 'cross-'.$analysis, 'format' => 'excel', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.file-icon type="excel" /> Excel</a>
                <a href="{{ route('commercial.export', ['report' => 'cross-'.$analysis, 'format' => 'pdf', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.file-icon type="pdf" /> PDF</a>
            @endif
            <a href="{{ route('commercial.cross') }}" class="btn btn-ghost">All analyses</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($r['region_id'] === null)
        <x-ui.card>
            <x-ui.empty-state icon="layers" title="Nothing has been loaded yet." description="Upload the reading report by route, the Detailed Billing Report or the customer list, and the analyses appear here." />
        </x-ui.card>
    @else
        <div class="ui-toolbar dash-row" role="search" aria-label="Across the files range and filters">
            @if (count($r['regions']) > 1)
                <select wire:model.live="region" class="form-input" aria-label="Region">
                    @foreach ($r['regions'] as $id => $name)<option value="{{ $id }}" @selected($id === $r['region_id'])>{{ $name }}</option>@endforeach
                </select>
            @endif
            <select wire:model.live="preset" class="form-input" aria-label="A whole month">
                <option value="">A whole month…</option>
                @foreach ($monthOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <input type="text" wire:model.live.debounce.500ms="dateFrom" class="form-input" style="max-width:11rem" placeholder="From date 2026-09-01" aria-label="From date (YYYY-MM-DD)" autocomplete="off">
            <input type="text" wire:model.live.debounce.500ms="dateTo" class="form-input" style="max-width:11rem" placeholder="To date 2026-09-30" aria-label="To date (YYYY-MM-DD)" autocomplete="off">
            <select wire:model.live="mode" class="form-input" aria-label="Which uploads each part uses">
                <option value="latest" @selected($r['mode'] === 'latest')>Latest of each part</option>
                <option value="same" @selected($r['mode'] === 'same')>The same range for every part</option>
            </select>
            @if (count($r['districts']) > 1 || $r['district_id'])
                <select wire:model.live="district" class="form-input" aria-label="District">
                    <option value="">{{ $r['district_limited'] ? 'Your districts' : 'All districts' }}</option>
                    @foreach ($r['districts'] as $id => $name)<option value="{{ $id }}" @selected($id === $r['district_id'])>{{ $name }}</option>@endforeach
                </select>
            @endif
            @if ($dateFrom !== '' || $dateTo !== '' || $r['district_id'] || $r['mode'] === 'same')
                <button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">Clear filters</button>
            @endif
        </div>

        {{-- the period each part used: no figure below can be read without it --}}
        <x-ui.card title="The period each part used" class="dash-row" data-windows>
            <table class="ui-table is-dense">
                <thead><tr><th>Part</th><th>Period used</th><th>Needs</th></tr></thead>
                <tbody>
                    @foreach ($r['windows'] as $w)
                        <tr data-window="{{ $w['source'] }}">
                            <td>{{ $w['title'] }}</td>
                            <td>
                                @if (! $w['allowed'])
                                    <span class="ui-hint">Hidden: you do not have the permission for it.</span>
                                @elseif ($w['available'])
                                    {{ $w['sentence'] }}
                                @else
                                    <span class="ui-hint">Not available: {{ $w['reason'] }}</span>
                                @endif
                            </td>
                            <td>{{ $w['source'] === 'link' ? 'billing and customer list' : ($w['permission'] ? str_replace('commercial.', '', $w['permission']) : '–') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <ul style="padding-left:18px;list-style:disc;margin-top:8px">@foreach ($r['notes'] as $note)<li class="ui-hint">{{ $note }}</li>@endforeach</ul>
            @foreach ($r['windows'] as $w)
                @foreach ($w['notes'] ?? [] as $note)<p class="ui-hint">{{ $w['title'] }}: {{ $note }}</p>@endforeach
            @endforeach
        </x-ui.card>

        @if ($r['hidden'] !== [])
            <div class="ui-hint dash-row" data-hidden-line>
                <strong>Hidden from you:</strong>
                @foreach ($r['hidden'] as $h){{ $h['title'] }} ({{ $h['reason'] }}){{ ! $loop->last ? ' ' : '' }}@endforeach
            </div>
        @endif

        @include('livewire.commercial.cross.'.$analysis)
    @endif
</div>
