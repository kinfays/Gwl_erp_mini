<div>
    <x-ui.page-header title="All Leave Requests" description="Search and filter leave requests.">
        @if ($readOnly)
            <p><x-ui.badge>HR view (read-only)</x-ui.badge></p>
        @endif
    </x-ui.page-header>

    <x-ui.card :padded="false">
        <div role="search" aria-label="Filter leave requests">
            <div class="ui-toolbar filter-bar">
                <div class="ui-input-wrap filter-search">
                    <x-ui.icon name="search" class="ui-input-icon" />
                    <input type="search" wire:model.live="search" placeholder="Search employee name..." aria-label="Search employee name" class="form-input ui-input has-icon">
                </div>

                <select wire:model.live="leaveType" aria-label="Leave type" class="form-input filter-select">
                    <option value="">All Types</option>
                    <option value="Annual">Annual</option>
                    <option value="Casual">Casual</option>
                    <option value="Paternity">Paternity</option>
                    <option value="Maternity">Maternity</option>
                    <option value="Sick">Sick</option>
                </select>

                <select wire:model.live="departmentId" aria-label="Department" class="form-input filter-select">
                    <option value="">All Departments</option>
                    @foreach ($departments as $d)
                        <option value="{{ $d->id }}">{{ $d->department_name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ui-toolbar toolbar-tabs">
                <div class="tabs" role="group" aria-label="Filter by status">
                    <button type="button" wire:click="setTab('all')" @class(['tab', 'active' => $tab === 'all']) aria-pressed="{{ $tab === 'all' ? 'true' : 'false' }}">All</button>
                    <button type="button" wire:click="setTab('pending')" @class(['tab', 'active' => $tab === 'pending']) aria-pressed="{{ $tab === 'pending' ? 'true' : 'false' }}">Pending</button>
                    <button type="button" wire:click="setTab('approved')" @class(['tab', 'active' => $tab === 'approved']) aria-pressed="{{ $tab === 'approved' ? 'true' : 'false' }}">Approved</button>
                    <button type="button" wire:click="setTab('denied')" @class(['tab', 'active' => $tab === 'denied']) aria-pressed="{{ $tab === 'denied' ? 'true' : 'false' }}">Denied</button>
                </div>

                <div class="date-range filter-dates" role="group" aria-label="Leave dates">
                    <input type="date" wire:model.live="dateFrom" aria-label="Starting on or after" class="form-input">
                    <span class="date-range-sep" aria-hidden="true">to</span>
                    <input type="date" wire:model.live="dateTo" aria-label="Ending on or before" class="form-input">
                </div>
            </div>
        </div>

        @php($canViewLetter = fn ($request) => app(\App\Services\Leave\LeaveLetterService::class)->canView(auth()->user(), $request))
        <x-ui.table label="Leave requests" pin-first>
            <x-slot:head>
                <tr>
                    <th>Employee</th>
                    <th>Type</th>
                    <th>Dates</th>
                    <th class="num">Days</th>
                    <th>Status</th>
                    <th>Region/District</th>
                    <th class="actions"><span class="sr-only-text">Letter</span></th>
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
                    <td class="actions">
                        @if ($r->leave_status === 'Approved' && $canViewLetter($r))
                            <x-ui.button size="sm" :href="route('leave.letters.show', $r)" icon="printer">Print Letter</x-ui.button>
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="list-checks" title="No requests found." description="Try another status tab or clear the filters." />
            @endforelse

            @if ($requests->hasPages())
                <x-slot:footer>
                    {{ $requests->links() }}
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
