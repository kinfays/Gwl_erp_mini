<div>
    <x-ui.page-header :title="'Batch '.$batch->batch_no" :description="$wholeBatch ? 'A container Finance sent to Audit. Each voucher is still ticked or queried on its own.' : 'The vouchers of this batch that are yours. Each is still ticked or queried on its own.'">
        <x-slot:actions>
            <x-ui.button icon="arrow-left" :href="route('audit.batches')">All batches</x-ui.button>
            <x-ui.button icon="printer" :href="route('audit.batches.print', $batch->id)" target="_blank" rel="noopener">Print</x-ui.button>
            <x-ui.button icon="printer" :href="route('audit.batches.print', [$batch->id, 'pages' => 1])" target="_blank" rel="noopener">Print with each voucher</x-ui.button>
            @if ($mineWaiting > 0)
                <x-ui.button variant="primary" icon="check" wire:click="acknowledgeRemaining" loading="acknowledgeRemaining">Acknowledge all remaining ({{ $mineWaiting }})</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @foreach (['action.action', 'action.status', 'conflict'] as $key)
        @error($key)<x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert>@enderror
    @endforeach

    @if ($bulk)
        <x-ui.alert :tone="$bulk['skipped'] === [] && $bulk['not_yours'] === 0 ? 'success' : 'warning'" role="status" class="dash-row">
            {{ $bulk['acknowledged'] }} {{ \Illuminate\Support\Str::plural('voucher', $bulk['acknowledged']) }} acknowledged.
            @foreach ($bulk['skipped'] as $skipped)
                <div>Skipped PV {{ $skipped['pv_no'] }}: {{ $skipped['reason'] }}</div>
            @endforeach
            @if ($bulk['not_yours'] > 0)<div>{{ $bulk['not_yours'] }} more {{ \Illuminate\Support\Str::plural('voucher', $bulk['not_yours']) }} in this batch {{ $bulk['not_yours'] === 1 ? 'is' : 'are' }} with other officers.</div>@endif
            <div class="cell-muted">A query is always one voucher at a time: open the voucher to query it.</div>
        </x-ui.alert>
    @endif

    <x-ui.card title="{{ $batch->title ?: 'Batch '.$batch->batch_no }}" class="dash-row">
        <x-slot:actions><x-finance.batch-state :state="$state" /></x-slot:actions>
        <dl class="ui-dl">
            <div><dt>Batch number</dt><dd class="mono">{{ $batch->batch_no }}</dd></div>
            <div><dt>Location</dt><dd>{{ $batch->district?->district_name }}</dd></div>
            <div><dt>{{ $wholeBatch ? 'Vouchers' : 'Your vouchers' }}</dt><dd>{{ $members->count() }}, total GH¢ {{ $total }}</dd></div>
            <div>
                <dt>Progress</dt>
                <dd>{{ $state['done'] }} of {{ $state['total'] }} done (Completed or Voided)@if ($state['unassigned'] > 0)<span class="cell-muted">, {{ $state['unassigned'] }} not yet given to an officer</span>@endif</dd>
            </div>
            @if ($batch->sent_at)<div><dt>Sent</dt><dd>{{ $batch->sender?->full_name }}, {{ $batch->sent_at->format('d M Y H:i') }}</dd></div>@endif
        </dl>
    </x-ui.card>

    @if ($manages)
        <x-ui.card title="Assign" description="Give the whole batch to one officer, or tick vouchers below to give them to different officers. The same controls move a voucher from one officer to another." class="dash-row">
            @if ($waitingCount === 0)
                <p class="cell-muted">No voucher of this batch is waiting for Audit, so there is nothing to assign.</p>
            @else
                <div class="ui-form-grid">
                    <x-form.searchable-select label="Assign to" model="officerId" :options="$officerOptions" :reset-key="$pickedKey"
                        placeholder="Type an officer's name" empty-text="No officer can take all of these"
                        hint="Active Audit officers of this location, with how many vouchers they have open. Anyone who sent one of these vouchers is left out." />
                    <x-ui.input label="Note (optional)" wire:model="note" maxlength="500" hint="Shown on the voucher's timeline." />
                </div>
                @if ($choiceError)<x-ui.alert tone="warning" role="status">{{ $choiceError }}</x-ui.alert>@endif
                @foreach (['assign.vouchers', 'assign.officer', 'assign.note', 'assign.status', 'assign.batch'] as $key)
                    @error($key) <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                @endforeach
                <div class="ui-form-actions">
                    <x-ui.button variant="primary" icon="user-check" wire:click="assignWhole" loading="assignWhole">Assign the whole batch ({{ $waitingCount }})</x-ui.button>
                    <x-ui.button icon="user-check" wire:click="assignTicked" loading="assignTicked" :disabled="$tickedCount === 0">Assign ticked ({{ $tickedCount }})</x-ui.button>
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card :padded="false" title="Vouchers" class="dash-row">
        <x-ui.table label="Vouchers in this batch">
            <x-slot:head>
                <tr>
                    @if ($manages)<th><span class="sr-only-text">Select</span></th>@endif
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
            @forelse ($members as $row)
                @php($full = $openable->get($row->id))
                @php($marks = $full?->marks())
                <tr wire:key="m-{{ $row->id }}" @class(['is-voided' => $row->status === \App\Models\FinPaymentVoucher::VOIDED])>
                    @if ($manages)
                        <td>
                            <input type="checkbox" wire:model.live="selected" value="{{ $row->id }}" aria-label="Select voucher {{ $row->pv_no }}"
                                @disabled($row->status !== \App\Models\FinPaymentVoucher::WITH_AUDIT)
                                @if ($row->status !== \App\Models\FinPaymentVoucher::WITH_AUDIT) title="Only a voucher that is with Audit can be assigned" @endif>
                        </td>
                    @endif
                    <td class="mono">{{ $row->pv_no }}@if ($row->round > 1) <span class="cell-muted">(round {{ $row->round }})</span>@endif</td>
                    <td>
                        {{-- A voucher nobody has worked on is not the manager's to read: no payee, no details. --}}
                        @if ($full)
                            {{ $full->payee_name }}<div class="cell-muted">{{ $full->payeeKindLabel() }}</div>
                        @else
                            <span class="cell-muted">Opens after an officer has acted on it</span>
                        @endif
                    </td>
                    <td>{{ $row->category_label }}<div class="cell-muted">{{ $row->type_label }}</div></td>
                    <td class="num">{{ $row->amountFormatted() }}</td>
                    <td>{{ $row->statusLabel() }}</td>
                    <td>
                        @if ($row->assigned_to_user_id)
                            {{ $names[$row->assigned_to_user_id] ?? 'Unknown user' }}
                            <div class="cell-muted">by {{ $names[$row->assigned_by_user_id] ?? 'the manager' }}@if ($row->assigned_at), {{ $row->assigned_at->format('d M Y H:i') }}@endif</div>
                        @elseif ($row->status === \App\Models\FinPaymentVoucher::WITH_AUDIT)
                            <span class="cell-muted">Awaiting assignment</span>
                        @else
                            <span class="cell-muted">-</span>
                        @endif
                    </td>
                    <td>@if ($marks)<x-finance.mark :mark="$marks['audit']" party="Audit" />@else<span class="cell-muted">-</span>@endif</td>
                    <td>@if ($marks)<x-finance.mark :mark="$marks['finance']" party="Finance" />@else<span class="cell-muted">-</span>@endif</td>
                    <td class="nowrap">@if ($full)<x-ui.button size="sm" variant="ghost" :href="route('audit.pvs.show', $row->id)">Open</x-ui.button>@endif</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="$manages ? 10 : 9" icon="receipt" title="No voucher of this batch is yours." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    {{-- Who gave what to whom is the manager's to read; an officer is not told about other officers' vouchers. --}}
    @if ($wholeBatch)
    <x-ui.card title="History" class="dash-row">
        <ol class="fin-timeline">
            @foreach ($batch->events->reject(fn ($event) => in_array($event->action, ['created', 'pv_added', 'pv_removed', 'cancelled'], true)) as $event)
                <li wire:key="bev-{{ $event->id }}">
                    <strong>{{ $event->label() }}</strong>@if ($event->toUser) <span>to {{ $event->toUser->full_name }}</span>@endif
                    <span class="cell-muted">by {{ $event->actorName() }}, {{ $event->created_at->format('d M Y H:i') }}</span>
                    @if (filled($event->comment))<div style="white-space: pre-line">{{ $event->comment }}</div>@endif
                </li>
            @endforeach
        </ol>
    </x-ui.card>
    @endif
</div>
