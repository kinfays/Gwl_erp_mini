<div class="space-y-4">
    <div class="bg-white border rounded-xl p-6">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">All Leave Requests</h2>
                <p class="text-sm text-slate-600">
                    Search and filter leave requests.
                    @if($readOnly)
                        <span class="ml-2 text-xs bg-slate-100 px-2 py-1 rounded">HR view (read-only)</span>
                    @endif
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <input type="text" wire:model.live="search" placeholder="Search employee name..."
                       class="border rounded px-3 py-2 text-sm">

                <select wire:model.live="leaveType" class="border rounded px-3 py-2 text-sm">
                    <option value="">All Types</option>
                    <option value="Annual">Annual</option>
                    <option value="Casual">Casual</option>
                    <option value="Paternity">Paternity</option>
                    <option value="Maternity">Maternity</option>
                    <option value="Sick">Sick</option>
                </select>

                <select wire:model.live="departmentId" class="border rounded px-3 py-2 text-sm">
                    <option value="">All Departments</option>
                    @foreach($departments as $d)
                        <option value="{{ $d->id }}">{{ $d->department_name }}</option>
                    @endforeach
                </select>

                <input type="date" wire:model.live="dateFrom" class="border rounded px-3 py-2 text-sm">
                <input type="date" wire:model.live="dateTo" class="border rounded px-3 py-2 text-sm">
            </div>
        </div>

        <div class="flex gap-2 mt-4">
            <button wire:click="setTab('all')" class="px-3 py-2 text-sm rounded {{ $tab === 'all' ? 'bg-blue-600 text-white' : 'bg-slate-100' }}">All</button>
            <button wire:click="setTab('pending')" class="px-3 py-2 text-sm rounded {{ $tab === 'pending' ? 'bg-blue-600 text-white' : 'bg-slate-100' }}">Pending</button>
            <button wire:click="setTab('approved')" class="px-3 py-2 text-sm rounded {{ $tab === 'approved' ? 'bg-blue-600 text-white' : 'bg-slate-100' }}">Approved</button>
            <button wire:click="setTab('denied')" class="px-3 py-2 text-sm rounded {{ $tab === 'denied' ? 'bg-blue-600 text-white' : 'bg-slate-100' }}">Denied</button>
        </div>
    </div>

    <div class="bg-white border rounded-xl overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50">
                <tr class="text-left border-b">
                    <th class="px-4 py-3">Employee</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Dates</th>
                    <th class="px-4 py-3">Days</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Region/District</th>
                </tr>
            </thead>
            <tbody>
                @forelse($requests as $r)
                    <tr class="border-b hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $r->requester->full_name }}</div>
                            <div class="text-xs text-slate-500">{{ $r->department->department_name ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $r->leave_type }}</td>
                        <td class="px-4 py-3">
                            {{ $r->start_date->format('d M Y') }} → {{ $r->end_date->format('d M Y') }}
                        </td>
                        <td class="px-4 py-3">{{ $r->total_days_applied }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs rounded bg-slate-100">
                                {{ $r->leave_status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-600">
                            {{ $r->requester->region->region_name ?? '—' }} /
                            {{ $r->requester->district->district_name ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                            No requests found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $requests->links() }}
    </div>
</div>