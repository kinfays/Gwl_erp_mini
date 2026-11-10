{{-- The voucher itself, its marks, what changed since the last query, the timeline by round and the documents.
     Needs $pv (events.actor, category, type, attachments loaded) and $attachmentRoute. Shared by the Finance and the Audit screens. --}}
{{-- $payeeWarning (optional): PayeeStatusService's flag for an employee payee who has left service. --}}
@if (! empty($payeeWarning))
    <x-ui.alert tone="warning" title="Payee has left service" role="status" class="dash-row">{{ $payeeWarning['message'] }}</x-ui.alert>
@endif
@php($marks = $pv->marks())
@php($resubmission = $pv->round > 1 ? $pv->events->last(fn ($event) => $event->action === \App\Models\FinPvEvent::RESUBMITTED) : null)

<x-ui.card title="Voucher {{ $pv->pv_no }}" class="dash-row">
    <x-slot:actions>
        <span class="ui-badge ui-badge-neutral">{{ $pv->statusLabel() }}@if ($pv->round > 1), round {{ $pv->round }}@endif</span>
    </x-slot:actions>

    <dl class="ui-dl">
        <div><dt>PV date</dt><dd>{{ $pv->pv_date->format('d M Y') }}</dd></div>
        <div><dt>Payee</dt><dd>{{ $pv->payee_name }} <span class="cell-muted">({{ $pv->payeeKindLabel() }})</span></dd></div>
        <div><dt>Category</dt><dd>{{ $pv->category_label }}</dd></div>
        <div><dt>Type</dt><dd>{{ $pv->type_label }}</dd></div>
        <div><dt>Amount (GH¢)</dt><dd>{{ $pv->amountFormatted() }}</dd></div>
        <div><dt>Prepared by</dt><dd>{{ $pv->creator?->full_name }}</dd></div>
        @if ($pv->batch)
            <div>
                <dt>Batch</dt>
                <dd>
                    @if (! empty($batchRoute))<a href="{{ route($batchRoute, $pv->batch_id) }}" class="mono">{{ $pv->batch->batch_no }}</a>@else<span class="mono">{{ $pv->batch->batch_no }}</span>@endif
                    @if (filled($pv->batch->title)) <span class="cell-muted">{{ $pv->batch->title }}</span>@endif
                </dd>
            </div>
        @endif
        @if ($pv->reachedAudit())
            <div>
                <dt>Audit officer</dt>
                <dd>
                    @if ($pv->assignedTo)
                        {{ $pv->assignedTo->full_name }}
                        <span class="cell-muted">assigned by {{ $pv->assignedBy?->full_name ?? 'the Audit manager' }}@if ($pv->assigned_at), {{ $pv->assigned_at->format('d M Y H:i') }}@endif</span>
                    @elseif ($pv->status === \App\Models\FinPaymentVoucher::WITH_AUDIT)
                        <span class="cell-muted">Awaiting assignment: nobody in Audit can work on it until the Audit manager gives it to an officer.</span>
                    @else
                        <span class="cell-muted">-</span>
                    @endif
                </dd>
            </div>
        @endif
        <div><dt>Details</dt><dd style="white-space: pre-line">{{ $pv->details }}</dd></div>
        @if (filled($pv->remarks))
            <div><dt>Finance's remarks</dt><dd style="white-space: pre-line">{{ $pv->remarks }}</dd></div>
        @endif
        @if ($pv->isVoided())
            <div><dt>Voided</dt><dd>{{ $pv->voided_at?->format('d M Y H:i') }}, reason: {{ $pv->void_reason }}</dd></div>
        @endif
    </dl>

    <div class="ui-form-grid">
        <div><strong>Audit</strong> <x-finance.mark :mark="$marks['audit']" party="Audit" /></div>
        <div><strong>Finance</strong> <x-finance.mark :mark="$marks['finance']" party="Finance" /></div>
    </div>
</x-ui.card>

@if ($resubmission)
    <x-ui.card title="Changed since Audit's last query" :description="'Resubmitted by '.$resubmission->actorName().' on '.$resubmission->created_at->format('d M Y H:i').' (round '.$resubmission->round.').'" class="dash-row">
        @if (filled($resubmission->comment))
            <p><strong>Finance's reply:</strong> {{ $resubmission->comment }}</p>
        @endif
        @if (! empty($resubmission->changes))
            <x-ui.table label="Changes since the last query" class="fin-diff">
                <x-slot:head><tr><th>Field</th><th>Before</th><th>Now</th></tr></x-slot:head>
                @foreach ($resubmission->changes as $change)
                    <tr><td>{{ $change['field'] }}</td><td><del>{{ $change['old'] ?: '(empty)' }}</del></td><td>{{ $change['new'] ?: '(empty)' }}</td></tr>
                @endforeach
            </x-ui.table>
        @else
            <p class="cell-muted">Nothing on the voucher was changed.</p>
        @endif
    </x-ui.card>
@endif

<x-ui.card title="Supporting documents" class="dash-row">
    @forelse ($pv->attachments as $attachment)
        <div wire:key="doc-{{ $attachment->id }}">
            <x-ui.icon name="paperclip" />
            <a href="{{ route($attachmentRoute, [$pv->id, $attachment->id]) }}" target="_blank" rel="noopener">{{ $attachment->display_name }}</a>
            <span class="cell-muted">{{ number_format($attachment->size / 1024) }} KB</span>
        </div>
    @empty
        <p class="cell-muted">No documents attached.</p>
    @endforelse
</x-ui.card>

<x-ui.card title="Timeline" class="dash-row">
    @forelse ($pv->events->groupBy('round') as $round => $events)
        <h3>Round {{ $round }}</h3>
        <ol class="fin-timeline">
            @foreach ($events as $event)
                <li wire:key="ev-{{ $event->id }}">
                    <strong>{{ $event->label() }}</strong>@if ($event->toUser) <span>to {{ $event->toUser->full_name }}</span>@endif
                    <span class="cell-muted">by {{ $event->actorName() }}, {{ $event->created_at->format('d M Y H:i') }}</span>
                    @if ($event->reasonLabel())<div>Reason: {{ $event->reasonLabel() }}</div>@endif
                    @if (filled($event->comment))<div style="white-space: pre-line">{{ $event->comment }}</div>@endif
                </li>
            @endforeach
        </ol>
    @empty
        <p class="cell-muted">Nothing has happened to this voucher yet.</p>
    @endforelse
</x-ui.card>
