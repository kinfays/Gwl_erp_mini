<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>ICT Asset Dashboard</h2>
            <p>Live visibility across inventory, maintenance, and agent health.</p>
        </div>
        <div class="ph-right">
            <a href="{{ route('assets.assets') }}" class="btn btn-primary">Open Assets</a>
        </div>
    </div>

    <style>
        /* Categorical slots, validated for CVD separation and contrast
           against each theme's own chart surface. */
        .assets-dash-columns {
            --assets-series-1: #2a78d6;
            --assets-series-2: #eb6834;
            --assets-series-3: #1baf7a;
            --assets-series-4: #eda100;
        }
        .dark .assets-dash-columns {
            --assets-series-1: #3987e5;
            --assets-series-2: #d95926;
            --assets-series-3: #199e70;
            --assets-series-4: #c98500;
        }
        .assets-kpi-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-top: 14px; }
        .assets-kpi-card { background: var(--color-background-primary); border: 0.5px solid var(--color-border-tertiary); border-radius: var(--border-radius-lg); padding: 16px; }
        .assets-kpi-title { font-size: 13px; font-weight: 600; color: var(--color-text-secondary); margin-bottom: 6px; }
        .assets-kpi-value { font-size: 30px; font-weight: 600; color: var(--color-text-primary); line-height: 1; }
        .assets-kpi-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
        .assets-kpi-badge { font-size: 10px; font-weight: 600; padding: 3px 8px; border-radius: 10px; background: var(--color-background-tertiary); color: var(--color-text-secondary); }
        .assets-dash-columns { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; margin-top: 14px; align-items: start; }
        .assets-donut-wrap { display: flex; align-items: center; gap: 18px; padding: 16px; flex-wrap: wrap; }
        .assets-donut-legend { display: flex; flex-direction: column; gap: 8px; font-size: 11px; color: var(--color-text-secondary); }
        .assets-donut-swatch { width: 10px; height: 10px; border-radius: 3px; display: inline-block; margin-right: 6px; vertical-align: middle; }
        @media (max-width: 1000px) {
            .assets-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .assets-dash-columns { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .assets-kpi-grid { grid-template-columns: 1fr; }
        }
    </style>

    @php
        $cardMeta = [
            'computers' => 'Computers',
            'laptops' => 'Laptops',
            'printers' => 'Printers',
            'phones' => 'Phones',
            'servers' => 'Servers',
            'network' => 'Network Devices',
        ];
        $allocationTotal = collect($allocation)->sum('total');
        $circumference = 2 * M_PI * 45;
        $donutOffset = 0;
    @endphp

    <div class="assets-kpi-grid">
        @foreach ($cardMeta as $key => $label)
            @php($card = $cards[$key])
            <div class="assets-kpi-card">
                <div class="assets-kpi-title">{{ $label }}</div>
                <div class="assets-kpi-value">{{ $card['total'] }}</div>
                @if (count($card['badges']))
                    <div class="assets-kpi-badges">
                        @foreach ($card['badges'] as $type => $count)
                            <span class="assets-kpi-badge">{{ $type }}: {{ $count }}</span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="assets-dash-columns">
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">District Breakdown</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>District</th>
                        <th>Total Assets</th>
                        <th>Computers</th>
                        <th>Laptops</th>
                        <th>Printers</th>
                        <th>Phones</th>
                        <th>Network</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($districtBreakdown as $row)
                        <tr>
                            <td>{{ $row['district_name'] }}</td>
                            <td>{{ $row['total'] }}</td>
                            <td>{{ $row['computers'] }}</td>
                            <td>{{ $row['laptops'] }}</td>
                            <td>{{ $row['printers'] }}</td>
                            <td>{{ $row['phones'] }}</td>
                            <td>{{ $row['network'] }}</td>
                            <td>
                                <span class="pill {{ $row['status'] === 'OPTIMAL' ? 'p-g' : 'p-a' }}">{{ $row['status'] }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align:center;color:var(--color-text-secondary);padding:18px">
                                No district totals available.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Device Allocation</span>
            </div>
            <div class="assets-donut-wrap">
                <svg viewBox="0 0 120 120" width="140" height="140" style="flex-shrink:0" role="img" aria-label="Device allocation by category">
                    <circle cx="60" cy="60" r="45" fill="none" stroke="var(--color-background-tertiary)" stroke-width="16"></circle>
                    @if ($allocationTotal > 0)
                        @foreach ($allocation as $slice)
                            {{-- 2px surface gap between adjacent segments, never wider than the segment itself. --}}
                            @php($length = $circumference * ($slice['percentage'] / 100))
                            @php($drawn = max($length - 2, 0))
                            <circle
                                cx="60" cy="60" r="45" fill="none"
                                stroke="{{ $slice['color'] }}"
                                stroke-width="16"
                                stroke-dasharray="{{ $drawn }} {{ $circumference - $drawn }}"
                                stroke-dashoffset="{{ -$donutOffset }}"
                                transform="rotate(-90 60 60)"
                            ></circle>
                            @php($donutOffset += $length)
                        @endforeach
                    @endif
                </svg>
                <div class="assets-donut-legend">
                    @foreach ($allocation as $slice)
                        <div>
                            <span class="assets-donut-swatch" style="background:{{ $slice['color'] }}"></span>
                            {{ $slice['label'] }} — {{ $slice['total'] }} ({{ $slice['percentage'] }}%)
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Recently Updated Assets</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Asset</th>
                    <th>District</th>
                    <th>Assigned To</th>
                    <th>Status</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recentAssets as $asset)
                    <tr>
                        <td>
                            <div>{{ $asset->asset_name }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ $asset->serial_number ?: 'No serial' }}</div>
                        </td>
                        <td>{{ $asset->district?->district_name ?: 'Unassigned' }}</td>
                        <td>{{ $asset->assignedTo?->full_name ?: 'Unassigned' }}</td>
                        <td>{{ $asset->status ?: 'Unknown' }}</td>
                        <td>{{ $asset->updated_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align:center;color:var(--color-text-secondary);padding:18px">
                            No recent updates yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
