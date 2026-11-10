{{-- The voucher table shared by the Finance list and the Audit queue. Needs $rows (paginator), $showRoute, $isSuperAdmin.
     Optional: $selectable (Finance: a tick box per row; wire:model "selected" on the component), $batchRoute (a link to the batch). --}}
@php($selectable = $selectable ?? false)
@php($printable = $printable ?? false)
@php($columns = 9 + ($isSuperAdmin ? 1 : 0) + ($selectable ? 1 : 0) + ($printable ? 1 : 0))
<x-ui.card :padded="false" class="dash-row">
    <x-ui.table label="Payment vouchers">
        <x-slot:head>
            <tr>
                @if ($selectable)
                    <th class="fin-select-col">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="selectPage" title="Tick or untick every voucher on this page that can go into a batch">Select page</button>
                    </th>
                @endif
                @if ($printable)<th>Print</th>@endif
                <th>PV date</th>
                <th>PV no.</th>
                <th>Payee</th>
                <th>Category / type</th>
                <th class="num">Amount (GH¢)</th>
                <th>Status</th>
                <th>Batch</th>
                <th>Audit</th>
                <th>Finance</th>
                @if ($isSuperAdmin)<th>Location</th>@endif
                <th><span class="sr-only-text">Open</span></th>
            </tr>
        </x-slot:head>
        @forelse ($rows as $pv)
            @php($marks = $pv->marks())
            @php($blocker = $selectable ? $pv->batchBlocker() : null)
            <tr wire:key="pv-{{ $pv->id }}" @class(['is-voided' => $pv->isVoided()])>
                @if ($selectable)
                    <td class="fin-select-col">
                        {{-- A voucher that cannot go into a batch says why (tooltip and screen-reader text), instead of just being greyed out. --}}
                        <input type="checkbox" value="{{ $pv->id }}" wire:model.live="selected" id="pick-{{ $pv->id }}"
                            @disabled($blocker !== null)
                            aria-label="Select voucher {{ $pv->pv_no }} for a batch"
                            @if ($blocker !== null) title="PV {{ $pv->pv_no }} {{ $blocker }}" aria-describedby="why-{{ $pv->id }}" @endif>
                        @if ($blocker !== null)<span id="why-{{ $pv->id }}" class="sr-only-text">Cannot be batched: PV {{ $pv->pv_no }} {{ $blocker }}.</span>@endif
                    </td>
                @endif
                @if ($printable)
                    <td><input type="checkbox" value="{{ $pv->id }}" wire:model.live="printIds" aria-label="Tick voucher {{ $pv->pv_no }} for printing"></td>
                @endif
                <td class="nowrap">{{ $pv->pv_date->format('d M Y') }}</td>
                <td class="mono">{{ $pv->pv_no }}@if ($pv->round > 1) <span class="cell-muted">(round {{ $pv->round }})</span>@endif</td>
                <td>{{ $pv->payee_name }}<div class="cell-muted">{{ $pv->payeeKindLabel() }}</div>
                    @if (isset($payeeFlags[$pv->id]))
                        {{-- an icon AND words, with the full sentence as a tooltip --}}
                        <div class="cell-muted" title="{{ $payeeFlags[$pv->id]['message'] }}"><x-ui.icon name="triangle-alert" /> Left service</div>
                    @endif
                </td>
                <td>{{ $pv->category_label }}<div class="cell-muted">{{ $pv->type_label }}</div></td>
                <td class="num">{{ $pv->amountFormatted() }}</td>
                <td>{{ $pv->statusLabel() }}</td>
                <td class="nowrap">
                    @if ($pv->batch && ! empty($batchRoute))
                        <a href="{{ route($batchRoute, $pv->batch_id) }}" class="mono">{{ $pv->batch->batch_no }}</a>
                    @elseif ($pv->batch)
                        <span class="mono">{{ $pv->batch->batch_no }}</span>
                    @else
                        <span class="cell-muted">-</span>
                    @endif
                </td>
                <td><x-finance.mark :mark="$marks['audit']" party="Audit" /></td>
                <td><x-finance.mark :mark="$marks['finance']" party="Finance" /></td>
                @if ($isSuperAdmin)<td class="cell-muted">{{ $pv->district?->district_name ?? $pv->region?->region_name }}</td>@endif
                <td class="nowrap"><x-ui.button size="sm" variant="ghost" :href="route($showRoute, $pv->id)">Open</x-ui.button></td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="$columns" icon="receipt" title="No vouchers match." />
        @endforelse
        <x-slot:footer><div>{{ $rows->links() }}</div></x-slot:footer>
    </x-ui.table>
</x-ui.card>
