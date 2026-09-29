<div>
    @php
        $totals = $summary['totals'];
        $intake = $summary['intake'];
    @endphp

    <x-ui.page-header title="Mobile Devices" description="Company-owned Android phones managed through Android Enterprise.">
        @if ($canEnroll)
            <x-slot:actions>
                <a href="{{ route('assets.mdm.enroll') }}" class="btn btn-primary">
                    <x-ui.icon name="plus" />
                    Enroll Phone
                </a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Managed phones" :value="$totals['total']" icon="smartphone" tone="primary" />
        <x-ui.stat-tile label="Compliant" :value="$totals['compliant']" icon="circle-check" tone="success" />
        <x-ui.stat-tile label="Non-compliant" :value="$totals['non_compliant']" icon="triangle-alert" :tone="$totals['non_compliant'] ? 'warning' : 'muted'" />
        <x-ui.stat-tile label="Lost" :value="$totals['lost']" icon="map-pin" :tone="$totals['lost'] ? 'danger' : 'muted'" />
        <x-ui.stat-tile label="Needs review" :value="$totals['needs_review']" icon="user-check" :tone="$totals['needs_review'] ? 'warning' : 'muted'" />
        <x-ui.stat-tile label="No report in {{ config('gwl.mdm_stale_report_hours', 24) }}h" :value="$totals['not_reported']" icon="clock" :tone="$totals['not_reported'] ? 'warning' : 'muted'" />
    </div>

    <div class="ui-grid ui-grid-main dash-row">
        <x-ui.card title="Recent enrollments" description="Newest phones to join the fleet." :padded="false">
            <x-ui.table label="Recent enrollments" :sticky="false">
                <x-slot:head>
                    <tr>
                        <th>Phone</th>
                        <th>Assigned to</th>
                        <th>Enrolled</th>
                    </tr>
                </x-slot:head>
                @forelse ($summary['recent_enrollments'] as $enrolled)
                    <tr wire:key="enrolled-{{ $enrolled->id }}">
                        <td>
                            <a href="{{ route('assets.mdm.devices.show', $enrolled->id) }}" class="ui-person-name">{{ $enrolled->asset?->asset_name ?? 'Unlinked device' }}</a>
                        </td>
                        <td @class(['nowrap', 'cell-muted' => ! $enrolled->asset?->assignedTo])>{{ $enrolled->asset?->assignedTo?->full_name ?: 'Unassigned' }}</td>
                        <td class="nowrap">{{ $enrolled->enrolled_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="3" icon="smartphone" title="No phones enrolled yet." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        @if ($intake)
            <x-ui.card title="Event intake" description="How Google's notifications reach the ERP.">
                <dl class="ui-dl">
                    <div>
                        <dt>Mode</dt>
                        <dd>
                            <x-ui.status-pill :tone="$intake['configured'] ? 'success' : 'warning'" :label="strtoupper($intake['mode'])" />
                            @unless ($intake['configured'])
                                <span class="ui-hint">Not fully configured</span>
                            @endunless
                        </dd>
                    </div>
                    <div>
                        <dt>Last event received</dt>
                        <dd>
                            @if ($intake['last_received_at'])
                                {{ $intake['last_received_at']->diffForHumans() }}
                                <span class="ui-hint">{{ $intake['last_type'] }}</span>
                            @else
                                <span class="ui-hint">Nothing received yet</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Failed events</dt>
                        <dd>
                            <x-ui.status-pill :tone="$intake['failed'] ? 'danger' : 'success'" :label="(string) $intake['failed']" />
                        </dd>
                    </div>
                    <div>
                        <dt>Unprocessed</dt>
                        <dd>
                            <x-ui.status-pill :tone="$intake['unprocessed'] ? 'warning' : 'success'" :label="(string) $intake['unprocessed']" />
                            @if ($intake['unprocessed'])
                                <span class="ui-hint">Is the queue worker running?</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-ui.card>
        @endif
    </div>

    <x-ui.card title="Recent commands" description="The latest actions sent to phones." :padded="false" class="dash-row">
        <x-ui.table label="Recent commands" :sticky="false">
            <x-slot:head>
                <tr>
                    <th>Command</th>
                    <th>Phone</th>
                    <th>Requested by</th>
                    <th>Status</th>
                    <th>When</th>
                </tr>
            </x-slot:head>
            @forelse ($summary['recent_commands'] as $command)
                <tr wire:key="command-{{ $command->id }}">
                    <td>{{ $command->typeLabel() }}</td>
                    <td>
                        <a href="{{ route('assets.mdm.devices.show', $command->mdm_device_id) }}">{{ $command->device?->asset?->asset_name ?? 'Unlinked device' }}</a>
                    </td>
                    <td class="nowrap">{{ $command->requester?->full_name ?? 'System' }}</td>
                    <td><x-ui.status-pill domain="mdm-command" :status="$command->status" /></td>
                    <td class="nowrap">{{ $command->requested_at?->diffForHumans() }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" icon="send" title="No commands have been sent yet." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.card :padded="false">
        <div class="ui-toolbar" role="search" aria-label="Filter managed phones">
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" wire:model.live.debounce.300ms="search" class="form-input ui-input has-icon" placeholder="Search name, serial, IMEI, assignee" aria-label="Search managed phones">
            </div>
            <select wire:model.live="filter" class="form-input" aria-label="Show">
                @foreach ($filters as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="perPage" class="form-input" aria-label="Rows per page">
                <option value="15">15 per page</option>
                <option value="30">30 per page</option>
                <option value="50">50 per page</option>
            </select>
        </div>

        <div class="ui-loading-host">
            <x-ui.table label="Managed phones" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Phone</th>
                        <th>Assigned to</th>
                        <th>Policy</th>
                        <th>Compliance</th>
                        <th>Last check-in</th>
                        <th class="actions"><span class="sr-only-text">Open</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($devices as $device)
                    <tr wire:key="device-{{ $device->id }}">
                        <td>
                            <span class="ui-cell-stack">
                                <a href="{{ route('assets.mdm.devices.show', $device->id) }}" class="ui-person-name">{{ $device->asset?->asset_name ?? 'Unlinked device' }}</a>
                                <span class="ui-person-sub mono">
                                    {{ $device->asset?->serial_number ?: ($device->hardware_info['serialNumber'] ?? 'No serial') }}
                                    @if ($device->asset?->district)
                                        · {{ $device->asset->district->district_name }}
                                    @endif
                                </span>
                            </span>
                        </td>
                        <td @class(['nowrap', 'cell-muted' => ! $device->asset?->assignedTo])>{{ $device->asset?->assignedTo?->full_name ?: 'Unassigned' }}</td>
                        <td class="nowrap">{{ $device->policy?->name ?? '—' }}</td>
                        <td>
                            <span class="ui-tags">
                                @if ($device->needs_review)
                                    <x-ui.status-pill tone="warning" label="Needs review" />
                                @endif
                                @if ($device->is_lost)
                                    <x-ui.status-pill tone="danger" label="Lost" />
                                @endif
                                @if ($device->policy_compliant === null)
                                    <x-ui.status-pill tone="muted" label="Awaiting report" />
                                @elseif ($device->policy_compliant)
                                    <x-ui.status-pill tone="success" label="Compliant" />
                                @else
                                    <x-ui.status-pill tone="warning" label="Non-compliant" />
                                @endif
                            </span>
                        </td>
                        <td @class(['nowrap', 'cell-muted' => ! $device->last_status_report_at || $device->last_status_report_at->lt($staleBefore)])>
                            {{ $device->last_status_report_at?->diffForHumans() ?? 'Never' }}
                        </td>
                        <td class="actions">
                            <a href="{{ route('assets.mdm.devices.show', $device->id) }}" class="btn btn-ghost btn-sm btn-icon" title="Open" aria-label="Open {{ $device->asset?->asset_name ?? 'device' }}">
                                <x-ui.icon name="chevron-right" />
                            </a>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="6" icon="smartphone" title="No managed phones found." description="Enroll a phone, or clear the filters." />
                @endforelse

                <x-slot:footer>
                    <p class="pager-summary">
                        Showing {{ $devices->firstItem() ?? 0 }} - {{ $devices->lastItem() ?? 0 }} of {{ $devices->total() }} phones
                    </p>
                    <div>{{ $devices->links() }}</div>
                </x-slot:footer>
            </x-ui.table>

            <div wire:loading.delay class="table-skeleton">
                <span class="skeleton-line"></span>
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
            </div>
        </div>
    </x-ui.card>
</div>
