<div>
    <x-ui.page-header title="Payments to G Water" description="Payment requests and payments, and what is owed: the opening amount owed + every trip at the cost it was bought at - payments marked paid." />

    @include('livewire.gwater.partials.location-picker')

    @if ($location && $statement)
        <div class="ui-stat-grid dash-row">
            <x-ui.stat-tile label="Owed to G Water" :value="\App\Services\GWater\Money::format($statement['owed'])" icon="landmark" meta="GH¢" />
            <x-ui.stat-tile label="Brought forward" :value="\App\Services\GWater\Money::format($statement['opening'])" icon="clipboard-list" tone="muted" />
            <x-ui.stat-tile label="Bought on trips" :value="\App\Services\GWater\Money::format($statement['purchases'])" icon="boxes" tone="muted" />
            <x-ui.stat-tile label="Paid" :value="\App\Services\GWater\Money::format($statement['paid'])" icon="banknote" tone="muted" />
            <x-ui.stat-tile label="Requested, not yet paid" :value="\App\Services\GWater\Money::format($statement['requested'])" icon="hourglass" tone="muted" />
        </div>

        @if ($canRecord)
            <x-ui.card title="Record a payment request" description="Type the packs and prices, or only the amount. Tick 'already paid' for a payment that has been made." class="dash-row">
                <div class="ui-form-grid">
                    <x-ui.input label="Request date" type="date" wire:model.defer="form.request_date" :max="today()->toDateString()" />
                    <x-ui.input label="Reference" wire:model.defer="form.reference" maxlength="60" class="mono" />
                    @foreach ($products as $product)
                        <x-ui.input :label="'Packs of '.strtolower($product->code).' paid for'" type="number" min="0" step="1" inputmode="numeric" wire:model.defer="packs.{{ $product->id }}" />
                        <x-ui.input :label="'Price per pack of '.strtolower($product->code).' (GH¢, optional)'" wire:model.defer="prices.{{ $product->id }}" inputmode="decimal" hint="Empty = the cost price in force." />
                    @endforeach
                    <x-ui.input label="Amount (GH¢)" wire:model.defer="form.amount" inputmode="decimal" hint="Empty when you typed packs: it is worked out." />
                    <x-ui.input label="Note (optional)" wire:model.defer="form.note" maxlength="255" />
                    <div class="field-check">
                        <x-ui.checkbox label="Already paid" wire:model.live="form.already_paid" id="f-payment-paid" />
                    </div>
                    @if ($form['already_paid'])
                        <x-ui.input label="Paid on" type="date" wire:model.defer="form.paid_on" :max="today()->toDateString()" />
                    @endif
                </div>
                @error('form.lines') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                @error('form.location') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                <div class="ui-form-actions">
                    <x-ui.button variant="primary" icon="check" wire:click="save" loading="save">Record payment</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card title="Payment requests" :padded="false" class="dash-row">
            <x-ui.table label="Payments to G Water">
                <x-slot:head>
                    <tr><th>Requested</th><th>Reference</th><th>Packs</th><th class="num">Amount</th><th>Paid on</th><th>Status</th><th><span class="sr-only-text">Actions</span></th></tr>
                </x-slot:head>
                @forelse ($payments as $payment)
                    <tr wire:key="payment-{{ $payment->id }}" @class(['is-voided' => $payment->isVoided()])>
                        <td class="nowrap">{{ $payment->request_date->format('d M Y') }}</td>
                        <td class="mono">{{ $payment->reference }}</td>
                        <td>
                            @forelse ($payment->lines as $line)
                                <div>{{ number_format($line->packs) }} × {{ $line->product->name }} <span class="cell-muted">@ {{ \App\Services\GWater\Money::format($line->unit_price) }}</span></div>
                            @empty
                                <span class="cell-muted">-</span>
                            @endforelse
                        </td>
                        <td class="num">{{ \App\Services\GWater\Money::format($payment->amount) }}</td>
                        <td class="nowrap">{{ $payment->paid_on?->format('d M Y') ?? '-' }}</td>
                        <td>
                            @if ($payment->isVoided())
                                <x-ui.badge tone="danger">Voided</x-ui.badge><div class="cell-muted">{{ $payment->void_reason }}</div>
                            @elseif ($payment->status === 'paid')
                                <x-ui.badge tone="success">Paid</x-ui.badge>
                            @else
                                <x-ui.badge tone="warning">Requested</x-ui.badge>
                            @endif
                        </td>
                        <td class="nowrap">
                            @if ($canRecord && $payment->status === 'requested')
                                <x-ui.button size="sm" wire:click="openPay({{ $payment->id }})">Mark paid</x-ui.button>
                            @endif
                            @if ($canVoid && ! $payment->isVoided())
                                <x-ui.button size="sm" variant="ghost" wire:click="openVoid({{ $payment->id }})">Void</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="7" icon="landmark" title="No payment requests yet." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Statement of the account" description="Oldest first, with the balance after each line." :padded="false" class="dash-row">
            <x-ui.table label="Owed balance" :sticky="false" dense>
                <x-slot:head>
                    <tr><th>Date</th><th>Entry</th><th class="num">Owed (+)</th><th class="num">Paid (-)</th><th class="num">Balance</th></tr>
                </x-slot:head>
                @forelse ($statement['lines'] as $line)
                    <tr wire:key="stmt-{{ $line['kind'] }}-{{ $line['id'] }}">
                        <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($line['date'])->format('d M Y') }}</td>
                        <td>{{ $line['label'] }}</td>
                        <td class="num">{{ $line['charge'] ? \App\Services\GWater\Money::format($line['charge']) : '' }}</td>
                        <td class="num">{{ $line['payment'] ? \App\Services\GWater\Money::format($line['payment']) : '' }}</td>
                        <td class="num"><strong>{{ \App\Services\GWater\Money::format($line['balance']) }}</strong></td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" icon="landmark" title="Nothing owed yet." />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($payingId)
        <x-ui.modal title="Mark as paid" description="The payment reduces what is owed from the day it was paid." close="cancelPay">
            <x-ui.input label="Paid on" type="date" wire:model.defer="paidOn" :max="today()->toDateString()" />
            @error('pay.paid_on') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="cancelPay">Cancel</x-ui.button>
                <x-ui.button variant="primary" wire:click="pay">Mark paid</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($voidingId)
        <x-ui.modal title="Void this payment" description="It stops counting. If it was paid, the amount is owed again. It stays in the books, marked voided." close="cancelVoid">
            <x-ui.textarea label="Reason" wire:model.defer="voidReason" rows="3" maxlength="500" />
            @error('void.reason') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="cancelVoid">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmVoid">Void payment</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
