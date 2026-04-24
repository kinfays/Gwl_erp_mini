<div class="space-y-4">

    <div class="bg-white border rounded-xl p-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold">My Leave History</h2>
            <p class="text-sm text-slate-600">
                @if($includePast36Months)
                    Showing the last 36 months.
                @else
                    Showing current year requests.
                @endif
            </p>
        </div>

        <div class="flex gap-2">
            <button wire:click="togglePast" class="px-4 py-2 rounded bg-slate-100 text-sm">
                {{ $includePast36Months ? 'Show Current Year' : 'Show Past 36 Months' }}
            </button>
            <a href="{{ route('leave.apply') }}" class="px-4 py-2 rounded bg-blue-600 text-white text-sm">
                + Apply
            </a>
        </div>
    </div>

    @if ($errors->has('action'))
        <div class="p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700">
            {{ $errors->first('action') }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($requests as $r)
            <div class="bg-white border rounded-xl p-5 space-y-2">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm text-slate-500">Leave Type</div>
                        <div class="text-base font-semibold">{{ $r->leave_type }}</div>
                    </div>

                    <span class="text-xs px-2 py-1 rounded bg-slate-100">
                        {{ $r->leave_status }}
                    </span>
                </div>

                <div class="text-sm text-slate-700">
                    <div><span class="text-slate-500">Dates:</span>
                        {{ $r->start_date->format('d M Y') }} → {{ $r->end_date->format('d M Y') }}
                    </div>
                    <div><span class="text-slate-500">Days:</span> {{ $r->total_days_applied }}</div>
                </div>

                <div class="pt-2 flex flex-wrap gap-2">
                    <button wire:click="viewRequest({{ $r->id }})"
                            class="px-3 py-1.5 rounded bg-slate-100 text-xs">
                        View
                    </button>

                    @if(in_array($r->leave_status, ['Planned','Pending Approval']))
                        <button wire:click="editRequest({{ $r->id }})"
                                class="px-3 py-1.5 rounded bg-blue-50 text-blue-700 text-xs border border-blue-200">
                            Edit
                        </button>
                    @endif

                    @if($r->leave_status === 'Planned')
                        <button wire:click="deletePlanned({{ $r->id }})"
                                onclick="return confirm('Delete this planned request?')"
                                class="px-3 py-1.5 rounded bg-red-50 text-red-700 text-xs border border-red-200">
                            Delete
                        </button>
                    @endif

                    @if($r->leave_status === 'Denied')
                        <button wire:click="reopenDenied({{ $r->id }})"
                                class="px-3 py-1.5 rounded bg-amber-50 text-amber-800 text-xs border border-amber-200">
                            Re-open
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <div class="md:col-span-2 xl:col-span-3 bg-white border rounded-xl p-8 text-center text-slate-500">
                No leave requests found.
            </div>
        @endforelse
    </div>

    <div>
        {{ $requests->links() }}
    </div>

    {{-- Drawer --}}
    @if($showDrawer && $selectedRequest)
        <div class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-black/40" wire:click="closeDrawer"></div>

            <div class="absolute right-0 top-0 h-full w-full max-w-xl bg-white shadow-xl border-l p-6 overflow-y-auto">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">Leave Request Details</h3>
                        <p class="text-sm text-slate-600">Read-only view</p>
                    </div>
                    <button wire:click="closeDrawer" class="text-2xl text-slate-500 hover:text-slate-800">&times;</button>
                </div>

                <div class="mt-6 space-y-3 text-sm">
                    <div><span class="text-slate-500">Type:</span> {{ $selectedRequest->leave_type }}</div>
                    <div><span class="text-slate-500">Status:</span> {{ $selectedRequest->leave_status }}</div>
                    <div><span class="text-slate-500">Dates:</span>
                        {{ $selectedRequest->start_date->format('d M Y') }} → {{ $selectedRequest->end_date->format('d M Y') }}
                    </div>
                    <div><span class="text-slate-500">Working Days:</span> {{ $selectedRequest->total_days_applied }}</div>

                    <div class="pt-2">
                        <div class="text-slate-500 mb-1">Reason</div>
                        <div class="p-3 bg-slate-50 border rounded">{{ $selectedRequest->leave_details ?: '—' }}</div>
                    </div>

                    <div class="pt-2">
                        <div class="text-slate-500 mb-1">Manager</div>
                        <div class="p-3 bg-slate-50 border rounded">
                            {{ $selectedRequest->manager?->full_name ?? '—' }} <br>
                            <span class="text-xs text-slate-500">Recommendation: {{ $selectedRequest->manager_recommendation }}</span><br>
                            <span class="text-xs text-slate-500">Comment: {{ $selectedRequest->manager_comments ?: '—' }}</span>
                        </div>
                    </div>

                    <div class="pt-2">
                        <div class="text-slate-500 mb-1">Final Approver</div>
                        <div class="p-3 bg-slate-50 border rounded">
                            {{ $selectedRequest->approvedBy?->full_name ?? '—' }} <br>
                            <span class="text-xs text-slate-500">Comment: {{ $selectedRequest->chiefManager_comments ?: '—' }}</span>
                        </div>
                    </div>

                    @if($selectedRequest->file_attachment)
                        <div class="pt-2">
                            <div class="text-slate-500 mb-1">Attachment</div>
                            <a class="text-blue-600 underline"
                               href="{{ asset('storage/' . $selectedRequest->file_attachment) }}"
                               target="_blank"
                               rel="noopener">
                                View attachment
                            </a>
                        </div>
                    @endif

                <div class="mt-6 flex flex-wrap gap-2">
                    @if(in_array($selectedRequest->leave_status, ['Planned','Pending Approval']))
                        <button wire:click="editRequest({{ $selectedRequest->id }})"
                                class="px-4 py-2 rounded bg-blue-600 text-white text-sm">
                            Edit
                        </button>
                    @endif

                    @if($selectedRequest->leave_status === 'Planned')
                        <button wire:click="deletePlanned({{ $selectedRequest->id }})"
                                onclick="return confirm('Delete this planned request?')"
                                class="px-4 py-2 rounded bg-red-600 text-white text-sm">
                            Delete
                        </button>
                    @endif

                    @if($selectedRequest->leave_status === 'Denied')
                        <button wire:click="reopenDenied({{ $selectedRequest->id }})"
                                class="px-4 py-2 rounded bg-amber-600 text-white text-sm">
                            Re-open
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif

</div>
