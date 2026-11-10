<div>
    <x-ui.page-header :title="'Batch '.$batch->batch_no" description="A container for sending the vouchers to Audit together. Audit still ticks or queries each voucher on its own.">
        <x-slot:actions>
            <x-ui.button icon="arrow-left" :href="route('finance.batches')">All batches</x-ui.button>
            <x-ui.button icon="printer" :href="route('finance.batches.print', $batch->id)" target="_blank" rel="noopener">Print</x-ui.button>
            <x-ui.button icon="printer" :href="route('finance.batches.print', [$batch->id, 'pages' => 1])" target="_blank" rel="noopener">Print with each voucher</x-ui.button>
            @if ($canManage)
                <x-ui.button icon="pencil" wire:click="$set('showEdit', true)">Edit title</x-ui.button>
                <x-ui.button icon="plus" wire:click="toggleAdd">{{ $showAdd ? 'Hide the list' : 'Add vouchers' }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="openCancel">Cancel batch</x-ui.button>
            @endif
            @if ($canSend)<x-ui.button variant="primary" icon="send" wire:click="openSend">Send to Audit</x-ui.button>@endif
            @if ($canAcknowledgeAll)
                <x-ui.button variant="primary" icon="check" wire:click="acknowledgeAll" loading="acknowledgeAll">Acknowledge all ({{ $waitingToAcknowledge }})</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @foreach (['action.batch', 'action.vouchers', 'action.title', 'action.remarks', 'action.status', 'action.action'] as $key)
        @if ($errors->has($key) && ! $showSend)
            <x-ui.alert tone="danger" role="alert" class="dash-row">
                @foreach ($errors->get($key) as $message)<div>{{ $message }}</div>@endforeach
            </x-ui.alert>
        @endif
    @endforeach
    @error('conflict')
        <x-ui.alert tone="warning" role="alert" class="dash-row">
            {{ $message }}
            <x-slot:actions><x-ui.button size="sm" wire:click="$refresh">Reload the latest</x-ui.button></x-slot:actions>
        </x-ui.alert>
    @enderror

    @if ($bulk)
        <x-ui.alert :tone="$bulk['skipped'] === [] ? 'success' : 'warning'" role="status" class="dash-row">
            {{ $bulk['acknowledged'] }} {{ \Illuminate\Support\Str::plural('voucher', $bulk['acknowledged']) }} acknowledged.
            @foreach ($bulk['skipped'] as $skipped)
                <div>Skipped PV {{ $skipped['pv_no'] }}: {{ $skipped['reason'] }}</div>
            @endforeach
        </x-ui.alert>
    @endif

    @if ($batch->isCancelled())
        <x-ui.alert tone="warning" title="This batch was cancelled" class="dash-row">
            {{ $batch->cancelled_at?->format('d M Y H:i') }}. Reason: {{ $batch->cancel_reason }}
        </x-ui.alert>
    @endif

    <x-ui.card title="{{ $batch->title ?: 'Batch '.$batch->batch_no }}" class="dash-row">
        <x-slot:actions><x-finance.batch-state :state="$state" /></x-slot:actions>
        <dl class="ui-dl">
            <div><dt>Batch number</dt><dd class="mono">{{ $batch->batch_no }}</dd></div>
            <div><dt>Location</dt><dd>{{ $batch->district?->district_name }}</dd></div>
            <div><dt>Vouchers</dt><dd>{{ $members->count() }}, total GH¢ {{ $total }}</dd></div>
            <div>
                <dt>Progress</dt>
                <dd>
                    @if ($batch->isSent())
                        {{ $state['done'] }} of {{ $state['total'] }} done (Completed or Voided)
                        @if ($state['unassigned'] > 0)<span class="cell-muted">, {{ $state['unassigned'] }} not yet given to an Audit officer</span>@endif
                    @else
                        <span class="cell-muted">Not sent to Audit yet.</span>
                    @endif
                </dd>
            </div>
            <div><dt>Created by</dt><dd>{{ $batch->creator?->full_name }}, {{ $batch->created_at?->format('d M Y H:i') }}</dd></div>
            @if ($batch->sent_at)<div><dt>Sent</dt><dd>{{ $batch->sender?->full_name }}, {{ $batch->sent_at->format('d M Y H:i') }}</dd></div>@endif
            @if (filled($batch->remarks))<div><dt>Remarks</dt><dd style="white-space: pre-line">{{ $batch->remarks }}</dd></div>@endif
        </dl>
    </x-ui.card>

    @if ($canManage && $showAdd)
        <x-ui.card title="Add vouchers" :description="'Drafts of this location that are in no batch (the newest 50). A batch holds at most '.$cap.' vouchers.'" class="dash-row">
            @forelse ($candidates as $candidate)
                <x-ui.checkbox wire:key="cand-{{ $candidate->id }}" wire:model.live="addIds" value="{{ $candidate->id }}"
                    label="PV {{ $candidate->pv_no }}: {{ $candidate->payee_name }}, GH¢ {{ $candidate->amountFormatted() }}"
                    description="{{ $candidate->category_label }} / {{ $candidate->type_label }}, {{ $candidate->pv_date->format('d M Y') }}" />
            @empty
                <p class="cell-muted">There are no free drafts to add.</p>
            @endforelse
            <div class="ui-form-actions">
                <x-ui.button variant="primary" wire:click="addSelected" loading="addSelected" :disabled="count($addIds) === 0">Add selected ({{ count($addIds) }})</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card :padded="false" title="Vouchers" class="dash-row">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button size="sm" wire:click="removeSelected" loading="removeSelected" :disabled="count($removeIds) === 0">Take selected out ({{ count($removeIds) }})</x-ui.button>
            </x-slot:actions>
        @endif
        <x-ui.table label="Vouchers in this batch">
            <x-slot:head>
                <tr>
                    @if ($canManage)<th><span class="sr-only-text">Select</span></th>@endif
                    <th>PV no.</th>
                    <th>Payee</th>
                    <th>Category / type</th>
                    <th class="num">Amount (GH¢)</th>
                    <th>Status</th>
                    <th>Audit officer</th>
                    <th>Audit</th>
                    <th>Finance</th>
                    <th><span class="sr-only-text">Open</span></th>
                </tr>
            </x-slot:head>
            @forelse ($members as $pv)
                @php($marks = $pv->marks())
                <tr wire:key="member-{{ $pv->id }}" @class(['is-voided' => $pv->isVoided()])>
                    @if ($canManage)
                        <td><input type="checkbox" wire:model.live="removeIds" value="{{ $pv->id }}" aria-label="Select PV {{ $pv->pv_no }} to take out of the batch"></td>
                    @endif
                    <td class="mono">{{ $pv->pv_no }}@if ($pv->round > 1) <span class="cell-muted">(round {{ $pv->round }})</span>@endif</td>
                    <td>{{ $pv->payee_name }}<div class="cell-muted">{{ $pv->payeeKindLabel() }}</div></td>
                    <td>{{ $pv->category_label }}<div class="cell-muted">{{ $pv->type_label }}</div></td>
                    <td class="num">{{ $pv->amountFormatted() }}</td>
                    <td>{{ $pv->statusLabel() }}</td>
                    <td>
                        @if ($pv->assignedTo)
                            {{ $pv->assignedTo->full_name }}
                        @elseif ($pv->status === \App\Models\FinPaymentVoucher::WITH_AUDIT)
                            <span class="cell-muted">Awaiting assignment</span>
                        @else
                            <span class="cell-muted">-</span>
                        @endif
                    </td>
                    <td><x-finance.mark :mark="$marks['audit']" party="Audit" /></td>
                    <td><x-finance.mark :mark="$marks['finance']" party="Finance" /></td>
                    <td class="nowrap"><x-ui.button size="sm" variant="ghost" :href="route('finance.pvs.show', $pv->id)">Open</x-ui.button></td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="$canManage ? 10 : 9" icon="receipt" title="This batch has no vouchers." description="Add some, or cancel the batch." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="History" class="dash-row">
        <ol class="fin-timeline">
            @foreach ($batch->events as $event)
                <li wire:key="bev-{{ $event->id }}">
                    <strong>{{ $event->label() }}</strong>@if ($event->pv) <span class="mono">PV {{ $event->pv->pv_no }}</span>@endif
                    <span class="cell-muted">by {{ $event->actorName() }}, {{ $event->created_at->format('d M Y H:i') }}</span>
                    @if ($event->toUser)<div>To {{ $event->toUser->full_name }}</div>@endif
                    @if (filled($event->comment))<div style="white-space: pre-line">{{ $event->comment }}</div>@endif
                </li>
            @endforeach
        </ol>
    </x-ui.card>

    @if ($showEdit)
        <x-ui.modal title="Title and remarks" close="$set('showEdit', false)">
            <x-ui.input label="Title (optional)" wire:model="title" maxlength="150" />
            <x-ui.textarea label="Remarks (optional)" wire:model="remarks" rows="3" maxlength="1000" />
            <x-slot:footer>
                <x-ui.button wire:click="$set('showEdit', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" wire:click="saveDetails">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($showSend)
        <x-ui.modal title="Send this batch to Audit" description="All or nothing: if one voucher cannot be sent, none is, and you are told which. Once sent, the vouchers in the batch are frozen." close="$set('showSend', false)">
            <p><strong>{{ $members->count() }}</strong> {{ \Illuminate\Support\Str::plural('voucher', $members->count()) }}, total <strong>GH¢ {{ $total }}</strong>.</p>
            @if ($errors->has('action.batch') || $errors->has('action.vouchers'))
                <x-ui.alert tone="danger" role="alert">
                    @foreach (array_merge($errors->get('action.batch'), $errors->get('action.vouchers')) as $message)<div>{{ $message }}</div>@endforeach
                </x-ui.alert>
            @endif
            <x-slot:footer>
                <x-ui.button wire:click="$set('showSend', false)">Close</x-ui.button>
                <x-ui.button variant="primary" icon="send" wire:click="confirmSend" loading="confirmSend">Send batch</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($showCancel)
        <x-ui.modal title="Cancel this batch" description="The vouchers are released as drafts and nothing is deleted. The batch stays on record, marked cancelled, with your reason." close="$set('showCancel', false)">
            <x-ui.textarea label="Reason" wire:model="cancelReason" rows="3" maxlength="500" />
            @error('cancel.reason') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            @error('cancel.batch') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="$set('showCancel', false)">Keep the batch</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmCancel">Cancel batch</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
