<div wire:poll.45s>
    <div class="page-head">
        <div class="ph-left">
            <h2>Agent Reports</h2>
            <p>Super admin intake board for endpoint telemetry and manual matching.</p>
        </div>
    </div>

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Recent Device Reports</span>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="text" wire:model.live="search" class="form-input" placeholder="Search host, serial, MAC, OS">
                <select wire:model.live="matchedFilter" class="form-input">
                    <option value="all">All</option>
                    <option value="matched">Matched</option>
                    <option value="unmatched">Unmatched</option>
                </select>
                <select wire:model.live="perPage" class="form-input">
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Hostname</th>
                    <th>Serial</th>
                    <th>Last Seen</th>
                    <th>Matched Asset</th>
                    <th>OS / CPU / RAM</th>
                    <th>Region</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($reports as $report)
                    <tr @if (! $report->matched) style="background:#fff7ed" @endif>
                        <td>
                            <div>{{ $report->hostname ?: 'Unknown host' }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">{{ $report->mac_address ?: 'No MAC' }}</div>
                        </td>
                        <td>{{ $report->serial_number ?: 'No serial' }}</td>
                        <td>{{ $report->reported_at?->diffForHumans() ?: '-' }}</td>
                        <td>
                            @if ($report->asset)
                                <div>{{ $report->asset->asset_name }}</div>
                                <div style="font-size:10px;color:var(--color-text-secondary)">{{ $report->asset->serial_number ?: 'No serial' }}</div>
                            @else
                                <span class="pill p-w">Unmatched</span>
                            @endif
                        </td>
                        <td>
                            <div>{{ $report->os_name ?: '-' }} {{ $report->os_version ?: '' }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary)">
                                {{ $report->cpu_name ?: '-' }} | {{ $report->ram_gb ? $report->ram_gb.' GB' : '-' }}
                            </div>
                        </td>
                        <td>{{ $report->region?->region_name ?: '-' }}</td>
                        <td>
                            @if (! $report->matched || $linkingReportId === $report->id)
                                @if ($linkingReportId === $report->id)
                                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                        <select wire:model="linkAssetId" class="form-input" style="min-width:220px">
                                            <option value="">Select asset</option>
                                            @foreach ($assets as $asset)
                                                <option value="{{ $asset->id }}">
                                                    {{ $asset->asset_name }} ({{ $asset->serial_number ?: ($asset->hostname ?: 'no-id') }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="button" wire:click="linkReport" class="actn actn-p">Link</button>
                                        <button type="button" wire:click="cancelLink" class="actn">Cancel</button>
                                    </div>
                                @else
                                    <button type="button" wire:click="startLink({{ $report->id }})" class="actn actn-p">Link Asset</button>
                                @endif
                            @else
                                <span style="font-size:11px;color:var(--color-text-secondary)">Matched</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align:center;color:var(--color-text-secondary);padding:20px">
                            No agent reports found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="display:flex;justify-content:flex-end;padding:10px 14px;border-top:0.5px solid var(--color-border-tertiary)">
            {{ $reports->links() }}
        </div>
    </div>
</div>

