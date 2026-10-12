<div>
    <x-ui.page-header title="Daily sales" description="A day's packs per product at the selling price in force that day. A sale is paid at once or on credit, for a customer who pays later. To change a price, add it on the Prices screen." />

    @include('livewire.gwater.partials.location-picker')
    @include('livewire.gwater.partials.exports')

    @if ($location)
        @if ($canRecord)
            <x-ui.card title="Record sales" class="dash-row">
                <div class="ui-form-grid">
                    <x-ui.input label="Date" type="date" wire:model.live="form.sale_date" :max="today()->toDateString()" />
                    <x-ui.select label="Product" wire:model.live="form.product_id">
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Packs sold" type="number" min="1" step="1" inputmode="numeric" wire:model.live="form.packs" />
                    <x-ui.field label="Selling price per pack (GH¢)" for="f-sale-price" hint="From the price list; not editable here.">
                        <input id="f-sale-price" class="form-input" readonly value="{{ $price !== null ? \App\Services\GWater\Money::format($price) : 'No price on the list' }}">
                    </x-ui.field>
                    <x-ui.field label="Amount (GH¢)" for="f-sale-amount">
                        <input id="f-sale-amount" class="form-input" readonly value="{{ $price !== null && (int) $form['packs'] > 0 ? \App\Services\GWater\Money::format($price * (int) $form['packs']) : '' }}">
                    </x-ui.field>
                    <x-ui.select label="Paid" wire:model.live="form.payment_type">
                        <option value="cash">At once (cash)</option>
                        <option value="credit">On credit: the customer pays later</option>
                    </x-ui.select>
                    @if ($form['payment_type'] === 'credit')
                        <x-ui.input label="Customer's name" wire:model.defer="form.customer_name" maxlength="120" hint="Who will pay later." />
                        <x-ui.input label="Customer's phone (optional)" wire:model.defer="form.customer_phone" maxlength="30" inputmode="tel" />
                    @endif
                    <x-ui.input label="Note (optional)" wire:model.defer="form.note" maxlength="255" />
                </div>
                @error('form.location') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
                <div class="ui-form-actions">
                    <x-ui.button variant="primary" icon="check" wire:click="save" loading="save">{{ $form['payment_type'] === 'credit' ? 'Record credit sale' : 'Record sale' }}</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        @if ($owing->isNotEmpty())
            <x-ui.card title="Who owes" :description="'GH¢ '.\App\Services\GWater\Money::format($owedByCustomers['total']).' owed by '.$owedByCustomers['customers'].' customer(s) on '.$owedByCustomers['count'].' credit sale(s); the oldest debt is '.$owedByCustomers['oldest_days'].' day(s) old.'" :padded="false" class="dash-row">
                <x-ui.table label="Customers who owe" :sticky="false" dense>
                    <x-slot:head><tr><th>Customer</th><th>Phone</th><th class="num">Sales</th><th class="num">Owes (GH¢)</th><th>Oldest sale</th></tr></x-slot:head>
                    @foreach ($owing as $row)
                        <tr wire:key="owing-{{ \Illuminate\Support\Str::slug($row['customer']) }}">
                            <td>{{ $row['customer'] }}</td>
                            <td class="mono">{{ $row['phone'] }}</td>
                            <td class="num">{{ $row['sales'] }}</td>
                            <td class="num"><strong>{{ \App\Services\GWater\Money::format($row['owed']) }}</strong></td>
                            <td class="nowrap">{{ $row['oldest']->format('d M Y') }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif

        <x-ui.card class="dash-row">
            <div class="ui-form-grid">
                <x-ui.input label="From" type="date" wire:model.live="from" />
                <x-ui.input label="To" type="date" wire:model.live="to" />
                <x-ui.select label="Show" wire:model.live="show">
                    <option value="">All sales</option>
                    <option value="credit">Credit sales</option>
                    <option value="owing">Credit sales still owing</option>
                </x-ui.select>
            </div>
            <div class="ui-stat-grid" style="margin-top:12px">
                @foreach ($products as $product)
                    @php($rows = $totals[$product->id] ?? collect())
                    <x-ui.stat-tile :label="$product->name" :value="number_format($rows->sum('packs'))" icon="banknote" :meta="'packs · GH¢ '.\App\Services\GWater\Money::format($rows->sum('amount'))" tone="muted" />
                @endforeach
                <x-ui.stat-tile label="Owed by customers" :value="\App\Services\GWater\Money::format($owedByCustomers['total'])" icon="hourglass" meta="on credit sales, now" :tone="$owedByCustomers['total'] > 0 ? 'primary' : 'muted'" />
            </div>
        </x-ui.card>

        <x-ui.card :padded="false" class="dash-row">
            <x-ui.table label="Sales">
                <x-slot:head>
                    <tr>
                        <th>Date</th><th>Product</th><th class="num">Packs</th><th class="num">Price</th><th class="num">Amount</th><th>Customer</th><th class="num">Credit (owed)</th><th>By</th><th>Status</th>
                        <th><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>
                @forelse ($sales as $sale)
                    @php($owed = $sale->balanceOwed())
                    <tr wire:key="sale-{{ $sale->id }}" @class(['is-voided' => $sale->isVoided()])>
                        <td class="nowrap">{{ $sale->sale_date->format('d M Y') }}</td>
                        <td>{{ $sale->product->name }}</td>
                        <td class="num">{{ number_format($sale->packs) }}</td>
                        <td class="num">{{ \App\Services\GWater\Money::format($sale->unit_price) }}</td>
                        <td class="num">{{ \App\Services\GWater\Money::format($sale->amount) }}</td>
                        <td>
                            @if ($sale->isCredit())
                                {{ $sale->customer_name }}
                                @if ($sale->customer_phone)<div class="cell-muted mono">{{ $sale->customer_phone }}</div>@endif
                            @else
                                <span class="cell-muted">Paid at once</span>
                            @endif
                        </td>
                        <td class="num">
                            @if ($sale->isCredit())
                                @if ($sale->isVoided())
                                    <span class="cell-muted">-</span>
                                @elseif ($owed === 0)
                                    <x-ui.badge tone="success">Paid in full</x-ui.badge>
                                @else
                                    <strong>{{ \App\Services\GWater\Money::format($owed) }}</strong>
                                    <div><x-ui.badge tone="{{ $sale->receivedAmount() > 0 ? 'warning' : 'danger' }}">{{ $sale->receivedAmount() > 0 ? 'Part paid' : 'Unpaid' }}</x-ui.badge></div>
                                @endif
                                @foreach ($sale->receipts as $receipt)
                                    <div class="cell-muted" wire:key="rc-{{ $receipt->id }}">
                                        {{ $receipt->received_on->format('d M') }}: {{ \App\Services\GWater\Money::format($receipt->amount) }}@if ($receipt->reference) ({{ $receipt->reference }})@endif
                                        @if ($canVoid)<button type="button" class="btn btn-ghost btn-sm" wire:click="openVoidReceipt({{ $receipt->id }})" title="Void this payment">void</button>@endif
                                    </div>
                                @endforeach
                            @else
                                <span class="cell-muted">-</span>
                            @endif
                        </td>
                        <td class="cell-muted">{{ $sale->creator?->full_name }}</td>
                        <td>
                            @if ($sale->isVoided())
                                <x-ui.badge tone="danger" title="{{ $sale->void_reason }}">Voided</x-ui.badge>
                                <div class="cell-muted">{{ $sale->void_reason }}</div>
                            @else
                                <x-ui.badge tone="success">Posted</x-ui.badge>
                            @endif
                        </td>
                        <td class="nowrap">
                            @if ($canRecord && $owed > 0)
                                <x-ui.button size="sm" wire:click="openReceive({{ $sale->id }})">Receive payment</x-ui.button>
                            @endif
                            @if ($canVoid && ! $sale->isVoided())
                                <x-ui.button size="sm" variant="ghost" wire:click="openVoid({{ $sale->id }})">Void</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="10" icon="banknote" title="No sales in this period." />
                @endforelse
                <x-slot:footer>
                    <div>{{ $sales->links() }}</div>
                </x-slot:footer>
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($voidingId)
        <x-ui.modal title="Void this sale" description="The packs go back into stock. The entry stays in the books, marked voided, with your reason. A credit sale with payments on it needs those payments voided first." close="cancelVoid">
            <x-ui.textarea label="Reason" wire:model.defer="voidReason" rows="3" maxlength="500" />
            @error('void.reason') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="cancelVoid">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmVoid">Void sale</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($receivingSale)
        <x-ui.modal title="Receive payment" :description="$receivingSale->customer_name.' owes GH¢ '.\App\Services\GWater\Money::format($receivingSale->balanceOwed()).' on '.number_format($receivingSale->packs).' pack(s) sold on '.$receivingSale->sale_date->format('d M Y').'. Part payments are fine: come back for the rest.'" close="cancelReceive" icon="banknote">
            <div class="ui-form-grid">
                <x-ui.input label="Amount received (GH¢)" wire:model.defer="receipt.amount" inputmode="decimal" />
                <x-ui.input label="Received on" type="date" wire:model.defer="receipt.received_on" :max="today()->toDateString()" />
                <x-ui.input label="Reference (optional)" wire:model.defer="receipt.reference" maxlength="60" hint="Receipt number, mobile money reference..." />
                <x-ui.input label="Note (optional)" wire:model.defer="receipt.note" maxlength="255" />
            </div>
            <x-slot:footer>
                <x-ui.button wire:click="cancelReceive">Cancel</x-ui.button>
                <x-ui.button variant="primary" icon="check" wire:click="receive">Record payment</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($voidingReceiptId)
        <x-ui.modal title="Void this payment" description="The customer owes that money again. The payment stays in the books, marked voided, with your reason." close="cancelVoidReceipt">
            <x-ui.textarea label="Reason" wire:model.defer="receiptVoidReason" rows="3" maxlength="500" />
            @error('receiptvoid.reason') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="cancelVoidReceipt">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="confirmVoidReceipt">Void payment</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
