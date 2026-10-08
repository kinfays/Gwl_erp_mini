<div>
    <x-ui.page-header :title="$incident->reference" :description="$incident->typeLabel().' at '.($incident->placeLabel() ?: 'an unspecified place').', '.$incident->occurred_on->format('d M Y')">
        <x-slot:actions>
            <x-ui.button :href="route('health_safety.incidents.print', $incident)" target="_blank" icon="printer">Print</x-ui.button>
            <x-ui.button :href="route('health_safety.incidents.pdf', $incident)" icon="file-down">PDF</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-tags dash-row">
        <x-ui.status-pill domain="hs-incident" :status="$incident->status" />
        @if ($incident->severity)<x-ui.status-pill domain="severity" :status="$incident->severity" :label="$severities[$incident->severity].' severity'" />@else<x-ui.badge>Severity not rated</x-ui.badge>@endif
        @if ($incident->is_urgent)<x-ui.badge tone="danger">Urgent</x-ui.badge>@endif
        @if ($incident->is_anonymous)<x-ui.badge>Anonymous report</x-ui.badge>@elseif ($incident->is_confidential)<x-ui.badge>Confidential reporter</x-ui.badge>@endif
        @if ($internal && $incident->isAcknowledgementOverdue())<x-ui.badge tone="warning">Not acknowledged within {{ \App\Services\HealthSafety\HealthSafetySettings::value('hs_ack_hours') }} hours</x-ui.badge>@endif
        @if ($internal && $incident->status === 'investigating' && $incident->investigationDueOn())<x-ui.badge>Investigation due {{ $incident->investigationDueOn()->format('d M Y') }}</x-ui.badge>@endif
    </div>

    @error('status')<x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert>@enderror

    {{-- What the reporter filed --}}
    <x-ui.card title="Report" class="dash-row">
        <dl class="ui-form-grid">
            <div><dt class="ui-label">Where it happened</dt><dd>{{ \App\Models\HsIncident::CONTEXTS[$incident->context] ?? $incident->context }}: {{ $incident->placeLabel() }}</dd></div>
            <div><dt class="ui-label">When</dt><dd>{{ $incident->occurred_on->format('d M Y') }}@if ($incident->occurred_time), {{ substr((string) $incident->occurred_time, 0, 5) }}@endif</dd></div>
            <div class="span-2"><dt class="ui-label">What happened</dt><dd style="white-space:pre-line">{{ $incident->description }}</dd></div>
            <div><dt class="ui-label">First aid given</dt><dd>{{ \App\Models\HsIncident::FIRST_AID[$incident->first_aid] ?? $incident->first_aid }}</dd></div>
            <div>
                <dt class="ui-label">Witness</dt>
                <dd>
                    @if ($incident->no_witness)
                        No witness
                    @elseif ($incident->witness_name || $incident->witness_contact)
                        {{ $incident->witness_name }} {{ $incident->witness_contact }}
                    @else
                        <span class="cell-muted">Not given</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="ui-label">Reported by</dt>
                <dd>
                    {{ $reporter['name'] }}
                    @if ($reporter['recorded_by'])<span class="cell-muted">(recorded by {{ $reporter['recorded_by'] }})</span>@endif
                    <span class="cell-muted">on {{ $incident->created_at->format('d M Y H:i') }}</span>
                </dd>
            </div>
            @if ($internal)
                <div><dt class="ui-label">Owner</dt><dd>{{ $ownerName ?? 'Not assigned' }}</dd></div>
            @endif
        </dl>

        @if ($incident->attachments->isNotEmpty())
            <h3 class="ui-label" style="margin-top:14px">Photos</h3>
            <ul class="ui-tags">
                @foreach ($incident->attachments as $photo)
                    <li wire:key="hs-photo-{{ $photo->id }}"><a href="{{ route('health_safety.attachments.show', $photo) }}" target="_blank" rel="noopener">{{ $photo->original_name }}</a></li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    {{-- The outcome, for the reporter --}}
    @if ($incident->status === 'closed' && $canSeeClosureNote && filled($incident->closure_note))
        <x-ui.card title="Outcome" class="dash-row">
            <p style="white-space:pre-line">{{ $incident->closure_note }}</p>
            <p class="ui-hint">
                Closed {{ $incident->closed_at?->format('d M Y') }}@if ($closure && $closure['closed_by']) by {{ $closure['closed_by'] }}@endif
                @if ($closure && $closure['approved_by']), approved by {{ $closure['approved_by'] }}@if ($closure['approved_at']) on {{ $closure['approved_at']->format('d M Y') }}@endif @endif.
            </p>
        </x-ui.card>
    @endif

    {{-- Workflow buttons --}}
    @if ($canManage && in_array($incident->status, ['reported', 'acknowledged'], true))
        <div class="ui-form-actions dash-row">
            @if ($incident->status === 'reported')
                <x-ui.button variant="primary" icon="check" wire:click="acknowledge" loading="acknowledge">Acknowledge</x-ui.button>
            @endif
            <x-ui.button icon="play" wire:click="startInvestigation" loading="startInvestigation">Start investigation</x-ui.button>
        </div>
    @endif

    {{-- Triage --}}
    @if ($canManage && in_array($incident->status, ['reported', 'acknowledged', 'investigating'], true))
        <x-ui.card title="Triage" description="Rate the severity, correct the type if needed and choose who owns it." class="dash-row">
            <form wire:submit="saveTriage" class="ui-stack" novalidate>
                <div class="ui-form-grid">
                    <x-ui.select label="Severity" wire:model="severity" required hint="Low: no injury. Medium: first aid only. High: medical treatment or lost time. Critical: fatality, permanent disability or major spill.">
                        <option value="">Select severity</option>
                        @foreach ($severities as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Type" wire:model.live="incidentType">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                    @if ($incidentType === 'other')
                        <x-ui.input label="What kind of report is this?" wire:model="otherTypeText" />
                    @endif
                    <x-ui.select label="Owner" wire:model="ownerUserId" error="owner_user_id">
                        <option value="">Me</option>
                        @foreach ($owners as $owner)
                            <option value="{{ $owner->id }}">{{ $owner->full_name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <div class="ui-form-actions">
                    <x-ui.button type="submit" variant="primary" loading="saveTriage">Save triage</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- Investigation --}}
    @if ($internal && ($canManage || filled($incident->findings) || filled($incident->root_cause_category)))
        <x-ui.card title="Investigation" description="Internal: the reporter never sees the findings." class="dash-row">
            @if ($canManage && in_array($incident->status, ['acknowledged', 'investigating'], true))
                <form wire:submit="saveInvestigation" class="ui-stack" novalidate>
                    <div class="ui-form-grid">
                        <x-ui.select label="Root cause" wire:model="rootCause" error="root_cause_category" :required="$needsInvestigation">
                            <option value="">Not recorded</option>
                            @foreach ($rootCauses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                        <div class="span-2">
                            <x-ui.textarea label="Findings" wire:model="findings" rows="5" error="findings" :required="$needsInvestigation" />
                        </div>
                    </div>
                    <div class="ui-form-actions">
                        <x-ui.button type="submit" loading="saveInvestigation">Save findings</x-ui.button>
                    </div>
                </form>
            @else
                <dl class="ui-form-grid">
                    <div><dt class="ui-label">Root cause</dt><dd>{{ $incident->root_cause_category ? $rootCauses[$incident->root_cause_category] : 'Not recorded' }}</dd></div>
                    <div class="span-2"><dt class="ui-label">Findings</dt><dd style="white-space:pre-line">{{ $incident->findings ?: 'None recorded' }}</dd></div>
                </dl>
            @endif
        </x-ui.card>
    @endif

    {{-- Persons affected: health information, behind view_injury_details --}}
    @if ($canSeeInjury)
        <x-ui.card title="People affected" description="Health information: visible only to those allowed to see injury details." :padded="false" class="dash-row">
            <x-ui.table label="People affected">
                <x-slot:head>
                    <tr>
                        <th>Person</th><th>Injury</th><th>Treatment</th><th>First aider</th><th class="num">Days lost</th><th>Back at work</th>
                        @if ($canWriteInjury)<th class="actions"><span class="sr-only-text">Remove</span></th>@endif
                    </tr>
                </x-slot:head>
                @forelse ($persons as $affected)
                    <tr wire:key="hs-person-{{ $affected->id }}">
                        <td>{{ $affected->displayName() }}<br><span class="ui-person-sub">{{ $personTypes[$affected->person_type] ?? $affected->person_type }}</span></td>
                        <td>{{ $affected->injury_type }}@if ($affected->body_part) <span class="cell-muted">({{ $affected->body_part }})</span>@endif</td>
                        <td>{{ $treatments[$affected->treatment] ?? $affected->treatment }}</td>
                        <td>{{ $affected->first_aider_name }}</td>
                        <td class="num">{{ $affected->lost_time_days }}</td>
                        <td class="nowrap">{{ $affected->returned_to_work_on?->format('d M Y') }}</td>
                        @if ($canWriteInjury)
                            <td class="actions"><x-ui.button size="sm" variant="ghost" wire:click="removePerson({{ $affected->id }})" wire:confirm="Remove this person from the report?">Remove</x-ui.button></td>
                        @endif
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="$canWriteInjury ? 7 : 6" icon="user" title="Nobody recorded yet." />
                @endforelse
            </x-ui.table>

            @if ($canWriteInjury && in_array($incident->status, ['reported', 'acknowledged', 'investigating'], true))
                <form wire:submit="addPerson" class="ui-stack" style="padding:14px" novalidate>
                    <div class="ui-form-grid">
                        <x-ui.input label="Staff ID (if staff)" wire:model="person.staff_id" error="person.staff_id" />
                        <x-ui.input label="or name" wire:model="person.name_raw" error="person.name_raw" />
                        <x-ui.select label="Who" wire:model="person.person_type">
                            @foreach ($personTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.input label="Injury" wire:model="person.injury_type" />
                        <x-ui.input label="Body part" wire:model="person.body_part" />
                        <x-ui.select label="Treatment" wire:model="person.treatment">
                            @foreach ($treatments as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.input label="First aider" wire:model="person.first_aider_name" />
                        <x-ui.input type="number" min="0" label="Days lost" wire:model="person.lost_time_days" error="person.lost_time_days" />
                        <x-ui.input type="date" label="Back at work on" wire:model="person.returned_to_work_on" />
                    </div>
                    <div class="ui-form-actions">
                        <x-ui.button type="submit" icon="plus" loading="addPerson">Add person</x-ui.button>
                    </div>
                </form>
            @endif
        </x-ui.card>
    @endif

    {{-- Actions --}}
    @if ($internal)
        <x-ui.card title="Actions" description="Corrective and preventive actions. Open actions do not hold up closing the incident." :padded="false" class="dash-row">
            <x-ui.table label="Actions on this incident">
                <x-slot:head>
                    <tr><th>Action</th><th>Assigned to</th><th>Due</th><th>Status</th><th class="actions"><span class="sr-only-text">Update</span></th></tr>
                </x-slot:head>
                @forelse ($actions as $action)
                    @php $own = $viewerEmployeeId && (int) $action->assigned_to_employee_id === (int) $viewerEmployeeId; @endphp
                    <tr wire:key="hs-act-{{ $action->id }}">
                        <td>{{ $action->description }}@if ($action->completion_note)<br><span class="ui-person-sub">{{ $action->completion_note }}</span>@endif</td>
                        <td>{{ $action->assignee?->full_name }}</td>
                        <td class="nowrap">{{ $action->due_on->format('d M Y') }}@if ($action->isOverdue()) <x-ui.badge tone="danger">Overdue</x-ui.badge>@endif</td>
                        <td><x-ui.status-pill domain="hs-action" :status="$action->status" /></td>
                        <td class="actions">
                            @if ($action->status === 'open' && ($own || $canManage))
                                <input type="text" class="form-input form-input-sm" wire:model="completionNotes.{{ $action->id }}" placeholder="What was done (optional)" aria-label="Completion note for this action" maxlength="1000">
                                <x-ui.button size="sm" wire:click="completeAction({{ $action->id }})">Mark done</x-ui.button>
                            @elseif ($action->status === 'done' && $canManage)
                                <x-ui.button size="sm" wire:click="verifyAction({{ $action->id }})">Verify</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" icon="list-checks" title="No actions yet." />
                @endforelse
            </x-ui.table>

            @if ($canManage && $incident->status !== 'cancelled')
                <form wire:submit="createAction" class="ui-stack" style="padding:14px" novalidate>
                    <div class="ui-form-grid">
                        <div class="span-2"><x-ui.textarea label="New action" wire:model="newAction.description" rows="2" error="newAction.description" required /></div>
                        @if ($newAction['assigned_to_employee_id'])
                            <div>
                                <span class="ui-label">Assigned to</span>
                                <p>{{ $assigneeName }} <x-ui.button size="sm" variant="ghost" wire:click="clearAssignee">Change</x-ui.button></p>
                            </div>
                        @else
                            <div>
                                <x-ui.input label="Assign to (name or staff ID)" wire:model.live.debounce.300ms="assigneeSearch" error="newAction.assigned_to_employee_id" required />
                                @foreach ($assigneeMatches as $match)
                                    <x-ui.button size="sm" variant="ghost" wire:key="hs-assignee-{{ $match->id }}" wire:click="chooseAssignee({{ $match->id }})">{{ $match->full_name }} <span class="mono">{{ $match->staff_id }}</span></x-ui.button>
                                @endforeach
                            </div>
                        @endif
                        <x-ui.input type="date" label="Due on" wire:model="newAction.due_on" error="newAction.due_on" required />
                    </div>
                    <div class="ui-form-actions">
                        <x-ui.button type="submit" icon="plus" loading="createAction">Assign action</x-ui.button>
                    </div>
                </form>
            @endif
        </x-ui.card>

        {{-- Photos: officers can add to what the reporter attached --}}
        @if ($canManage && $incident->status !== 'cancelled')
            <x-ui.card title="Add photos" class="dash-row">
                <form wire:submit="uploadPhotos" class="ui-stack" novalidate>
                    <x-ui.field label="Photos" for="hs-new-photos" error="newPhotos" hint="Up to {{ $maxPhotos }} photos on an incident, {{ $maxMb }} MB each.">
                        <input id="hs-new-photos" type="file" class="form-input" wire:model="newPhotos" multiple accept="image/jpeg,image/png,image/webp">
                        @error('newPhotos.*')<p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>@enderror
                        @error('photos')<p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>@enderror
                    </x-ui.field>
                    <div class="ui-form-actions"><x-ui.button type="submit" icon="upload" loading="uploadPhotos">Upload</x-ui.button></div>
                </form>
            </x-ui.card>
        @endif
    @endif

    {{-- Closure --}}
    @if (($canManage && in_array($incident->status, ['acknowledged', 'investigating'], true)) || ($canApprove && $incident->status === 'pending_closure'))
        <x-ui.card title="Closure" :description="$incident->needsApprovalToClose() ? 'High and Critical incidents are closed by an approver.' : 'Write the outcome the reporter will see.'" class="dash-row">
            <div class="ui-stack">
                <x-ui.textarea label="Closure note (shown to the reporter)" wire:model="closureNote" rows="4" error="closure_note" required />
                @foreach (['severity', 'root_cause_category', 'findings'] as $field)
                    @error($field)<p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>@enderror
                @endforeach
                <div class="ui-form-actions">
                    @if ($incident->status === 'pending_closure')
                        @if ($approverBlocked)
                            <x-ui.alert tone="warning">You sent this incident for approval, so someone else has to approve it.</x-ui.alert>
                        @else
                            <x-ui.button variant="primary" icon="check" wire:click="approve" loading="approve">Approve and close</x-ui.button>
                        @endif
                        <x-ui.button wire:click="openReason('return')">Return for rework</x-ui.button>
                    @elseif ($incident->needsApprovalToClose())
                        <x-ui.button variant="primary" icon="send" wire:click="sendForApproval" loading="sendForApproval">Send for approval</x-ui.button>
                    @else
                        <x-ui.button variant="primary" icon="check" wire:click="close" loading="close">Close incident</x-ui.button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    @endif

    {{-- Cancel / reopen --}}
    @if ($canManage && ($incident->isOpen() || $incident->status === 'closed'))
        <div class="ui-form-actions dash-row">
            @if ($incident->isOpen())
                <x-ui.button variant="danger" wire:click="openReason('cancel')">Cancel this report</x-ui.button>
            @else
                <x-ui.button wire:click="openReason('reopen')">Reopen</x-ui.button>
            @endif
        </div>
    @endif

    @if ($reasonFor !== '')
        <x-ui.card :title="['cancel' => 'Why is it being cancelled?', 'reopen' => 'Why is it being reopened?', 'return' => 'What needs to change?'][$reasonFor]" class="dash-row">
            <div class="ui-stack">
                <x-ui.textarea label="Reason" wire:model="reason" rows="3" error="reason" required />
                <div class="ui-form-actions">
                    <x-ui.button wire:click="closeReason">Back</x-ui.button>
                    <x-ui.button variant="primary" wire:click="submitReason" loading="submitReason">Confirm</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif

    {{-- Timeline --}}
    <x-ui.card title="Timeline" class="dash-row">
        <ol class="ui-stack">
            @foreach ($timeline as $entry)
                <li wire:key="hs-log-{{ $loop->index }}">
                    <strong>{{ $statuses[$entry['to']] ?? $entry['to'] }}</strong>
                    <span class="cell-muted">{{ $entry['at']?->format('d M Y H:i') }}@if ($entry['by']) &middot; {{ $entry['by'] }}@endif</span>
                    @if ($entry['note'])<br><span>{{ $entry['note'] }}</span>@endif
                </li>
            @endforeach
        </ol>
    </x-ui.card>
</div>
