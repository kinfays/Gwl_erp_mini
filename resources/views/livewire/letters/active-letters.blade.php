<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>{{ $tab === 'closed' ? 'Closed Letters' : 'Active Letters' }}</h2>
            <p>Track received, reviewed, dispatched, and closed correspondence.</p>
        </div>
        <div class="ph-right">
            @if (auth()->user()?->hasRoles('super_admin') || auth()->user()?->hasPermission('letters.create'))
                <a href="{{ route('letters.create') }}" class="btn btn-primary">+ New Letter</a>
            @endif
        </div>
    </div>

    @if ($missingEmployee)
        <div class="erp-card" style="margin-top:14px;background:#fcebeb;border-color:#f7c1c1;color:#a32d2d">
            Your user account is not linked to an employee record.
        </div>
    @else
        @if (session('success'))
            <div class="erp-card" style="margin:14px 0;background:#eaf7ef;border-color:#b8e0c5;color:#21633c">
                {{ session('success') }}
            </div>
        @endif

        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <div class="tabs">
                    <button type="button" wire:click="setTab('active')" class="tab {{ $tab === 'active' ? 'active' : '' }}">Active</button>
                    <button type="button" wire:click="setTab('closed')" class="tab {{ $tab === 'closed' ? 'active' : '' }}">Closed</button>
                </div>

                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <select wire:model.live="typeFilter" class="form-input">
                        <option value="">All types</option>
                        <option value="Internal">Internal</option>
                        <option value="External">External</option>
                    </select>
                    <input type="text" wire:model.live="search" class="form-input" placeholder="Search subject, ref, sender">
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>SN#</th>
                        <th>Subject</th>
                        <th>Ref No</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Current Location</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($letters as $letter)
                        @php
                            $currentLog = $workflow->currentLog($letter, $employee);
                            $pendingRoute = $workflow->pendingIncomingRoute($letter, $employee);
                            $status = $currentLog?->status ?? 'Received';
                            $statusClass = match ($status) {
                                'Received' => 'p-g',
                                'In Review' => 'p-b',
                                'Dispatched' => 'p-d',
                                'Closed' => 'p-v',
                                default => 'p-d',
                            };
                            $latestLog = $letter->statusLogs->sortByDesc('created_at')->first();
                        @endphp
                        <tr>
                            <td style="color:#185FA5">{{ $letter->sn_number }}</td>
                            <td>
                                <div>{{ $letter->subject }}</div>
                                <div style="font-size:10px;color:var(--color-text-secondary)">{{ $letter->sender_name }}</div>
                            </td>
                            <td>{{ $letter->ref_no ?: '-' }}</td>
                            <td><span class="pill {{ $letter->type === 'Internal' ? 'p-b' : 'p-a' }}">{{ $letter->type }}</span></td>
                            <td><span class="pill {{ $statusClass }}">{{ $status }}</span></td>
                            <td>{{ $latestLog?->secretariat?->full_name ?? '-' }}</td>
                            <td>{{ $letter->date_on_letter?->format('d M Y') }}</td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    @if ($pendingRoute)
                                        <button type="button" wire:click="openLetter({{ $letter->id }}, true)" class="actn actn-g">Confirm Hardcopy</button>
                                    @endif

                                    @if ($canForward && $workflow->canDispatch($letter, $employee))
                                        <button type="button" wire:click="openLetter({{ $letter->id }})" class="actn actn-p">Dispatch</button>
                                    @endif

                                    <button type="button" wire:click="openLetter({{ $letter->id }})" class="actn">View</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align:center;color:var(--color-text-secondary);padding:20px">
                                No letters found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if (method_exists($letters, 'links'))
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-top:0.5px solid var(--color-border-tertiary);gap:12px;flex-wrap:wrap">
                    <div style="font-size:11px;color:var(--color-text-secondary)">
                        Showing {{ $letters->firstItem() ?? 0 }} - {{ $letters->lastItem() ?? 0 }} of {{ $letters->total() }} letters
                    </div>
                    <div>{{ $letters->links() }}</div>
                </div>
            @endif
        </div>

        @if ($selectedLetter)
            @php
                $selectedLog = $workflow->currentLog($selectedLetter, $employee);
                $selectedStatus = $selectedLog?->status ?? 'Received';
                $selectedPendingRoute = $workflow->pendingIncomingRoute($selectedLetter, $employee);
                $isCreator = $selectedLetter->created_by_id === $employee->id;
                $isClosed = (bool) $selectedLog?->is_closed;
            @endphp

            <div class="letter-panel-backdrop">
                <div class="letter-panel">
                    <div class="pg-head">
                        <div>
                            <div class="pg-title">{{ $selectedLetter->sn_number }} · {{ $selectedLetter->subject }}</div>
                            <div style="font-size:10px;color:var(--color-text-secondary);margin-top:2px">
                                Ref: {{ $selectedLetter->ref_no ?: 'No reference' }}
                                ·
                                <span class="pill {{ $selectedStatus === 'Received' ? 'p-g' : ($selectedStatus === 'In Review' ? 'p-b' : ($selectedStatus === 'Closed' ? 'p-v' : 'p-d')) }}">{{ $selectedStatus }}</span>
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            @if ($isCreator && ! $isClosed)
                                <button type="button" wire:click="closeLetter" onclick="return confirm('Close this letter?')" class="actn actn-r">Close Letter</button>
                            @endif
                            @if ($isCreator && $isClosed)
                                <button type="button" wire:click="reopenLetter" class="actn actn-g">Re-open</button>
                            @endif
                            <button type="button" wire:click="closePanel" class="actn">Close Panel</button>
                        </div>
                    </div>

                    @if ($flashMessage)
                        <div style="margin:12px 14px 0;padding:8px 10px;border-radius:8px;background:#eaf7ef;color:#21633c;font-size:11px">
                            {{ $flashMessage }}
                        </div>
                    @endif

                    @if ($confirmPrompt && $selectedPendingRoute)
                        <div style="margin:12px 14px;padding:12px;border-radius:10px;background:#faeeda;border:0.5px solid #fac775;color:#854f0b">
                            <div style="font-size:12px;font-weight:600">Confirm hardcopy received</div>
                            <div style="font-size:11px;margin-top:4px">This letter was dispatched to you. Confirm the physical copy before reviewing or dispatching it.</div>
                            <button type="button" wire:click="confirmHardcopy" class="btn btn-primary" style="margin-top:10px">Confirm Hardcopy Received</button>
                        </div>
                    @else
                        <div class="letter-info">
                            <div>
                                <span>Sender</span>
                                <strong>{{ $selectedLetter->sender_name }}</strong>
                            </div>
                            <div>
                                <span>Date on Letter</span>
                                <strong>{{ $selectedLetter->date_on_letter?->format('d M Y') }}</strong>
                            </div>
                            <div>
                                <span>Date Received</span>
                                <strong>{{ $selectedLog?->created_at?->format('d M Y') ?? '-' }}</strong>
                            </div>
                            <div>
                                <span>Current Location</span>
                                <strong>{{ $selectedLetter->statusLogs->sortByDesc('created_at')->first()?->secretariat?->full_name ?? '-' }}</strong>
                            </div>
                        </div>

                        <div class="pg" style="margin:14px">
                            <div class="pg-head">
                                <span class="pg-title">Routing timeline</span>
                            </div>
                            <div style="padding:10px 14px">
                                @forelse ($selectedLetter->routingHistories->sortBy('created_at') as $route)
                                    <div class="route-row">
                                        <div>
                                            <strong>{{ $route->fromSecretariat?->full_name }}</strong>
                                            <span>to</span>
                                            <strong>{{ $route->toSecretariat?->full_name }}</strong>
                                        </div>
                                        <span class="pill {{ $route->received_confirm ? 'p-g' : 'p-a' }}">
                                            {{ $route->received_confirm ? 'Confirmed' : 'Awaiting hardcopy' }}
                                        </span>
                                        <small>{{ $route->created_at?->format('d M Y H:i') }}</small>
                                    </div>
                                @empty
                                    <div style="font-size:11px;color:var(--color-text-secondary)">No dispatch history yet.</div>
                                @endforelse
                            </div>
                        </div>

                        <div style="padding:0 14px 14px">
                            <div class="tabs" style="display:inline-flex;margin-bottom:10px">
                                <button type="button" wire:click="$set('detailTab', 'remarks')" class="tab {{ $detailTab === 'remarks' ? 'active' : '' }}">Remarks</button>
                                <button type="button" wire:click="$set('detailTab', 'dispatch')" class="tab {{ $detailTab === 'dispatch' ? 'active' : '' }}">Dispatch</button>
                                @if ($isCreator)
                                    <button type="button" wire:click="$set('detailTab', 'edit')" class="tab {{ $detailTab === 'edit' ? 'active' : '' }}">Edit</button>
                                @endif
                            </div>

                            @if ($detailTab === 'remarks')
                                <div class="pg">
                                    <div class="pg-head">
                                        <span class="pg-title">Remarks</span>
                                    </div>
                                    <div style="padding:12px 14px">
                                        @forelse ($selectedLetter->remarks->sortByDesc('created_at') as $remark)
                                            <div class="remark-row">
                                                <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
                                                    <strong>{{ $remark->author?->full_name }}</strong>
                                                    <small>{{ $remark->created_at?->format('d M Y H:i') }}</small>
                                                </div>
                                                @if ($editingRemarkId === $remark->id)
                                                    <textarea wire:model="editingRemarkContent" class="form-input" rows="3" style="width:100%;margin-top:8px"></textarea>
                                                    <button type="button" wire:click="updateRemark" class="actn actn-p" style="margin-top:8px">Save</button>
                                                @else
                                                    <p>{{ $remark->remark_content }}</p>
                                                    @if ($remark->author_id === $employee->id)
                                                        <button type="button" wire:click="startEditRemark({{ $remark->id }})" class="actn">Edit</button>
                                                    @endif
                                                @endif
                                            </div>
                                        @empty
                                            <div style="font-size:11px;color:var(--color-text-secondary)">No remarks yet.</div>
                                        @endforelse

                                        @if ($canRemark)
                                            <div style="margin-top:12px">
                                                <textarea wire:model="remarkContent" class="form-input" rows="3" style="width:100%" placeholder="Add remark"></textarea>
                                                @error('remarkContent') <span class="form-label" style="color:#a32d2d">{{ $message }}</span> @enderror
                                                <button type="button" wire:click="addRemark" class="btn btn-primary" style="margin-top:8px">Add Remark</button>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @elseif ($detailTab === 'dispatch')
                                <div class="pg">
                                    <div class="pg-head">
                                        <span class="pg-title">Dispatch letter</span>
                                    </div>
                                    <div style="padding:12px 14px">
                                        @if (! $canForward)
                                            <div style="font-size:11px;color:#854f0b;background:#faeeda;border-radius:8px;padding:10px">
                                                You do not have permission to dispatch letters.
                                            </div>
                                        @elseif (! $workflow->canDispatch($selectedLetter, $employee))
                                            <div style="font-size:11px;color:#854f0b;background:#faeeda;border-radius:8px;padding:10px">
                                                Dispatch is disabled until hardcopy receipt is confirmed or while this letter is closed/dispatched.
                                            </div>
                                        @else
                                            <div class="form-row">
                                                <div class="form-field">
                                                    <label class="form-label">Search secretariat</label>
                                                    <input type="text" wire:model.live="secretarySearch" class="form-input" placeholder="Name or staff ID">
                                                </div>
                                                <div class="form-field">
                                                    <label class="form-label">Recipient</label>
                                                    <select wire:model="dispatchToId" class="form-input">
                                                        <option value="">Select secretary</option>
                                                        @foreach ($secretaries as $secretary)
                                                            <option value="{{ $secretary->id }}">{{ $secretary->full_name }} · {{ $secretary->staff_id }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('dispatchToId') <span class="form-label" style="color:#a32d2d">{{ $message }}</span> @enderror
                                                </div>
                                            </div>
                                            <button type="button" wire:click="dispatch" class="btn btn-primary">Dispatch</button>
                                        @endif
                                    </div>
                                </div>
                            @elseif ($detailTab === 'edit' && $isCreator)
                                <div class="pg">
                                    <div class="pg-head">
                                        <span class="pg-title">Edit letter details</span>
                                    </div>
                                    <div style="padding:12px 14px">
                                        <div class="form-row">
                                            <div class="form-field">
                                                <label class="form-label">Subject</label>
                                                <input type="text" wire:model="editSubject" class="form-input">
                                            </div>
                                            <div class="form-field">
                                                <label class="form-label">Reference No.</label>
                                                <input type="text" wire:model="editRefNo" class="form-input">
                                            </div>
                                        </div>
                                        <div class="form-row">
                                            <div class="form-field">
                                                <label class="form-label">Type</label>
                                                <select wire:model.live="editType" class="form-input">
                                                    <option value="Internal">Internal</option>
                                                    <option value="External">External</option>
                                                </select>
                                            </div>
                                            <div class="form-field">
                                                <label class="form-label">Date on Letter</label>
                                                <input type="date" wire:model="editDateOnLetter" class="form-input">
                                            </div>
                                        </div>
                                        @if ($editType === 'Internal')
                                            <div class="form-row">
                                                <div class="form-field">
                                                    <label class="form-label">Search Employee Sender</label>
                                                    <input type="text" wire:model.live="editSenderSearch" class="form-input">
                                                </div>
                                                <div class="form-field">
                                                    <label class="form-label">Memo Sender</label>
                                                    <select wire:model="editMemoSenderId" class="form-input">
                                                        <option value="">Select employee</option>
                                                        @foreach ($senders as $sender)
                                                            <option value="{{ $sender->id }}">{{ $sender->full_name }} · {{ $sender->staff_id }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            </div>
                                        @else
                                            <div class="form-field" style="margin-bottom:10px">
                                                <label class="form-label">Company / External Sender</label>
                                                <textarea wire:model="editCompanySender" class="form-input" rows="3"></textarea>
                                            </div>
                                        @endif
                                        <button type="button" wire:click="updateLetter" class="btn btn-primary">Save Changes</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>
