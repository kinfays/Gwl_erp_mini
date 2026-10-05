<div>
    <x-ui.page-header title="Approvals" description="Pending leave requests in your approval chain.">
        <x-slot:actions>
            <x-ui.badge tone="primary">Pending</x-ui.badge>
            <x-ui.input type="search" wire:model.live="search" placeholder="Search employee name..." icon="search" aria-label="Search employee name" class="approvals-search" />
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->has('action'))
        <x-ui.alert tone="danger" role="alert">{{ $errors->first('action') }}</x-ui.alert>
    @endif

    <div class="request-grid">
        @forelse ($requests as $r)
            <article class="request-card">
                <header class="request-card-head">
                    <span class="ui-person">
                        <x-ui.avatar :name="$r->requester->full_name" size="lg" />
                        <span>
                            <span class="ui-person-name">{{ $r->requester->full_name }}</span>
                            <span class="ui-person-sub">{{ $r->department->department_name ?? 'N/A' }}</span>
                        </span>
                    </span>
                    <x-ui.status-pill
                        :tone="$r->manager_recommendation === 'Pending' ? 'warning' : 'info'"
                        :label="$r->manager_recommendation === 'Pending' ? 'Manager review' : 'Final approval'"
                    />
                </header>

                <dl class="ui-dl request-card-facts">
                    <div>
                        <dt>Leave Type</dt>
                        <dd>{{ $r->leave_type }}</dd>
                    </div>
                    <div>
                        <dt>Days</dt>
                        <dd>{{ $r->total_days_applied }}</dd>
                    </div>
                    <div class="span-2">
                        <dt>Dates</dt>
                        <dd>{{ $r->start_date->format('d M Y') }} – {{ $r->end_date->format('d M Y') }}</dd>
                    </div>
                    <div class="span-2">
                        <dt>Applied</dt>
                        <dd>{{ $r->created_at?->format('D, d M Y h:i A') }}</dd>
                    </div>
                </dl>

                <footer class="request-card-actions">
                    <x-ui.button size="sm" variant="ghost" icon="eye" wire:click="viewRequest({{ $r->id }})">View</x-ui.button>

                    @if ($readOnly)
                        <x-ui.badge>Read-only</x-ui.badge>
                    @else
                        <button
                            type="button"
                            class="btn btn-danger btn-sm"
                            x-data
                            x-on:click.prevent="$dispatch('confirm-action', {
                                title: 'Deny leave request?',
                                message: @js('This will deny the request from ' . $r->requester->full_name . '.'),
                                confirmLabel: 'Deny',
                                variant: 'danger',
                                action: () => $wire.denyRequest({{ $r->id }})
                            })"
                        >Deny</button>
                        <x-ui.button size="sm" variant="primary" icon="check" wire:click="approveRequest({{ $r->id }})" loading="approveRequest">Approve</x-ui.button>
                    @endif
                </footer>
            </article>
        @empty
            <x-ui.card class="request-grid-empty">
                <x-ui.empty-state icon="square-check-big" title="No pending requests found." description="When someone in your approval chain applies for leave, it will wait for you here." />
            </x-ui.card>
        @endforelse
    </div>

    <div class="list-pager">
        {{ $requests->links() }}
    </div>

    @if ($showDrawer && $selectedRequest)
        @php($commentKey = 'comments.' . $selectedRequest->id)
        <x-ui.drawer show="true" close="$wire.closeDrawer()" title="Leave Request Details" :description="$selectedRequest->requester->full_name">
            <dl class="ui-dl">
                <div>
                    <dt>Employee</dt>
                    <dd>{{ $selectedRequest->requester->full_name }}</dd>
                </div>
                <div>
                    <dt>Department</dt>
                    <dd>{{ $selectedRequest->department->department_name ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt>Type</dt>
                    <dd>{{ $selectedRequest->leave_type }}</dd>
                </div>
                <div>
                    <dt>Stage</dt>
                    <dd>{{ $selectedRequest->manager_recommendation === 'Pending' ? 'Manager review' : 'Final approval' }}</dd>
                </div>
                <div>
                    <dt>Dates</dt>
                    <dd>{{ $selectedRequest->start_date->format('d M Y') }} – {{ $selectedRequest->end_date->format('d M Y') }}</dd>
                </div>
                <div>
                    <dt>Working Days</dt>
                    <dd>{{ $selectedRequest->total_days_applied }}</dd>
                </div>
                <div class="span-2">
                    <dt>Applied</dt>
                    <dd>{{ $selectedRequest->created_at?->format('D, d M Y h:i A') }}</dd>
                </div>
            </dl>

            <section class="ui-panel">
                <h3 class="ui-panel-title">Reason</h3>
                <p class="panel-text">{{ $selectedRequest->leave_details ?: 'No details provided.' }}</p>
            </section>

            @if ($selectedRequest->is_single_stage)
                <section class="ui-panel">
                    <h3 class="ui-panel-title">Approval route</h3>
                    <p class="ui-hint">Applied directly to the final approver: no manager recommendation is needed.</p>
                </section>
            @else
                <section class="ui-panel">
                    <h3 class="ui-panel-title">Manager</h3>
                    <p class="panel-text"><strong>{{ $selectedRequest->manager?->full_name ?? 'N/A' }}</strong></p>
                    <p class="ui-hint">Recommendation: {{ $selectedRequest->manager_recommendation }}</p>
                    <p class="ui-hint">Comment: {{ $selectedRequest->manager_comments ?: 'N/A' }}</p>
                </section>
            @endif

            <x-ui.field label="Comment (optional)" for="approval-comment" :error="$commentKey">
                <textarea
                    id="approval-comment"
                    wire:model.live="comments.{{ $selectedRequest->id }}"
                    class="form-input ui-input"
                    rows="3"
                    maxlength="2000"
                ></textarea>
            </x-ui.field>

            @if (! $readOnly && $selectedRequest->manager_recommendation !== 'Pending')
                {{-- The final approval makes the approval letter; the approver's own saved signature can go on it. --}}
                @if ($hasSignature)
                    <x-ui.checkbox label="Apply my saved signature to the approval letter" id="apply-signature" wire:model="applySignature" />
                @else
                    <p class="ui-hint">You have no saved signature, so the letter's signing space will be blank. <a class="text-link" href="{{ route('leave.signature') }}">Add one under My Signature</a> first if you want it on the letter.</p>
                @endif
            @endif

            @if ($selectedRequest->file_attachment)
                <p>
                    <a class="text-link" href="{{ asset('storage/' . $selectedRequest->file_attachment) }}" target="_blank" rel="noopener">
                        <x-ui.icon name="external-link" class="icon-sm" /> View attachment
                    </a>
                </p>
            @endif

            <x-slot:footer>
                @if ($readOnly)
                    <x-ui.badge>Read-only</x-ui.badge>
                @else
                    <button
                        type="button"
                        class="btn btn-danger-solid"
                        x-data
                        x-on:click.prevent="$dispatch('confirm-action', {
                            title: 'Deny leave request?',
                            message: @js('This will deny the request from ' . $selectedRequest->requester->full_name . '.'),
                            confirmLabel: 'Deny',
                            variant: 'danger',
                            action: () => $wire.denyRequest({{ $selectedRequest->id }})
                        })"
                    >Deny</button>
                    <x-ui.button variant="primary" icon="check" wire:click="approveRequest({{ $selectedRequest->id }})" loading="approveRequest">Approve</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</div>
