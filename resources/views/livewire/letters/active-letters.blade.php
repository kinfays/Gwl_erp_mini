<div>
    <x-ui.page-header :title="$tab === 'closed' ? 'Closed Letters' : 'Active Letters'" description="Track received, reviewed, dispatched, and closed correspondence.">
        @if (auth()->user()?->hasRoles('super_admin') || auth()->user()?->hasPermission('letters.create'))
            <x-slot:actions>
                <a href="{{ route('letters.create') }}" class="btn btn-primary">
                    <x-ui.icon name="file-plus" />
                    New Letter
                </a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($missingEmployee)
        <x-ui.alert tone="danger">Your user account is not linked to an employee record.</x-ui.alert>
    @else
        @if (session('success'))
            <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($lastBatchId)
            <x-ui.alert tone="success" role="status" title="Transmittal {{ $lastBatchNo }} created">
                The letters were handed over and the recipient has been notified.
                <div class="alert-cta">
                    <a href="{{ route('letters.transmittals.sheet', $lastBatchId) }}" target="_blank" rel="noopener" class="btn btn-sm">
                        <x-ui.icon name="printer" class="icon-sm" />
                        Print sheet
                    </a>
                    <a href="{{ route('letters.transmittals', ['tab' => 'sent', 'batch' => $lastBatchId]) }}" class="btn btn-sm btn-ghost">View transmittal</a>
                    <button type="button" wire:click="dismissBatchNotice" class="btn btn-sm btn-ghost">Dismiss</button>
                </div>
            </x-ui.alert>
        @endif

        <x-ui.card :padded="false">
            <div class="ui-toolbar">
                <div class="tabs" role="group" aria-label="Show letters">
                    <button type="button" wire:click="setTab('active')" @class(['tab', 'active' => $tab === 'active']) aria-pressed="{{ $tab === 'active' ? 'true' : 'false' }}">Active</button>
                    <button type="button" wire:click="setTab('closed')" @class(['tab', 'active' => $tab === 'closed']) aria-pressed="{{ $tab === 'closed' ? 'true' : 'false' }}">Closed</button>
                </div>

                <div class="toolbar-filters">
                    <div class="tabs" role="group" aria-label="Quick filters">
                        <button type="button" wire:click="setQuickFilter('awaiting')" @class(['tab', 'active' => $quickFilter === 'awaiting']) aria-pressed="{{ $quickFilter === 'awaiting' ? 'true' : 'false' }}">Awaiting my confirmation</button>
                        @if ($canForward)
                            <button type="button" wire:click="setQuickFilter('ready')" @class(['tab', 'active' => $quickFilter === 'ready']) aria-pressed="{{ $quickFilter === 'ready' ? 'true' : 'false' }}">Ready to dispatch</button>
                        @endif
                    </div>
                    <select wire:model.live="typeFilter" class="form-input" aria-label="Letter type">
                        <option value="">All types</option>
                        <option value="Internal">Internal</option>
                        <option value="External">External</option>
                    </select>
                    <div class="ui-input-wrap toolbar-grow">
                        <x-ui.icon name="search" class="ui-input-icon" />
                        <input type="text" wire:model.live="search" class="form-input ui-input has-icon" placeholder="Search subject, ref, sender" aria-label="Search letters">
                    </div>
                </div>
            </div>

            <div class="ui-loading-host">
                <x-ui.table label="Letters" pin-first>
                    <x-slot:head>
                        <tr>
                            <th class="bulk-check">
                                <input
                                    type="checkbox"
                                    wire:click="togglePage"
                                    @checked($allOnPageSelected)
                                    @disabled(! $hasActionableRows)
                                    aria-label="Select every letter on this page that you can act on"
                                >
                            </th>
                            <th>SN#</th>
                            <th>Subject</th>
                            <th>Ref No</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Current Location</th>
                            <th>Date</th>
                            <th class="actions"><span class="sr-only-text">Actions</span></th>
                        </tr>
                    </x-slot:head>

                    @forelse ($letters as $letter)
                        @php
                            $state = $desk[$letter->id];
                            $pendingRoute = $state['pendingRoute'];
                            $status = $letter->isClosed() ? 'Closed' : ($state['currentLog']?->status ?? 'Received');
                            $latestLog = $letter->statusLogs->sortByDesc('created_at')->first();
                            $tickable = $pendingRoute !== null || ($canForward && $state['canDispatch']);
                            $outgoingHop = $outgoing->get($letter->id);
                        @endphp
                        <tr wire:key="letter-{{ $letter->id }}" @class(['is-selected' => $selectedLetter?->id === $letter->id])>
                            <td class="bulk-check">
                                @if ($tickable)
                                    <input type="checkbox" wire:model.live="selected" value="{{ $letter->id }}" aria-label="Select {{ $letter->sn_number }}">
                                @else
                                    <input type="checkbox" disabled title="Not awaiting your confirmation and not on your desk to dispatch" aria-label="{{ $letter->sn_number }} cannot be selected">
                                @endif
                            </td>
                            <td class="mono nowrap">{{ $letter->sn_number }}</td>
                            <td>
                                <span class="ui-cell-stack">
                                    <span class="ui-person-name">{{ $letter->subject }}</span>
                                    <span class="ui-person-sub">{{ $letter->sender_name }}</span>
                                </span>
                            </td>
                            <td @class(['mono', 'cell-muted' => ! $letter->ref_no])>{{ $letter->ref_no ?: '-' }}</td>
                            <td><x-ui.badge :tone="$letter->type === 'Internal' ? 'primary' : 'lagoon'">{{ $letter->type }}</x-ui.badge></td>
                            <td>
                                <x-ui.status-pill domain="letter" :status="$status" />
                                @if ($outgoingHop)
                                    <span class="ui-cell-stack">
                                        <span class="ui-hint">
                                            awaiting confirmation by {{ $outgoingHop->toSecretariat?->full_name }} ·
                                            <x-ui.badge :tone="$workflow->agingTone($outgoingHop->created_at) ?? 'neutral'" title="Dispatched {{ $outgoingHop->created_at?->format('d M Y H:i') }}">{{ ($outgoingDays = $workflow->waitingDays($outgoingHop->created_at)) === 0 ? '<1 d' : $outgoingDays.' d' }}</x-ui.badge>
                                        </span>
                                    </span>
                                @endif
                            </td>
                            <td class="nowrap">{{ $latestLog?->secretariat?->full_name ?? '-' }}</td>
                            <td class="nowrap cell-muted">{{ $letter->date_on_letter?->format('d M Y') }}</td>
                            <td class="actions">
                                <div class="row-actions">
                                    @if ($pendingRoute)
                                        <button type="button" wire:click="openLetter({{ $letter->id }}, true)" class="btn btn-sm btn-primary">
                                            <x-ui.icon name="clipboard-check" class="icon-sm" />
                                            Confirm Hardcopy
                                        </button>
                                    @endif

                                    @if ($canForward && $state['canDispatch'])
                                        <button type="button" wire:click="openLetter({{ $letter->id }})" class="btn btn-sm">
                                            <x-ui.icon name="send" class="icon-sm" />
                                            Dispatch
                                        </button>
                                    @endif

                                    @if ($canForward && $outgoingHop)
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-ghost is-danger"
                                            x-data
                                            x-on:click.prevent="$dispatch('confirm-action', {
                                                title: 'Recall this letter?',
                                                message: @js($letter->sn_number.' goes back to your desk and '.$outgoingHop->toSecretariat?->full_name.' can no longer confirm it.'),
                                                confirmLabel: 'Recall',
                                                variant: 'danger',
                                                action: () => $wire.recallHop({{ $outgoingHop->id }})
                                            })"
                                        >
                                            <x-ui.icon name="undo-2" class="icon-sm" />
                                            Recall
                                        </button>
                                    @endif

                                    <button type="button" wire:click="openLetter({{ $letter->id }})" class="btn btn-sm btn-ghost" aria-label="View {{ $letter->sn_number }}">
                                        <x-ui.icon name="eye" class="icon-sm" />
                                        View
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="9" icon="inbox" :title="$tab === 'closed' ? 'No closed letters found.' : 'No letters found.'" description="Letters routed to your desk appear here." />
                    @endforelse

                    @if (method_exists($letters, 'links'))
                        <x-slot:footer>
                            <p class="pager-summary">
                                Showing {{ $letters->firstItem() ?? 0 }} - {{ $letters->lastItem() ?? 0 }} of {{ $letters->total() }} letters
                            </p>
                            <div>{{ $letters->links() }}</div>
                        </x-slot:footer>
                    @endif
                </x-ui.table>

                <div wire:loading.delay class="table-skeleton">
                    <span class="skeleton-line"></span>
                    <span class="skeleton-line"></span>
                    <span class="skeleton-line short"></span>
                </div>
            </div>
        </x-ui.card>

        @if ($bulk['count'] > 0)
            <div class="bulk-bar" role="region" aria-label="Bulk actions" wire:key="bulk-bar">
                <div class="bulk-bar-text">
                    <strong>{{ $bulk['count'] }} selected</strong>
                    <span class="ui-hint">
                        @if ($bulk['confirmable'] > 0 && $bulk['dispatchable'] > 0)
                            {{ $bulk['confirmable'] }} to confirm, {{ $bulk['dispatchable'] }} to dispatch. Each button skips the rest.
                        @elseif ($bulk['confirmable'] > 0)
                            {{ $bulk['confirmable'] }} awaiting your confirmation{{ $bulk['count'] > $bulk['confirmable'] ? '; the other '.($bulk['count'] - $bulk['confirmable']).' will be skipped' : '' }}.
                        @elseif ($bulk['dispatchable'] > 0)
                            {{ $bulk['dispatchable'] }} ready to dispatch{{ $bulk['count'] > $bulk['dispatchable'] ? '; the other '.($bulk['count'] - $bulk['dispatchable']).' will be skipped' : '' }}.
                        @else
                            None of these can be acted on right now.
                        @endif
                    </span>
                </div>
                <div class="row-actions">
                    @if ($canForward)
                        <button type="button" wire:click="openBulkDispatch" wire:loading.attr="disabled" class="btn btn-primary btn-sm" @disabled($bulk['dispatchable'] === 0)>
                            <x-ui.icon name="send" class="icon-sm" />
                            Dispatch selected ({{ $bulk['dispatchable'] }})
                        </button>
                    @endif
                    <button type="button" wire:click="confirmSelected" wire:loading.attr="disabled" class="btn btn-sm" @disabled($bulk['confirmable'] === 0)>
                        <x-ui.icon name="clipboard-check" class="icon-sm" />
                        Confirm hardcopies ({{ $bulk['confirmable'] }})
                    </button>
                    <button type="button" wire:click="clearSelection" class="btn btn-sm btn-ghost">Clear</button>
                </div>
            </div>
        @endif

        @if ($bulkDispatchOpen)
            <x-ui.drawer
                title="Dispatch selected letters"
                :description="count($bulkLetterIds).' '.\Illuminate\Support\Str::plural('letter', count($bulkLetterIds)).' in one transmittal'"
                show="true"
                close="$wire.closeBulkDispatch()"
                width="42rem"
                wire:key="bulk-dispatch-panel"
            >
                <div class="ui-stack">
                    @if ($bulkSkipped > 0)
                        <x-ui.alert tone="warning">{{ $bulkSkipped }} of the selected {{ \Illuminate\Support\Str::plural('letter', $bulkSkipped) }} cannot be dispatched by you right now and {{ $bulkSkipped === 1 ? 'is' : 'are' }} left out.</x-ui.alert>
                    @endif

                    @if (count($bulkLetterIds) > $maxBatchSize)
                        <x-ui.alert tone="danger">A transmittal can hold at most {{ $maxBatchSize }} letters. Untick some and try again.</x-ui.alert>
                    @endif

                    <x-ui.table label="Letters in this transmittal" :sticky="false" dense>
                        <x-slot:head>
                            <tr>
                                <th>SN#</th>
                                <th>Subject</th>
                                <th>Ref No</th>
                            </tr>
                        </x-slot:head>
                        @foreach ($bulk['dispatchLetters'] as $dispatchLetter)
                            <tr wire:key="bulk-letter-{{ $dispatchLetter->id }}">
                                <td class="mono nowrap">{{ $dispatchLetter->sn_number }}</td>
                                <td>{{ $dispatchLetter->subject }}</td>
                                <td @class(['mono', 'cell-muted' => ! $dispatchLetter->ref_no])>{{ $dispatchLetter->ref_no ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>

                    @include('livewire.letters.partials.recipient-picker', [
                        'picker' => $bulkPicker,
                        'toModel' => 'bulkDispatchToId',
                        'searchModel' => 'bulkSearch',
                        'scopeModel' => 'bulkScope',
                        'scopeValue' => $bulkScope,
                    ])
                    <x-ui.textarea label="Note (optional)" wire:model="bulkNote" rows="2" maxlength="500" placeholder="e.g. Morning mail, CM minutes" />

                    <div class="ui-form-actions">
                        <button type="button" wire:click="dispatchSelected" wire:loading.attr="disabled" class="btn btn-primary" @disabled(count($bulkLetterIds) === 0 || count($bulkLetterIds) > $maxBatchSize)>
                            <x-ui.icon name="send" />
                            Dispatch {{ count($bulkLetterIds) }} {{ \Illuminate\Support\Str::plural('letter', count($bulkLetterIds)) }}
                        </button>
                    </div>
                </div>
            </x-ui.drawer>
        @endif

        @if ($selectedLetter)
            @php
                $selectedLog = $selectedDesk['currentLog'];
                $selectedPendingRoute = $selectedDesk['pendingRoute'];
                $isCreator = $selectedLetter->created_by_id === $employee->id;
                $isClosed = $selectedLetter->isClosed();
                $selectedStatus = $isClosed ? 'Closed' : ($selectedLog?->status ?? 'Received');
                $hasUnconfirmedHop = $selectedLetter->routingHistories->contains(fn ($route) => $route->isAwaiting());
            @endphp

            <x-ui.drawer
                :title="$selectedLetter->sn_number.' · '.$selectedLetter->subject"
                :description="'Ref: '.($selectedLetter->ref_no ?: 'No reference')"
                show="true"
                close="$wire.closePanel()"
                width="46rem"
                wire:key="letter-panel-{{ $selectedLetter->id }}"
            >
                <div class="ui-stack">
                    <div class="letter-panel-bar">
                        <div class="ui-tags">
                            <x-ui.status-pill domain="letter" :status="$selectedStatus" />
                            <x-ui.badge :tone="$selectedLetter->type === 'Internal' ? 'primary' : 'lagoon'">{{ $selectedLetter->type }}</x-ui.badge>
                        </div>

                        <div class="row-actions">
                            @if ($isCreator && ! $isClosed && $hasUnconfirmedHop)
                                <span class="ui-hint">Waiting for hardcopy confirmation before this letter can be closed.</span>
                            @elseif ($isCreator && ! $isClosed)
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger"
                                    x-data
                                    x-on:click.prevent="$dispatch('confirm-action', {
                                        title: 'Close letter?',
                                        message: 'This letter will move to the closed list.',
                                        confirmLabel: 'Close Letter',
                                        variant: 'danger',
                                        action: () => $wire.closeLetter()
                                    })"
                                >
                                    <x-ui.icon name="archive" class="icon-sm" />
                                    Close Letter
                                </button>
                            @endif
                            @if ($isCreator && $isClosed)
                                <button type="button" wire:click="reopenLetter" class="btn btn-sm">
                                    <x-ui.icon name="undo-2" class="icon-sm" />
                                    Re-open
                                </button>
                            @endif
                        </div>
                    </div>

                    @if ($flashMessage)
                        <x-ui.alert tone="success" role="status">{{ $flashMessage }}</x-ui.alert>
                    @endif

                    @if ($confirmPrompt && $selectedPendingRoute)
                        <x-ui.alert tone="warning" title="Confirm hardcopy received">
                            This letter was dispatched to you. Confirm the physical copy before reviewing or dispatching it.
                            <div class="alert-cta">
                                <button type="button" wire:click="confirmHardcopy" class="btn btn-primary">
                                    <x-ui.icon name="clipboard-check" />
                                    Confirm Hardcopy Received
                                </button>
                            </div>
                        </x-ui.alert>
                    @else
                        <dl class="ui-dl">
                            <div>
                                <dt>Sender</dt>
                                <dd>{{ $selectedLetter->sender_name }}</dd>
                            </div>
                            <div>
                                <dt>Date on Letter</dt>
                                <dd>{{ $selectedLetter->date_on_letter?->format('d M Y') }}</dd>
                            </div>
                            <div>
                                <dt>Date Received</dt>
                                <dd>{{ $selectedLog?->created_at?->format('d M Y') ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Current Location</dt>
                                <dd>{{ $selectedLetter->statusLogs->sortByDesc('created_at')->first()?->secretariat?->full_name ?? '-' }}</dd>
                            </div>
                            @if ($isClosed && $selectedLetter->latestDelivery)
                                <div>
                                    <dt>Delivered to</dt>
                                    <dd>
                                        {{ $selectedLetter->latestDelivery->addresseeName() }}
                                        <span class="ui-hint">{{ $selectedLetter->latestDelivery->delivered_at?->format('d M Y H:i') }} · by {{ $selectedLetter->latestDelivery->deliveredBy?->full_name }}@if ($selectedLetter->latestDelivery->note) · {{ $selectedLetter->latestDelivery->note }}@endif</span>
                                    </dd>
                                </div>
                            @endif
                        </dl>

                        <section aria-labelledby="letter-routing-title">
                            <h3 id="letter-routing-title" class="ui-panel-title">Routing timeline</h3>
                            @php($routes = $selectedLetter->routingHistories->sortBy('created_at'))
                            @if ($routes->isEmpty())
                                <p class="ui-hint">No dispatch history yet.</p>
                            @else
                                <ol class="ui-timeline">
                                    @foreach ($routes as $route)
                                        <li @class(['is-done' => $route->received_confirm, 'is-current' => $route->isAwaiting(), 'is-resolved' => $route->isResolved()])>
                                            <div class="route-line">
                                                <span><strong>{{ $route->fromSecretariat?->full_name }}</strong> <span class="cell-muted">to</span> <strong>{{ $route->toSecretariat?->full_name }}</strong></span>
                                                @if ($route->isResolved())
                                                    <x-ui.status-pill tone="muted" :label="$route->resolution === 'recalled' ? 'Recalled' : 'Rejected'" />
                                                @else
                                                    <x-ui.status-pill :tone="$route->received_confirm ? 'success' : 'warning'" :label="$route->received_confirm ? 'Confirmed' : 'Awaiting hardcopy'" />
                                                @endif
                                            </div>
                                            <span class="ui-hint">{{ $route->created_at?->format('d M Y H:i') }}</span>
                                            @if ($route->isResolved())
                                                <span class="ui-hint">
                                                    {{ $route->resolution === 'recalled' ? 'Recalled by '.$route->fromSecretariat?->full_name : 'Rejected by '.$route->toSecretariat?->full_name }}
                                                    on {{ $route->resolved_at?->format('d M Y H:i') }}@if ($route->resolution_note): “{{ $route->resolution_note }}”@endif
                                                </span>
                                            @elseif ($route->received_confirm && $route->confirmed_at)
                                                <span class="ui-hint">Confirmed {{ $route->confirmed_at->format('d M Y H:i') }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </section>

                        <div class="tabs" role="group" aria-label="Letter actions">
                            <button type="button" wire:click="$set('detailTab', 'remarks')" @class(['tab', 'active' => $detailTab === 'remarks']) aria-pressed="{{ $detailTab === 'remarks' ? 'true' : 'false' }}">Remarks</button>
                            <button type="button" wire:click="$set('detailTab', 'dispatch')" @class(['tab', 'active' => $detailTab === 'dispatch']) aria-pressed="{{ $detailTab === 'dispatch' ? 'true' : 'false' }}">Dispatch</button>
                            @if ($selectedDesk['holdsLetter'])
                                <button type="button" wire:click="$set('detailTab', 'deliver')" @class(['tab', 'active' => $detailTab === 'deliver']) aria-pressed="{{ $detailTab === 'deliver' ? 'true' : 'false' }}">Deliver</button>
                            @endif
                            @if ($isCreator)
                                <button type="button" wire:click="$set('detailTab', 'edit')" @class(['tab', 'active' => $detailTab === 'edit']) aria-pressed="{{ $detailTab === 'edit' ? 'true' : 'false' }}">Edit</button>
                            @endif
                        </div>

                        @if ($detailTab === 'remarks')
                            <section class="letter-section" aria-labelledby="letter-remarks-title">
                                <h3 id="letter-remarks-title" class="ui-panel-title">Remarks</h3>

                                @forelse ($selectedLetter->remarks->sortByDesc('created_at') as $remark)
                                    <article class="remark-card" wire:key="remark-{{ $remark->id }}">
                                        <header class="remark-card-head">
                                            <span class="ui-person">
                                                <x-ui.avatar :name="$remark->author?->full_name ?? ''" />
                                                <span class="ui-person-name">{{ $remark->author?->full_name }}</span>
                                            </span>
                                            <span class="ui-hint nowrap">{{ $remark->created_at?->format('d M Y H:i') }}</span>
                                        </header>

                                        @if ($editingRemarkId === $remark->id)
                                            <div class="ui-stack">
                                                @if ($reviewerTier)
                                                    <x-ui.input :label="$reviewerTier === 'chief' ? 'Chief Manager' : 'Manager'" :value="$employee->full_name" readonly disabled hint="Recorded as you." />
                                                    <x-ui.textarea label="Your remark" wire:model="editingRemarkContent" rows="3" />
                                                @else
                                                <div class="ui-form-grid">
                                                    <x-form.combobox
                                                        label="Manager"
                                                        model="editingRemarkManagerId"
                                                        :options="$managerOptions"
                                                        placeholder="Type to search manager"
                                                        empty-text="No managers in your region"
                                                    />
                                                    <x-form.combobox
                                                        label="Chief Manager"
                                                        model="editingRemarkChiefManagerId"
                                                        :options="$chiefManagerOptions"
                                                        placeholder="Type to search chief manager"
                                                        empty-text="No chief managers in your region"
                                                    />
                                                </div>
                                                <x-ui.textarea label="Manager remarks" wire:model="editingRemarkContent" rows="3" />
                                                <x-ui.textarea label="Secretary remarks" wire:model="editingSecretaryRemarkContent" rows="2" placeholder="Optional" />
                                                @endif
                                                <div class="ui-form-actions">
                                                    <button type="button" wire:click="updateRemark" class="btn btn-primary btn-sm">Save</button>
                                                </div>
                                            </div>
                                        @else
                                            @if ($remark->manager_id || $remark->chief_manager_id)
                                                <dl class="remark-people">
                                                    <div>
                                                        <dt>Manager</dt>
                                                        <dd>{{ $remark->manager?->full_name ?? '-' }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Chief Manager</dt>
                                                        <dd>{{ $remark->chiefManager?->full_name ?? '-' }}</dd>
                                                    </div>
                                                </dl>
                                            @endif
                                            @if (filled($remark->remark_content))
                                                <div class="remark-section">
                                                    <span>{{ $remark->manager_id || $remark->chief_manager_id ? 'Manager remarks' : 'Remark' }}</span>
                                                    <p>{{ $remark->remark_content }}</p>
                                                </div>
                                            @endif
                                            @if ($remark->secretary_remark_content)
                                                <div class="remark-section">
                                                    <span>Secretary remarks</span>
                                                    <p>{{ $remark->secretary_remark_content }}</p>
                                                </div>
                                            @endif
                                            @if ($remark->author_id === $employee->id && $selectedDesk['holdsLetter'])
                                                <div>
                                                    <button type="button" wire:click="startEditRemark({{ $remark->id }})" class="btn btn-sm btn-ghost">
                                                        <x-ui.icon name="pencil" class="icon-sm" />
                                                        Edit
                                                    </button>
                                                </div>
                                            @endif
                                        @endif
                                    </article>
                                @empty
                                    <p class="ui-hint">No remarks yet.</p>
                                @endforelse

                                @if ($canRemark && ! $selectedDesk['holdsLetter'])
                                    <p class="ui-hint">Remarks can be added by whoever currently holds this open letter.</p>
                                @elseif ($canRemark)
                                    <div class="remark-compose ui-stack">
                                        <h4 class="remark-compose-title">Add a remark</h4>
                                        @if ($reviewerTier)
                                            <x-ui.input :label="$reviewerTier === 'chief' ? 'Chief Manager' : 'Manager'" :value="$employee->full_name" readonly disabled hint="You are holding this letter, so the remark is recorded as yours." />
                                            <x-ui.textarea label="Your remark" wire:model="remarkContent" rows="3" placeholder="Add remark" />
                                        @else
                                        <div class="ui-form-grid">
                                            <x-form.combobox
                                                label="Manager"
                                                model="remarkManagerId"
                                                :options="$managerOptions"
                                                placeholder="Type to search manager"
                                                empty-text="No managers in your region"
                                            />
                                            <x-form.combobox
                                                label="Chief Manager"
                                                model="remarkChiefManagerId"
                                                :options="$chiefManagerOptions"
                                                placeholder="Type to search chief manager"
                                                empty-text="No chief managers in your region"
                                            />
                                        </div>
                                        <x-ui.textarea label="Manager remarks" wire:model="remarkContent" rows="3" placeholder="Add remark" />
                                        <x-ui.textarea label="Secretary remarks" wire:model="secretaryRemarkContent" rows="2" placeholder="Optional" />
                                        @endif
                                        <div class="ui-form-actions">
                                            <button type="button" wire:click="addRemark" class="btn btn-primary">
                                                <x-ui.icon name="plus" />
                                                Add Remark
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </section>
                        @elseif ($detailTab === 'dispatch')
                            <section class="letter-section" aria-labelledby="letter-dispatch-title">
                                <h3 id="letter-dispatch-title" class="ui-panel-title">Dispatch letter</h3>

                                @if (! $canForward)
                                    <x-ui.alert tone="warning">You do not have permission to dispatch letters.</x-ui.alert>
                                @elseif (! $selectedDesk['canDispatch'])
                                    <x-ui.alert tone="warning">Dispatch is disabled until hardcopy receipt is confirmed or while this letter is closed/dispatched.</x-ui.alert>
                                @else
                                    <div class="ui-stack">
                                        @include('livewire.letters.partials.recipient-picker', [
                                            'picker' => $picker,
                                            'toModel' => 'dispatchToId',
                                            'searchModel' => 'recipientSearch',
                                            'scopeModel' => 'dispatchScope',
                                            'scopeValue' => $dispatchScope,
                                        ])
                                        <div class="ui-form-actions">
                                            <button type="button" wire:click="dispatchLetter" class="btn btn-primary">
                                                <x-ui.icon name="send" />
                                                Dispatch
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </section>
                        @elseif ($detailTab === 'deliver' && $selectedDesk['holdsLetter'])
                            <section class="letter-section" aria-labelledby="letter-deliver-title">
                                <h3 id="letter-deliver-title" class="ui-panel-title">Deliver to addressee</h3>
                                <p class="ui-hint">Record who took the hardcopy. This is the last step: the letter is closed. The addressee needs no login; you record the paper signature.</p>

                                <div class="ui-stack">
                                    <div class="tabs" role="group" aria-label="Who received it">
                                        <button type="button" wire:click="$set('deliverMode', 'employee')" @class(['tab', 'active' => $deliverMode === 'employee']) aria-pressed="{{ $deliverMode === 'employee' ? 'true' : 'false' }}">Staff member</button>
                                        <button type="button" wire:click="$set('deliverMode', 'name')" @class(['tab', 'active' => $deliverMode === 'name']) aria-pressed="{{ $deliverMode === 'name' ? 'true' : 'false' }}">Someone else</button>
                                    </div>

                                    @if ($deliverMode === 'employee')
                                        <div class="ui-form-grid">
                                            <x-ui.input label="Search staff" wire:model.live="deliverEmployeeSearch" placeholder="Name or staff ID" icon="search" />
                                            <x-ui.select label="Received by" wire:model="deliverEmployeeId">
                                                <option value="">Select staff member</option>
                                                @foreach ($deliverEmployees as $person)
                                                    <option value="{{ $person->id }}">{{ $workflow->recipientLabel($person) }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        </div>
                                    @else
                                        <x-ui.input label="Received by (name)" wire:model="deliverName" placeholder="Full name of the person who took the letter" />
                                    @endif

                                    <div class="ui-form-grid">
                                        <x-ui.input label="Delivered at" type="datetime-local" wire:model="deliverAt" hint="Leave blank for now." />
                                        <x-ui.input label="Note (optional)" wire:model="deliverNote" maxlength="500" placeholder="e.g. Collected from the front desk" />
                                    </div>

                                    <div class="ui-form-actions">
                                        <button type="button" wire:click="deliverLetter" wire:loading.attr="disabled" class="btn btn-primary">
                                            <x-ui.icon name="check" />
                                            Record delivery and close
                                        </button>
                                    </div>
                                </div>
                            </section>
                        @elseif ($detailTab === 'edit' && $isCreator)
                            <section class="letter-section" aria-labelledby="letter-edit-title">
                                <h3 id="letter-edit-title" class="ui-panel-title">Edit letter details</h3>

                                <div class="ui-stack">
                                    <div class="ui-form-grid">
                                        <x-ui.input label="Subject" wire:model="editSubject" />
                                        <x-ui.input label="Reference No." wire:model="editRefNo" class="mono" />

                                        <div class="ui-field">
                                            <span class="ui-label" aria-hidden="true">Type</span>
                                            <x-ui.segmented label="Type" wire:model.live="editType" :options="['Internal' => 'Internal', 'External' => 'External']" />
                                        </div>
                                        <x-ui.input label="Date on Letter" type="date" wire:model="editDateOnLetter" />

                                        @if ($editType === 'Internal')
                                            <x-ui.input label="Search Employee Sender" wire:model.live="editSenderSearch" icon="search" />
                                            <x-ui.select label="Memo Sender" wire:model="editMemoSenderId">
                                                <option value="">Select employee</option>
                                                @foreach ($senders as $sender)
                                                    <option value="{{ $sender->id }}">{{ $sender->full_name }} · {{ $sender->staff_id }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        @else
                                            <div class="span-2">
                                                <x-ui.textarea label="Company / External Sender" wire:model="editCompanySender" rows="3" />
                                            </div>
                                        @endif
                                    </div>

                                    <div class="ui-form-actions">
                                        <button type="button" wire:click="updateLetter" class="btn btn-primary">
                                            <x-ui.icon name="check" />
                                            Save Changes
                                        </button>
                                    </div>
                                </div>
                            </section>
                        @endif
                    @endif
                </div>
            </x-ui.drawer>
        @endif
    @endif
</div>
