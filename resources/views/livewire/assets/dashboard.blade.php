<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>ICT Asset Dashboard</h2>
            <p>Live visibility across inventory, maintenance, and agent health.</p>
        </div>
        <div class="ph-right">
            <a href="{{ route('assets.inventory') }}" class="btn btn-primary">Open Inventory</a>
        </div>
    </div>

    <div class="stats" style="margin-top:14px">
        <div class="stat">
            <div class="stat-lbl">Total Assets</div>
            <div class="stat-val">{{ $stats['total'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Active</div>
            <div class="stat-val">{{ $stats['active'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">In Repair</div>
            <div class="stat-val">{{ $stats['in_repair'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Unassigned</div>
            <div class="stat-val">{{ $stats['unassigned'] }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Seen in 24h</div>
            <div class="stat-val">{{ $stats['recently_seen'] }}</div>
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Status Breakdown</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($statusBreakdown as $row)
                    <tr>
                        <td>{{ $row->status ?: 'Unspecified' }}</td>
                        <td>{{ $row->total }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" style="text-align:center;color:var(--color-text-secondary);padding:18px">
                            No assets found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">District Summary</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>District</th>
                    <th>Total Assets</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($districtBreakdown as $row)
                    <tr>
                        <td>{{ $row->district_name }}</td>
                        <td>{{ $row->total }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" style="text-align:center;color:var(--color-text-secondary);padding:18px">
                            No district totals available.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
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

