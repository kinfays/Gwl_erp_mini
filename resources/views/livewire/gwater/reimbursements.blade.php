<div>
    <x-ui.page-header title="Reimbursements" description="Fuel that staff paid out of pocket and are waiting to be paid back. Loading and offloading are paid in cash, so they never appear here." />

    @include('livewire.gwater.partials.location-picker')
    @include('livewire.gwater.partials.exports')

    @if ($location)
        <div class="ui-stat-grid dash-row">
            <x-ui.stat-tile label="To pay back" :value="\App\Services\GWater\Money::format($pending->sum('amount'))" icon="hand-coins" meta="GH¢" />
            <x-ui.stat-tile label="Entries waiting" :value="$pending->count()" icon="receipt" tone="muted" />
        </div>

        @forelse ($byPerson as $person => $items)
            <x-ui.card :title="$person" :description="'Total GH¢ '.\App\Services\GWater\Money::format($items->sum('amount'))" :padded="false" class="dash-row" wire:key="person-{{ $person }}">
                <x-ui.table label="Pending fuel" :sticky="false" dense>
                    <x-slot:head>
                        <tr><th>Date</th><th>Waybill</th><th class="num">Amount</th><th>Recorded by</th><th><span class="sr-only-text">Actions</span></th></tr>
                    </x-slot:head>
                    @foreach ($items as $item)
                        <tr wire:key="pend-{{ $item['kind'] }}-{{ $item['id'] }}">
                            <td class="nowrap">{{ $item['date']->format('d M Y') }}</td>
                            <td class="mono">{{ $item['waybill'] ?? '-' }}</td>
                            <td class="num">
                                {{ \App\Services\GWater\Money::format($item['amount']) }}
                                @if ($item['kind'] === 'trip')
                                    <div><x-ui.badge tone="lagoon">From Transport</x-ui.badge></div>
                                @endif
                                @if ($item['changed_in_transport'])
                                    <div class="cell-muted">changed in Transport</div>
                                @endif
                            </td>
                            <td class="cell-muted">{{ $item['recorded_by'] }}</td>
                            <td class="nowrap">
                                @if ($canReimburse)
                                    @if ($item['recorded_by_id'] === $me || $item['person_id'] === $me)
                                        <span class="cell-muted">Someone else must mark this</span>
                                    @else
                                        <x-ui.button size="sm" wire:click="openPay('{{ $item['kind'] }}', {{ $item['id'] }})">Mark reimbursed</x-ui.button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @empty
            <x-ui.card class="dash-row">
                <x-ui.empty-state icon="hand-coins" title="Nothing to pay back." description="Fuel paid out of pocket on a trip will appear here." />
            </x-ui.card>
        @endforelse

        <x-ui.card title="Recently paid back" :padded="false" class="dash-row">
            <x-ui.table label="Reimbursed fuel" :sticky="false" dense>
                <x-slot:head>
                    <tr><th>Paid on</th><th>Person</th><th>Waybill</th><th class="num">Amount</th><th>Reference</th></tr>
                </x-slot:head>
                @forelse ($paid as $item)
                    <tr wire:key="paid-{{ $item['kind'] }}-{{ $item['id'] }}">
                        <td class="nowrap">{{ $item['paid_on']?->format('d M Y') }}</td>
                        <td>{{ $item['person'] }}</td>
                        <td class="mono">{{ $item['waybill'] ?? '-' }}</td>
                        <td class="num">{{ \App\Services\GWater\Money::format($item['amount']) }}</td>
                        <td class="mono">{{ $item['reference'] }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" icon="receipt" title="Nothing has been paid back yet." />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($payingId)
        <x-ui.modal title="Mark fuel as reimbursed" description="Enter the reference of the payment. You cannot mark an entry you recorded or fuel you paid yourself." close="cancelPay">
            <x-ui.input label="Payment reference" wire:model.defer="form.reference" maxlength="60" />
            <x-ui.input label="Paid on" type="date" wire:model.defer="form.paid_on" :max="today()->toDateString()" />
            @error('form.reference') <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            <x-slot:footer>
                <x-ui.button wire:click="cancelPay">Cancel</x-ui.button>
                <x-ui.button variant="primary" wire:click="pay">Mark reimbursed</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
