{{-- The voucher table shared by the Finance list and the Audit queue. Needs $rows (paginator), $showRoute, $isSuperAdmin. --}}
<x-ui.card :padded="false" class="dash-row">
    <x-ui.table label="Payment vouchers">
        <x-slot:head>
            <tr>
                <th>PV date</th>
                <th>PV no.</th>
                <th>Payee</th>
                <th>Category / type</th>
                <th class="num">Amount (GH¢)</th>
                <th>Status</th>
                <th>Audit</th>
                <th>Finance</th>
                @if ($isSuperAdmin)<th>Location</th>@endif
                <th><span class="sr-only-text">Open</span></th>
            </tr>
        </x-slot:head>
        @forelse ($rows as $pv)
            @php($marks = $pv->marks())
            <tr wire:key="pv-{{ $pv->id }}" @class(['is-voided' => $pv->isVoided()])>
                <td class="nowrap">{{ $pv->pv_date->format('d M Y') }}</td>
                <td class="mono">{{ $pv->pv_no }}@if ($pv->round > 1) <span class="cell-muted">(round {{ $pv->round }})</span>@endif</td>
                <td>{{ $pv->payee_name }}<div class="cell-muted">{{ $pv->payeeKindLabel() }}</div></td>
                <td>{{ $pv->category?->name }}<div class="cell-muted">{{ $pv->type?->name }}</div></td>
                <td class="num">{{ $pv->amountFormatted() }}</td>
                <td>{{ $pv->statusLabel() }}</td>
                <td><x-finance.mark :mark="$marks['audit']" party="Audit" /></td>
                <td><x-finance.mark :mark="$marks['finance']" party="Finance" /></td>
                @if ($isSuperAdmin)<td class="cell-muted">{{ $pv->district?->district_name ?? $pv->region?->region_name }}</td>@endif
                <td class="nowrap"><x-ui.button size="sm" variant="ghost" :href="route($showRoute, $pv->id)">Open</x-ui.button></td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="$isSuperAdmin ? 10 : 9" icon="receipt" title="No vouchers match." />
        @endforelse
        <x-slot:footer><div>{{ $rows->links() }}</div></x-slot:footer>
    </x-ui.table>
</x-ui.card>
