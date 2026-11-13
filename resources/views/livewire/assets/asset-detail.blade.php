<div>
    @php
        $fmt = fn ($date) => $date?->format('d M Y') ?: '—';
        $dash = fn ($value) => filled($value) ? $value : '—';
    @endphp

    <x-ui.page-header :title="$asset->asset_name" :description="$typeLabel.' · '.($asset->serial_number ?: 'No serial number')">
        <x-slot:actions>
            <x-ui.status-pill domain="asset" :status="$asset->status" />
            <a href="{{ route($listRoute, ['q' => $asset->serial_number ?: $asset->asset_name]) }}" class="btn btn-secondary">Open in list to edit</a>
            <a href="{{ route($listRoute) }}" class="btn btn-ghost">Back to list</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-grid ui-grid-2 dash-row">
        <x-ui.card title="Overview">
            <dl class="ui-dl">
                <div><dt>Type</dt><dd>{{ $typeLabel }}</dd></div>
                <div><dt>Model</dt><dd>{{ $asset->assetModel ? trim(($asset->assetModel->manufacturer?->name ?? '').' '.$asset->assetModel->name) : '—' }}</dd></div>
                <div><dt>Serial number</dt><dd class="mono">{{ $dash($asset->serial_number) }}</dd></div>
                <div><dt>Asset tag</dt><dd class="mono">{{ $dash($asset->tag_no) }}</dd></div>
                <div><dt>Status</dt><dd>{{ $asset->status }}@if ($asset->status_reason) &mdash; {{ $asset->status_reason }}@endif</dd></div>
                @if (App\Models\IctAsset::supportsUps($asset->device_category, $asset->asset_type))
                    <div><dt>UPS</dt><dd>{{ $asset->has_ups ? ($asset->ups_status ?: 'Yes') : 'No UPS' }}@if ($asset->has_ups && $asset->ups_notes) <span class="ui-person-sub">{{ $asset->ups_notes }}</span>@endif</dd></div>
                @endif
                <div><dt>Location</dt><dd>{{ $asset->district?->district_name ?: 'No district' }}@if ($asset->region) <span class="ui-person-sub">{{ $asset->region->region_name }}</span>@endif</dd></div>
                <div><dt>Department</dt><dd>{{ $dash($asset->department?->department_name) }}</dd></div>
                <div>
                    <dt>Assigned to</dt>
                    <dd>
                        @if ($asset->assignedTo)
                            <a href="{{ route('assets.employee', $asset->assignedTo) }}">{{ $asset->assignedTo->full_name }}</a>
                        @elseif ($asset->is_general_use)
                            <x-ui.badge tone="lagoon">General use</x-ui.badge>
                        @else
                            Unassigned
                        @endif
                    </dd>
                </div>
                <div><dt>Previously assigned to</dt><dd>{{ $dash($asset->previousHolder()?->full_name) }}</dd></div>
                @if ($asset->notes)
                    <div><dt>Notes</dt><dd>{{ $asset->notes }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.card title="Lifecycle">
            <dl class="ui-dl">
                <div><dt>Purchased</dt><dd>{{ $fmt($asset->purchased_at) }}@if ($ageYears !== null) <span class="ui-person-sub">{{ $ageYears }} {{ \Illuminate\Support\Str::plural('year', $ageYears) }} old</span>@endif</dd></div>
                <div><dt>Warranty expires</dt><dd>{{ $fmt($asset->warranty_expires_at) }}@if ($warrantyState) <span class="ui-person-sub">{{ $warrantyState }}</span>@endif</dd></div>
                <div>
                    <dt>Replacement due</dt>
                    <dd>
                        {{ $fmt($replacementDue) }}
                        <span class="ui-person-sub">
                            @if ($replacementDue)
                                Policy: {{ $replacementYears }} years{{ $replacementOverdue ? ' · overdue' : '' }}
                            @else
                                Needs a purchase date
                            @endif
                        </span>
                    </dd>
                </div>
                <div><dt>Maintenance tickets</dt><dd>{{ $maintenanceTotal }}</dd></div>
                <div><dt>Added</dt><dd>{{ $fmt($asset->created_at) }}</dd></div>
                <div><dt>Last updated</dt><dd>{{ $fmt($asset->updated_at) }}</dd></div>
            </dl>
        </x-ui.card>
    </div>

    <x-ui.card :title="$asset->device_category === 'phone' ? 'Phone details' : ($asset->device_category === 'network' ? 'Network details' : 'Hardware details')" class="dash-row">
        <dl class="ui-dl">
            @if ($asset->device_category === 'phone')
                <div><dt>IMEI</dt><dd class="mono">{{ $dash($asset->imei) }}</dd></div>
                <div><dt>User's number</dt><dd class="mono">{{ $dash($asset->user_phone_number) }}</dd></div>
                <div><dt>Device number</dt><dd class="mono">{{ $dash($asset->device_phone_number) }}</dd></div>
            @elseif ($asset->device_category === 'network')
                <div><dt>Device IP</dt><dd class="mono">{{ $dash($asset->device_ip) }}</dd></div>
                <div><dt>SSID</dt><dd>{{ $dash($asset->ssid) }}</dd></div>
                <div><dt>Login user</dt><dd>{{ $dash($asset->device_username) }}</dd></div>
                <div><dt>Physical location</dt><dd>{{ $dash($asset->actual_location) }}</dd></div>
                <div><dt>Passwords</dt><dd><span class="ui-hint">Not shown here. Use the Network list.</span></dd></div>
            @else
                <div><dt>Hostname</dt><dd class="mono">{{ $dash($asset->hostname) }}</dd></div>
                <div><dt>IP address</dt><dd class="mono">{{ $dash($asset->device_ip) }}</dd></div>
                <div><dt>MAC address</dt><dd class="mono">{{ $dash($asset->mac_address) }}</dd></div>
                <div><dt>Operating system</dt><dd>{{ $dash(trim($asset->os_name.' '.$asset->os_version)) }}</dd></div>
                <div><dt>Processor</dt><dd>{{ $dash($asset->cpu_name) }}</dd></div>
                <div><dt>Physical location</dt><dd>{{ $dash($asset->actual_location) }}</dd></div>
                <div><dt>Memory</dt><dd>{{ $asset->ram_gb ? rtrim(rtrim($asset->ram_gb, '0'), '.').' GB' : '—' }}</dd></div>
                <div><dt>Last seen by agent</dt><dd>{{ $fmt($asset->agent_last_report_at) }}</dd></div>
            @endif
        </dl>
    </x-ui.card>

    <x-ui.card title="Assignment history" description="Everyone who has held this device, newest first. A correction is an entry that was picked by mistake; it is never shown as the previous holder." :padded="false" class="dash-row">
        <x-ui.table label="Assignment history" :sticky="false">
            <x-slot:head>
                <tr>
                    <th>Person</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Reason</th>
                    <th>Recorded by</th>
                    @if ($canCorrectAssignments)<th><span class="sr-only">Actions</span></th>@endif
                </tr>
            </x-slot:head>
            @forelse ($assignments as $row)
                <tr wire:key="assignment-{{ $row->id }}">
                    <td>{{ $row->employee?->full_name ?: 'A former employee' }}</td>
                    <td class="nowrap">{{ $row->assigned_at?->format('d M Y') ?: 'Not recorded' }}</td>
                    <td class="nowrap">{{ $row->ended_at?->format('d M Y') ?: ($row->is_current ? 'Now' : 'Not recorded') }}</td>
                    <td>
                        @if ($row->is_current)
                            <x-ui.badge tone="success">Current</x-ui.badge>
                        @elseif ($row->isCorrected())
                            <x-ui.badge tone="warning">Corrected</x-ui.badge>
                            <span class="ui-person-sub">Picked by mistake{{ $row->correction_code ? ' · '.(\App\Models\IctAssetAssignment::CORRECTION_CODES[$row->correction_code] ?? '') : '' }}</span>
                        @else
                            {{ $row->reasonLabel() }}
                        @endif
                        @if ($row->is_backfilled)
                            <span class="ui-person-sub">From earlier records</span>
                        @endif
                    </td>
                    <td>{{ $row->recordedBy?->full_name ?: '—' }}</td>
                    @if ($canCorrectAssignments)
                        <td class="nowrap">
                            @if (in_array($row->end_reason, \App\Models\IctAssetAssignment::PREVIOUS_REASONS, true))
                                <button type="button" class="btn btn-ghost btn-sm" wire:click="startCorrecting({{ $row->id }})">Mark as correction</button>
                            @endif
                        </td>
                    @endif
                </tr>
                @if ($canCorrectAssignments && $correctingId === $row->id)
                    <tr wire:key="assignment-correct-{{ $row->id }}">
                        <td colspan="6">
                            <div class="ui-form-grid">
                                <x-ui.select label="Why is this a correction?" wire:model="correctionCode" required>
                                    <option value="">Select a reason</option>
                                    @foreach ($correctionCodes as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </x-ui.select>
                                <div class="ui-actions">
                                    <button type="button" class="btn btn-primary btn-sm" wire:click="confirmCorrecting">Mark as correction</button>
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelCorrecting">Cancel</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endif
            @empty
                <x-ui.empty-row :colspan="$canCorrectAssignments ? 6 : 5" icon="history" title="No assignments recorded yet." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <div class="ui-grid ui-grid-2 dash-row">
        <x-ui.card title="History" description="Changes to holder, status and location, newest first." :padded="false">
            <x-ui.table label="Asset history" :sticky="false">
                <x-slot:head><tr><th>Change</th><th>Date</th></tr></x-slot:head>
                @forelse ($transfers as $transfer)
                    <tr wire:key="transfer-{{ $transfer->id }}">
                        <td>{{ $transfer->summary() }}</td>
                        <td class="nowrap">{{ $fmt($transfer->occurred_at) }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="2" icon="history" title="No changes recorded yet." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Stock checks" description="Results from physical verification audits." :padded="false">
            <x-ui.table label="Audit results for this asset" :sticky="false">
                <x-slot:head><tr><th>Audit</th><th>Result</th><th>Date</th></tr></x-slot:head>
                @forelse ($auditLines as $line)
                    <tr wire:key="audit-line-{{ $line->id }}">
                        <td>{{ $line->audit?->title }}</td>
                        <td>
                            <x-ui.status-pill :tone="\App\Models\IctAssetAuditLine::toneFor($line->result)" :label="\App\Models\IctAssetAuditLine::labelFor($line->result)" />
                            @if ($line->mismatch_reason)<span class="ui-person-sub">{{ $line->mismatch_reason }}</span>@endif
                        </td>
                        <td class="nowrap">{{ $fmt($line->verified_at) }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="3" icon="clipboard-list" title="Never audited." />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    </div>

    <x-ui.card title="Maintenance" :description="$maintenanceTotal.' ticket'.($maintenanceTotal === 1 ? '' : 's').' in total'" :padded="false" class="dash-row">
        <x-ui.table label="Maintenance tickets" :sticky="false">
            <x-slot:head><tr><th>Type</th><th>Status</th><th>Technician</th><th>Opened</th><th>Completed</th></tr></x-slot:head>
            @forelse ($maintenance as $ticket)
                <tr wire:key="maint-{{ $ticket->id }}">
                    <td>{{ $ticket->maintenance_type }}</td>
                    <td><x-ui.status-pill domain="maintenance" :status="$ticket->status" /></td>
                    <td>{{ $dash($ticket->technician) }}</td>
                    <td class="nowrap">{{ $fmt($ticket->created_at) }}</td>
                    <td class="nowrap">{{ $fmt($ticket->completion_date) }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" icon="wrench" title="No maintenance tickets." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Reported issues" :padded="false">
        <x-ui.table label="Issue reports linked to this asset" :sticky="false">
            <x-slot:head><tr><th>Issue</th><th>Type</th><th>Status</th><th>Reported</th><th>Solved</th></tr></x-slot:head>
            @forelse ($issues as $issue)
                <tr wire:key="issue-{{ $issue->id }}">
                    <td>{{ $issue->title }}</td>
                    <td>{{ $issue->issue_type }}</td>
                    <td><x-ui.status-pill domain="issue" :status="$issue->status" /></td>
                    <td class="nowrap">{{ $fmt($issue->created_at) }}</td>
                    <td class="nowrap">{{ $fmt($issue->date_solved) }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" icon="triangle-alert" title="No issue reports linked to this asset." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
