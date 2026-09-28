<div wire:poll.45s>
    <x-ui.page-header title="Agent Reports" description="Super admin intake board for endpoint telemetry and manual matching." />

    <x-ui.card title="Recent Device Reports" description="Refreshes every 45 seconds. Unmatched devices are tinted." :padded="false">
        <div class="ui-toolbar" role="search" aria-label="Filter device reports">
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" wire:model.live="search" class="form-input ui-input has-icon" placeholder="Search host, serial, MAC, OS" aria-label="Search device reports">
            </div>
            <select wire:model.live="matchedFilter" class="form-input" aria-label="Match status">
                <option value="all">All</option>
                <option value="matched">Matched</option>
                <option value="unmatched">Unmatched</option>
            </select>
            <select wire:model.live="perPage" class="form-input" aria-label="Rows per page">
                <option value="20">20 per page</option>
                <option value="50">50 per page</option>
                <option value="100">100 per page</option>
            </select>
        </div>

        <div class="ui-loading-host">
            <x-ui.table label="Device reports" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Hostname</th>
                        <th>Serial</th>
                        <th>Last Seen</th>
                        <th>Matched Asset</th>
                        <th>OS / CPU / RAM</th>
                        <th>Region</th>
                        <th class="actions">Action</th>
                    </tr>
                </x-slot:head>

                @forelse ($reports as $report)
                    <tr wire:key="agent-report-{{ $report->id }}" @class(['is-flagged' => ! $report->matched])>
                        <td>
                            <span class="ui-cell-stack">
                                <span class="ui-person-name">{{ $report->hostname ?: 'Unknown host' }}</span>
                                <span class="ui-person-sub mono">{{ $report->mac_address ?: 'No MAC' }}</span>
                            </span>
                        </td>
                        <td @class(['mono', 'cell-muted' => ! $report->serial_number])>{{ $report->serial_number ?: 'No serial' }}</td>
                        <td class="nowrap">{{ $report->reported_at?->diffForHumans() ?: '-' }}</td>
                        <td>
                            @if ($report->asset)
                                <span class="ui-cell-stack">
                                    <span>{{ $report->asset->asset_name }}</span>
                                    <span class="ui-person-sub mono">{{ $report->asset->serial_number ?: 'No serial' }}</span>
                                </span>
                            @else
                                <x-ui.status-pill tone="warning" label="Unmatched" />
                            @endif
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $report->os_name ?: '-' }} {{ $report->os_version ?: '' }}</span>
                                <span class="ui-person-sub">{{ $report->cpu_name ?: '-' }} · {{ $report->ram_gb ? $report->ram_gb.' GB' : '-' }}</span>
                            </span>
                        </td>
                        <td @class(['cell-muted' => ! $report->region])>{{ $report->region?->region_name ?: '-' }}</td>
                        <td class="actions">
                            @if (! $report->matched || $linkingReportId === $report->id)
                                @if ($linkingReportId === $report->id)
                                    <div class="row-actions link-picker">
                                        <label for="link-asset-{{ $report->id }}" class="sr-only-text">Asset to link to {{ $report->hostname ?: 'this device' }}</label>
                                        <select id="link-asset-{{ $report->id }}" wire:model="linkAssetId" class="form-input">
                                            <option value="">Select asset</option>
                                            @foreach ($assets as $asset)
                                                <option value="{{ $asset->id }}">
                                                    {{ $asset->asset_name }} ({{ $asset->serial_number ?: ($asset->hostname ?: 'no-id') }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="button" wire:click="linkReport" class="btn btn-primary btn-sm">Link</button>
                                        <button type="button" wire:click="cancelLink" class="btn btn-ghost btn-sm">Cancel</button>
                                    </div>
                                @else
                                    <button type="button" wire:click="startLink({{ $report->id }})" class="btn btn-sm">
                                        <x-ui.icon name="layers" class="icon-sm" />
                                        Link Asset
                                    </button>
                                @endif
                            @else
                                <span class="ui-hint">Matched</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="7" icon="radar" title="No agent reports found." description="Devices appear here once their agent checks in." />
                @endforelse

                @if ($reports->hasPages())
                    <x-slot:footer>
                        <div class="pager-end">{{ $reports->links() }}</div>
                    </x-slot:footer>
                @endif
            </x-ui.table>

            <div wire:loading.delay wire:target="search,matchedFilter,perPage,gotoPage,nextPage,previousPage,setPage" class="table-skeleton">
                <span class="skeleton-line"></span>
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
            </div>
        </div>
    </x-ui.card>
</div>
