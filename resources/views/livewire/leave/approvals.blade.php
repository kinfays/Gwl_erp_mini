<div class="space-y-4">
    <div class="bg-white border rounded-xl p-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold">Approvals</h2>
            <p class="text-sm text-slate-600">Pending leave requests in your approval chain.</p>
        </div>

        <div class="flex flex-col sm:flex-row gap-2">
            <span class="px-3 py-2 text-sm rounded-sm bg-blue-600 text-white text-center">Pending</span>
            <input type="text"
                   wire:model.live="search"
                   placeholder="Search employee name..."
                   class="border rounded-sm px-3 py-2 text-sm">
        </div>
    </div>

    @if ($errors->has('action'))
        <div class="p-3 bg-red-50 border border-red-200 rounded-sm text-sm text-red-700">
            {{ $errors->first('action') }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($requests as $r)
            <div class="bg-white border rounded-xl p-5 space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm text-slate-500">Leave Type</div>
                        <div class="text-base font-semibold">{{ $r->leave_type }}</div>
                    </div>

                    <span class="text-xs px-2 py-1 rounded-sm bg-slate-100 text-slate-700 text-right">
                        {{ $r->requester->full_name }}
                    </span>
                </div>

                <div class="text-sm text-slate-700 space-y-1">
                    <div>
                        <span class="text-slate-500">Dates:</span>
                        {{ $r->start_date->format('d M Y') }} - {{ $r->end_date->format('d M Y') }}
                    </div>
                    <div><span class="text-slate-500">Days:</span> {{ $r->total_days_applied }}</div>
                    <div><span class="text-slate-500">Applied:</span> {{ $r->created_at?->format('D, d M Y h:i A') }}</div>
                    <div><span class="text-slate-500">Department:</span> {{ $r->department->department_name ?? 'N/A' }}</div>
                    <div>
                        <span class="text-slate-500">Stage:</span>
                        {{ $r->manager_recommendation === 'Pending' ? 'Manager review' : 'Final approval' }}
                    </div>
                </div>

                <div class="pt-2 flex flex-wrap gap-2">
                    <button wire:click="viewRequest({{ $r->id }})"
                            class="px-3 py-1.5 rounded-sm bg-slate-100 text-xs">
                        View
                    </button>

                    @if($readOnly)
                        <span class="px-3 py-1.5 rounded-sm bg-slate-50 text-slate-500 text-xs border">Read-only</span>
                    @else
                        <button wire:click="approveRequest({{ $r->id }})"
                                class="px-3 py-1.5 rounded-sm bg-blue-600 text-white text-xs">
                            Approve
                        </button>
                        <button
                                type="button"
                                x-data
                                x-on:click.prevent="$dispatch('confirm-action', {
                                    title: 'Deny leave request?',
                                    message: @js('This will deny the request from ' . $r->requester->full_name . '.'),
                                    confirmLabel: 'Deny',
                                    variant: 'danger',
                                    action: () => $wire.denyRequest({{ $r->id }})
                                })"
                                class="px-3 py-1.5 rounded-sm bg-red-50 text-red-700 text-xs border border-red-200">
                            Deny
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <div class="md:col-span-2 xl:col-span-3 bg-white border rounded-xl p-8 text-center text-slate-500">
                No pending requests found.
            </div>
        @endforelse
    </div>

    <div>
        {{ $requests->links() }}
    </div>

    @if($showDrawer && $selectedRequest)
        @php($commentKey = 'comments.' . $selectedRequest->id)
        <div class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-black/40" wire:click="closeDrawer"></div>

            <div class="absolute right-0 top-0 h-full w-full max-w-xl bg-white shadow-xl border-l p-6 overflow-y-auto">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">Leave Request Details</h3>
                        <p class="text-sm text-slate-600">{{ $selectedRequest->requester->full_name }}</p>
                    </div>
                    <button wire:click="closeDrawer" class="text-2xl text-slate-500 hover:text-slate-800">&times;</button>
                </div>

                <div class="mt-6 space-y-3 text-sm">
                    <div><span class="text-slate-500">Employee:</span> {{ $selectedRequest->requester->full_name }}</div>
                    <div><span class="text-slate-500">Department:</span> {{ $selectedRequest->department->department_name ?? 'N/A' }}</div>
                    <div><span class="text-slate-500">Type:</span> {{ $selectedRequest->leave_type }}</div>
                    <div><span class="text-slate-500">Stage:</span> {{ $selectedRequest->manager_recommendation === 'Pending' ? 'Manager review' : 'Final approval' }}</div>
                    <div><span class="text-slate-500">Applied:</span> {{ $selectedRequest->created_at?->format('D, d M Y h:i A') }}</div>
                    <div>
                        <span class="text-slate-500">Dates:</span>
                        {{ $selectedRequest->start_date->format('d M Y') }} - {{ $selectedRequest->end_date->format('d M Y') }}
                    </div>
                    <div><span class="text-slate-500">Working Days:</span> {{ $selectedRequest->total_days_applied }}</div>

                    <div class="pt-2">
                        <div class="text-slate-500 mb-1">Reason</div>
                        <div class="p-3 bg-slate-50 border rounded-sm whitespace-pre-line">{{ $selectedRequest->leave_details ?: 'No details provided.' }}</div>
                    </div>

                    <div class="pt-2">
                        <div class="text-slate-500 mb-1">Manager</div>
                        <div class="p-3 bg-slate-50 border rounded-sm">
                            {{ $selectedRequest->manager?->full_name ?? 'N/A' }}<br>
                            <span class="text-xs text-slate-500">Recommendation: {{ $selectedRequest->manager_recommendation }}</span><br>
                            <span class="text-xs text-slate-500">Comment: {{ $selectedRequest->manager_comments ?: 'N/A' }}</span>
                        </div>
                    </div>

                    <div class="pt-2">
                        <label class="text-slate-500 mb-1 block">Comment (optional)</label>
                        <textarea wire:model.live="comments.{{ $selectedRequest->id }}"
                                  class="w-full border rounded-sm p-2 text-sm"
                                  rows="3"
                                  maxlength="2000"></textarea>
                        @error($commentKey) <div class="text-xs text-red-700 mt-1">{{ $message }}</div> @enderror
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
                </div>

                <div class="mt-6 flex flex-wrap gap-2">
                    @if($readOnly)
                        <span class="px-4 py-2 rounded-sm bg-slate-50 text-slate-500 text-sm border">Read-only</span>
                    @else
                        <button wire:click="approveRequest({{ $selectedRequest->id }})"
                                class="px-4 py-2 rounded-sm bg-blue-600 text-white text-sm">
                            Approve
                        </button>
                        <button
                                type="button"
                                x-data
                                x-on:click.prevent="$dispatch('confirm-action', {
                                    title: 'Deny leave request?',
                                    message: @js('This will deny the request from ' . $selectedRequest->requester->full_name . '.'),
                                    confirmLabel: 'Deny',
                                    variant: 'danger',
                                    action: () => $wire.denyRequest({{ $selectedRequest->id }})
                                })"
                                class="px-4 py-2 rounded-sm bg-red-600 text-white text-sm">
                            Deny
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
