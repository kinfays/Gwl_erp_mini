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

        <x-ui.card :padded="false">
            <div class="ui-toolbar">
                <div class="tabs" role="group" aria-label="Show letters">
                    <button type="button" wire:click="setTab('active')" @class(['tab', 'active' => $tab === 'active']) aria-pressed="{{ $tab === 'active' ? 'true' : 'false' }}">Active</button>
                    <button type="button" wire:click="setTab('closed')" @class(['tab', 'active' => $tab === 'closed']) aria-pressed="{{ $tab === 'closed' ? 'true' : 'false' }}">Closed</button>
                </div>

                <div class="toolbar-filters">
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
                            $currentLog = $workflow->currentLog($letter, $employee);
                            $pendingRoute = $workflow->pendingIncomingRoute($letter, $employee);
                            $status = $currentLog?->status ?? 'Received';
                            $latestLog = $letter->statusLogs->sortByDesc('created_at')->first();
                        @endphp
                        <tr wire:key="letter-{{ $letter->id }}" @class(['is-selected' => $selectedLetter?->id === $letter->id])>
                            <td class="mono nowrap">{{ $letter->sn_number }}</td>
                            <td>
                                <span class="ui-cell-stack">
                                    <span class="ui-person-name">{{ $letter->subject }}</span>
                                    <span class="ui-person-sub">{{ $letter->sender_name }}</span>
                                </span>
                            </td>
                            <td @class(['mono', 'cell-muted' => ! $letter->ref_no])>{{ $letter->ref_no ?: '-' }}</td>
                            <td><x-ui.badge :tone="$letter->type === 'Internal' ? 'primary' : 'lagoon'">{{ $letter->type }}</x-ui.badge></td>
                            <td><x-ui.status-pill domain="letter" :status="$status" /></td>
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

                                    @if ($canForward && $workflow->canDispatch($letter, $employee))
                                        <button type="button" wire:click="openLetter({{ $letter->id }})" class="btn btn-sm">
                                            <x-ui.icon name="send" class="icon-sm" />
                                            Dispatch
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
                        <x-ui.empty-row :colspan="8" icon="inbox" :title="$tab === 'closed' ? 'No closed letters found.' : 'No letters found.'" description="Letters routed to your desk appear here." />
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

        @if ($selectedLetter)
            @php
                $selectedLog = $workflow->currentLog($selectedLetter, $employee);
                $selectedStatus = $selectedLog?->status ?? 'Received';
                $selectedPendingRoute = $workflow->pendingIncomingRoute($selectedLetter, $employee);
                $isCreator = $selectedLetter->created_by_id === $employee->id;
                $isClosed = (bool) $selectedLog?->is_closed;
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
                            @if ($isCreator && ! $isClosed)
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
                        </dl>

                        <section aria-labelledby="letter-routing-title">
                            <h3 id="letter-routing-title" class="ui-panel-title">Routing timeline</h3>
                            @php($routes = $selectedLetter->routingHistories->sortBy('created_at'))
                            @if ($routes->isEmpty())
                                <p class="ui-hint">No dispatch history yet.</p>
                            @else
                                <ol class="ui-timeline">
                                    @foreach ($routes as $route)
                                        <li @class(['is-done' => $route->received_confirm, 'is-current' => ! $route->received_confirm])>
                                            <div class="route-line">
                                                <span><strong>{{ $route->fromSecretariat?->full_name }}</strong> <span class="cell-muted">to</span> <strong>{{ $route->toSecretariat?->full_name }}</strong></span>
                                                <x-ui.status-pill :tone="$route->received_confirm ? 'success' : 'warning'" :label="$route->received_confirm ? 'Confirmed' : 'Awaiting hardcopy'" />
                                            </div>
                                            <span class="ui-hint">{{ $route->created_at?->format('d M Y H:i') }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </section>

                        <div class="tabs" role="group" aria-label="Letter actions">
                            <button type="button" wire:click="$set('detailTab', 'remarks')" @class(['tab', 'active' => $detailTab === 'remarks']) aria-pressed="{{ $detailTab === 'remarks' ? 'true' : 'false' }}">Remarks</button>
                            <button type="button" wire:click="$set('detailTab', 'dispatch')" @class(['tab', 'active' => $detailTab === 'dispatch']) aria-pressed="{{ $detailTab === 'dispatch' ? 'true' : 'false' }}">Dispatch</button>
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
                                            @if ($remark->author_id === $employee->id)
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

                                @if ($canRemark)
                                    <div class="remark-compose ui-stack">
                                        <h4 class="remark-compose-title">Add a remark</h4>
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
                                @elseif (! $workflow->canDispatch($selectedLetter, $employee))
                                    <x-ui.alert tone="warning">Dispatch is disabled until hardcopy receipt is confirmed or while this letter is closed/dispatched.</x-ui.alert>
                                @else
                                    <div class="ui-stack">
                                        <div class="ui-form-grid">
                                            <x-ui.input label="Search secretariat" wire:model.live="secretarySearch" placeholder="Name or staff ID" icon="search" />
                                            <x-ui.select label="Recipient" wire:model="dispatchToId">
                                                <option value="">Select secretary</option>
                                                @foreach ($secretaries as $secretary)
                                                    <option value="{{ $secretary->id }}">{{ $secretary->full_name }} · {{ $secretary->staff_id }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        </div>
                                        <div class="ui-form-actions">
                                            <button type="button" wire:click="dispatchLetter" class="btn btn-primary">
                                                <x-ui.icon name="send" />
                                                Dispatch
                                            </button>
                                        </div>
                                    </div>
                                @endif
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
