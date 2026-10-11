<div>
    <x-ui.page-header title="Expenses" description="Fuel, loading, offloading and other costs. Only fuel paid out of pocket is paid back; loading and offloading are paid in cash. Fuel is counted once per trip." />

    @include('livewire.gwater.partials.location-picker')
    @include('livewire.gwater.partials.exports')

    @if ($location)
        @if ($canRecord)
            <x-ui.card title="Record an expense" class="dash-row">
                <div class="ui-form-grid">
                    <x-ui.input label="Date" type="date" wire:model.defer="form.expense_date" :max="today()->toDateString()" />
                    <x-ui.select label="Category" wire:model.live="form.category">
                        @foreach ($categories as $category)
                            <option value="{{ $category }}">{{ ucfirst($category) }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Amount (GH¢)" wire:model.defer="form.amount" inputmode="decimal" />
                    <x-ui.select label="Trip (optional)" wire:model.defer="form.purchase_id">
                        <option value="">Not tied to a trip</option>
                        @foreach ($trips as $trip)
                            <option value="{{ $trip->id }}">{{ $trip->purchase_date->format('d M Y') }} · waybill {{ $trip->waybill_no }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select label="Vehicle (optional, from Transport)" wire:model.defer="form.vehicle_id">
                        <option value="">No vehicle</option>
                        @foreach ($vehicles as $vehicle)
                            <option value="{{ $vehicle->id }}">{{ $vehicle->number_plate }} - {{ $vehicle->brand }} {{ $vehicle->model }}</option>
                        @endforeach
                    </x-ui.select>
                    @if ($form['category'] === 'fuel')
                        <x-ui.select label="Paid out of pocket by" wire:model.defer="form.paid_by_user_id" hint="Leave empty if the company paid. The person is paid back on the Reimbursements screen.">
                            <option value="">Paid by the company</option>
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}">{{ $person->full_name ?? $person->email }}</option>
                            @endforeach
                        </x-ui.select>
                    @endif
                    <x-ui.input label="Paid to (optional)" wire:model.defer="form.payee" maxlength="120" />
                    <x-ui.input label="Receipt reference (optional)" wire:model.defer="form.receipt_ref" maxlength="60" />
                    <div class="span-2"><x-ui.input label="Note (optional)" wire:model.defer="form.note" /></div>
                </div>
                @error('form.location') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                <div class="ui-form-actions">
                    <x-ui.button variant="primary" icon="check" wire:click="save" loading="save">Record expense</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card class="dash-row">
            <div class="ui-form-grid">
                <x-ui.input label="From" type="date" wire:model.live="from" />
                <x-ui.input label="To" type="date" wire:model.live="to" />
                <x-ui.select label="Category" wire:model.live="category">
                    <option value="">All</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option }}">{{ ucfirst($option) }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="ui-stat-grid" style="margin-top:12px">
                @foreach ($categories as $option)
                    <x-ui.stat-tile :label="ucfirst($option)" :value="\App\Services\GWater\Money::format($totals[$option] ?? 0)" icon="receipt" meta="GH¢" tone="muted" />
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card :padded="false" class="dash-row">
            <x-ui.table label="Expenses">
                <x-slot:head>
                    <tr><th>Date</th><th>Category</th><th class="num">Amount</th><th>Trip</th><th>Paid by</th><th>Receipt</th><th>Status</th><th><span class="sr-only-text">Actions</span></th></tr>
                </x-slot:head>
                @forelse ($expenses as $expense)
                    <tr wire:key="expense-{{ $expense->id }}" @class(['is-voided' => $expense->isVoided()])>
                        <td class="nowrap">{{ $expense->expense_date->format('d M Y') }}</td>
                        <td>{{ ucfirst($expense->category) }}@if ($expense->vehicle) <div class="cell-muted mono">{{ $expense->vehicle->number_plate }}</div>@endif</td>
                        <td class="num">{{ \App\Services\GWater\Money::format($expense->amount) }}</td>
                        <td class="mono">{{ $expense->purchase?->waybill_no ?? '-' }}</td>
                        <td>
                            {{ $expense->paidBy?->full_name ?? '-' }}
                            @if ($expense->reimbursement_status === 'pending') <x-ui.badge tone="warning">To pay back</x-ui.badge>
                            @elseif ($expense->reimbursement_status === 'reimbursed') <x-ui.badge tone="success">Paid back</x-ui.badge>
                            @endif
                        </td>
                        <td class="cell-muted">{{ $expense->receipt_ref }}</td>
                        <td>
                            @if ($expense->isVoided())
                                <x-ui.badge tone="danger">Voided</x-ui.badge>
                                <div class="cell-muted">{{ $expense->void_reason }}</div>
                            @else
                                <x-ui.badge tone="success">Posted</x-ui.badge>
                            @endif
                        </td>
                        <td class="nowrap">
                            @if ($canVoid && ! $expense->isVoided() && $expense->reimbursement_status !== 'reimbursed')
                                <x-ui.button size="sm" variant="ghost" wire:click="openVoid({{ $expense->id }})">Void</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="8" icon="receipt" title="No expenses in this period." />
                @endforelse
                <x-slot:footer><div>{{ $expenses->links() }}</div></x-slot:footer>
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($voidingId)
        <x-ui.modal title="Void this expense" description="The entry stays in the books, marked voided, with your reason." close="cancelVoid">
            <x-ui.textarea label="Reason" wire:model.defer="voidReason" rows="3" maxlength="500" />
            @error('void.reason') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="cancelVoid">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmVoid">Void expense</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
