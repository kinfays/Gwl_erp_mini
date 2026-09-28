<div>
    <x-ui.page-header title="All Leave Requests" description="Search and filter leave requests.">
        @if ($readOnly)
            <p><x-ui.badge>HR view (read-only)</x-ui.badge></p>
        @endif
    </x-ui.page-header>

    <x-ui.card :padded="false">
        <div class="ui-toolbar">
            <x-ui.input type="search" wire:model.live="search" placeholder="Search employee name..." icon="search" aria-label="Search employee name" class="toolbar-search" />

            <x-ui.select wire:model.live="leaveType" aria-label="Leave type">
                <option value="">All Types</option>
                <option value="Annual">Annual</option>
                <option value="Casual">Casual</option>
                <option value="Paternity">Paternity</option>
                <option value="Maternity">Maternity</option>
                <option value="Sick">Sick</option>
            </x-ui.select>

            <x-ui.select wire:model.live="departmentId" aria-label="Department">
                <option value="">All Departments</option>
                @foreach ($departments as $d)
                    <option value="{{ $d->id }}">{{ $d->department_name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input type="date" wire:model.live="dateFrom" aria-label="From date" />
            <x-ui.input type="date" wire:model.live="dateTo" aria-label="To date" />
        </div>

        <div class="ui-toolbar toolbar-tabs">
            <div class="tabs" role="group" aria-label="Filter by status">
                <button type="button" wire:click="setTab('all')" @class(['tab', 'active' => $tab === 'all']) aria-pressed="{{ $tab === 'all' ? 'true' : 'false' }}">All</button>
                <button type="button" wire:click="setTab('pending')" @class(['tab', 'active' => $tab === 'pending']) aria-pressed="{{ $tab === 'pending' ? 'true' : 'false' }}">Pending</button>
                <button type="button" wire:click="setTab('approved')" @class(['tab', 'active' => $tab === 'approved']) aria-pressed="{{ $tab === 'approved' ? 'true' : 'false' }}">Approved</button>
                <button type="button" wire:click="setTab('denied')" @class(['tab', 'active' => $tab === 'denied']) aria-pressed="{{ $tab === 'denied' ? 'true' : 'false' }}">Denied</button>
            </div>
        </div>

        <x-ui.table label="Leave requests" pin-first>
            <x-slot:head>
                <tr>
                    <th>Employee</th>
                    <th>Type</th>
                    <th>Dates</th>
                    <th class="num">Days</th>
                    <th>Status</th>
                    <th>Region/District</th>
                </tr>
            </x-slot:head>
            @forelse ($requests as $r)
                <tr>
                    <td>
                        <span class="ui-person">
                            <x-ui.avatar :name="$r->requester->full_name" />
                            <span>
                                <span class="ui-person-name">{{ $r->requester->full_name }}</span>
                                <span class="ui-person-sub">{{ $r->department->department_name ?? '—' }}</span>
                            </span>
                        </span>
                    </td>
                    <td>{{ $r->leave_type }}</td>
                    <td class="nowrap">{{ $r->start_date->format('d M Y') }} – {{ $r->end_date->format('d M Y') }}</td>
                    <td class="num">{{ $r->total_days_applied }}</td>
                    <td><x-ui.status-pill domain="leave" :status="$r->leave_status" /></td>
                    <td class="cell-muted">
                        {{ $r->requester->region->region_name ?? '—' }} /
                        {{ $r->requester->district->district_name ?? '—' }}
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="6" icon="list-checks" title="No requests found." description="Try another status tab or clear the filters." />
            @endforelse

            @if ($requests->hasPages())
                <x-slot:footer>
                    {{ $requests->links() }}
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
