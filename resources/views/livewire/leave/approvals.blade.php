<div class="bg-white border rounded-xl p-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-4">
        <div>
            <h2 class="text-lg font-semibold">Approvals</h2>
            <p class="text-sm text-slate-600">Recommend or approve leave requests in your chain.</p>
        </div>

        <div class="flex gap-2">
            <input type="text" wire:model.live="search" placeholder="Search employee name..."
                   class="border rounded px-3 py-2 text-sm">
        </div>
    </div>

    <div class="flex gap-2 mb-4">
        <button wire:click="setTab('pending')" class="px-3 py-2 text-sm rounded {{ $tab === 'pending' ? 'bg-blue-600 text-white' : 'bg-slate-100' }}">Pending</button>
        <button wire:click="setTab('approved')" class="px-3 py-2 text-sm rounded {{ $tab === 'approved' ? 'bg-blue-600 text-white' : 'bg-slate-100' }}">Approved</button>
        <button wire:click="setTab('denied')" class="px-3 py-2 text-sm rounded {{ $tab === 'denied' ? 'bg-blue-600 text-white' : 'bg-slate-100' }}">Denied</button>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left border-b">
                    <th class="py-2">Employee</th>
                    <th class="py-2">Type</th>
                    <th class="py-2">Dates</th>
                    <th class="py-2">Days</th>
                    <th class="py-2">Status</th>
                    <th class="py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($requests as $r)
                    <tr class="border-b">
                        <td class="py-2">
                            <div class="font-medium">{{ $r->requester->full_name }}</div>
                            <div class="text-xs text-slate-500">{{ $r->department->department_name ?? '—' }}</div>
                        </td>
                        <td class="py-2">{{ $r->leave_type }}</td>
                        <td class="py-2">{{ $r->start_date->format('d M') }} - {{ $r->end_date->format('d M Y') }}</td>
                        <td class="py-2">{{ $r->total_days_applied }}</td>
                        <td class="py-2">{{ $r->leave_status }}</td>
                        <td class="py-2">
                            @if($readOnly)
                                <span class="text-xs text-slate-500">Read-only</span>
                            @else
                                <div class="flex flex-wrap gap-2">
                                    {{-- Manager stage --}}
                                    @if($r->manager_recommendation === 'Pending' && $r->leave_status === 'Pending Approval')
                                        <button wire:click="recommend({{ $r->id }})" class="px-2 py-1 rounded bg-emerald-600 text-white text-xs">Recommend</button>
                                        <button wire:click="reject({{ $r->id }})" class="px-2 py-1 rounded bg-red-600 text-white text-xs">Reject</button>
                                    @endif

                                    {{-- Chief stage --}}
                                    @if($r->manager_recommendation === 'Recommended' && $r->leave_status === 'Pending Approval')
                                        <button wire:click="approve({{ $r->id }})" class="px-2 py-1 rounded bg-blue-600 text-white text-xs">Approve</button>
                                        <button wire:click="deny({{ $r->id }})" class="px-2 py-1 rounded bg-red-600 text-white text-xs">Deny</button>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-slate-500">No requests found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $requests->links() }}
    </div>
</div>
