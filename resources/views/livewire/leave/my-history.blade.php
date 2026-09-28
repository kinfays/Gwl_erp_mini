@php
    $viewer = auth()->user();
    $canExport = $viewer->hasRoles('super_admin') || $viewer->hasPermission('leave.export');
@endphp

<div>
    <x-ui.page-header title="My Leave History">
        <p>
            @if ($includePast36Months)
                Showing the last 36 months.
            @else
                Showing current year requests.
            @endif
        </p>

        <x-slot:actions>
            <x-ui.button wire:click="togglePast" icon="history">
                {{ $includePast36Months ? 'Show Current Year' : 'Show Past 36 Months' }}
            </x-ui.button>
            @if ($canExport)
                <x-ui.button :href="route('leave.export.approved.excel')" icon="download">Export Excel</x-ui.button>
            @endif
            <x-ui.button :href="route('leave.apply')" variant="primary" icon="plus">Apply</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->has('action'))
        <x-ui.alert tone="danger" role="alert">{{ $errors->first('action') }}</x-ui.alert>
    @endif

    <div class="request-grid">
        @forelse ($requests as $r)
            <article class="request-card">
                <header class="request-card-head">
                    <div>
                        <p class="request-card-kicker">Leave Type</p>
                        <p class="request-card-title">{{ $r->leave_type }}</p>
                    </div>
                    <x-ui.status-pill domain="leave" :status="$r->leave_status" />
                </header>

                <dl class="ui-dl request-card-facts">
                    <div class="span-2">
                        <dt>Dates</dt>
                        <dd>{{ $r->start_date->format('d M Y') }} – {{ $r->end_date->format('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt>Days</dt>
                        <dd>{{ $r->total_days_applied }}</dd>
                    </div>
                </dl>

                <footer class="request-card-actions">
                    <x-ui.button size="sm" variant="ghost" icon="eye" wire:click="viewRequest({{ $r->id }})">View</x-ui.button>

                    @if ($r->canBeEditedByRequester())
                        <x-ui.button size="sm" icon="pencil" wire:click="editRequest({{ $r->id }})">Edit</x-ui.button>
                    @endif

                    @if ($r->leave_status === 'Planned')
                        <button
                            type="button"
                            class="btn btn-danger btn-sm"
                            x-data
                            x-on:click.prevent="$dispatch('confirm-action', {
                                title: 'Delete planned request?',
                                message: 'This planned leave request will be removed.',
                                confirmLabel: 'Delete',
                                variant: 'danger',
                                action: () => $wire.deletePlanned({{ $r->id }})
                            })"
                        >Delete</button>
                    @endif

                    @if ($r->leave_status === 'Denied')
                        <x-ui.button size="sm" variant="warn" icon="undo-2" wire:click="reopenDenied({{ $r->id }})">Re-open</x-ui.button>
                    @endif
                </footer>
            </article>
        @empty
            <x-ui.card class="request-grid-empty">
                <x-ui.empty-state icon="calendar-days" title="No leave requests found." description="Requests you plan or submit will be listed here.">
                    <x-ui.button :href="route('leave.apply')" size="sm" variant="primary" icon="plus">Apply for leave</x-ui.button>
                </x-ui.empty-state>
            </x-ui.card>
        @endforelse
    </div>

    <div class="list-pager">
        {{ $requests->links() }}
    </div>

    {{-- Drawer --}}
    @if ($showDrawer && $selectedRequest)
        <x-ui.drawer show="true" close="$wire.closeDrawer()" title="Leave Request Details" description="Read-only view">
            <dl class="ui-dl">
                <div>
                    <dt>Type</dt>
                    <dd>{{ $selectedRequest->leave_type }}</dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd><x-ui.status-pill domain="leave" :status="$selectedRequest->leave_status" /></dd>
                </div>
                <div>
                    <dt>Dates</dt>
                    <dd>{{ $selectedRequest->start_date->format('d M Y') }} – {{ $selectedRequest->end_date->format('d M Y') }}</dd>
                </div>
                <div>
                    <dt>Working Days</dt>
                    <dd>{{ $selectedRequest->total_days_applied }}</dd>
                </div>
            </dl>

            <section class="ui-panel">
                <h3 class="ui-panel-title">Reason</h3>
                <p class="panel-text">{{ $selectedRequest->leave_details ?: '—' }}</p>
            </section>

            <section>
                <h3 class="ui-panel-title">Approval trail</h3>
                <ol class="ui-timeline">
                    <li class="is-done">
                        <strong>Submitted</strong>
                        <span>{{ $selectedRequest->created_at?->format('D, d M Y h:i A') }}</span>
                    </li>
                    <li @class(['is-done' => $selectedRequest->manager_recommendation !== 'Pending', 'is-current' => $selectedRequest->manager_recommendation === 'Pending'])>
                        <strong>Manager — {{ $selectedRequest->manager?->full_name ?? '—' }}</strong>
                        <span><x-ui.status-pill domain="recommendation" :status="$selectedRequest->manager_recommendation" /></span>
                        <span>Comment: {{ $selectedRequest->manager_comments ?: '—' }}</span>
                    </li>
                    <li @class(['is-done' => in_array($selectedRequest->leave_status, ['Approved', 'Denied'], true)])>
                        <strong>Final Approver — {{ $selectedRequest->approvedBy?->full_name ?? '—' }}</strong>
                        <span>Comment: {{ $selectedRequest->chiefManager_comments ?: '—' }}</span>
                    </li>
                </ol>
            </section>

            @if ($selectedRequest->file_attachment)
                <p>
                    <a class="text-link" href="{{ asset('storage/' . $selectedRequest->file_attachment) }}" target="_blank" rel="noopener">
                        <x-ui.icon name="external-link" class="icon-sm" /> View attachment
                    </a>
                </p>
            @endif

            <x-slot:footer>
                @if ($selectedRequest->canBeEditedByRequester())
                    <x-ui.button variant="primary" icon="pencil" wire:click="editRequest({{ $selectedRequest->id }})">Edit</x-ui.button>
                @endif

                @if ($selectedRequest->leave_status === 'Planned')
                    <button
                        type="button"
                        class="btn btn-danger-solid"
                        x-data
                        x-on:click.prevent="$dispatch('confirm-action', {
                            title: 'Delete planned request?',
                            message: 'This planned leave request will be removed.',
                            confirmLabel: 'Delete',
                            variant: 'danger',
                            action: () => $wire.deletePlanned({{ $selectedRequest->id }})
                        })"
                    >Delete</button>
                @endif

                @if ($selectedRequest->leave_status === 'Denied')
                    <x-ui.button variant="warn" icon="undo-2" wire:click="reopenDenied({{ $selectedRequest->id }})">Re-open</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
