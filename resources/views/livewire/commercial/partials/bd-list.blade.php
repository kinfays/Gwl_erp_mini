{{-- One capped account list (Phase 8d). Expects $list (kind, amount_label, total, rows, capped), $title, $customerLinks (account => customer id) and $named. Account
     numbers only: a name appears only on the customer view, behind its own permission. --}}
@php $shown = array_slice($list['rows'], 0, \App\Services\Commercial\BillingDetails\BillingDetailsReport::LIST_ROWS); $cap = (int) config('gwl.commercial_billing_detail_issue_cap', 200); @endphp
<x-ui.card :title="$title" :description="$description ?? null" :padded="false" class="dash-row" data-list="{{ $list['kind'] }}">
    <x-ui.table :label="$title" :sticky="false">
        <x-slot:head>
            <tr><th>Account</th><th>Route</th><th>District</th>@if ($named)<th>CCA</th>@endif<th class="num">{{ $list['amount_label'] }}</th></tr>
        </x-slot:head>
        @forelse ($shown as $row)
            <tr>
                <td>
                    @if (isset($customerLinks[$row['account']]))
                        <a href="{{ route('commercial.customers.show', $customerLinks[$row['account']]) }}">{{ $row['account'] }}</a>
                    @else
                        {{ $row['account'] }}
                    @endif
                </td>
                <td>{{ $row['route'] }}</td>
                <td>{{ $row['district'] ?? '–' }}</td>
                @if ($named)<td>{{ $row['cca'] ?? '–' }}</td>@endif
                <td class="num">{{ $list['kind'] === 'stale_read' ? ((int) $row['amount'] >= \App\Models\CommercialBillingDetailIssue::NEVER_READ_DAYS ? 'never' : number_format($row['amount'])) : number_format($row['amount'], 2) }}</td>
            </tr>
        @empty
            <x-ui.empty-row :colspan="$named ? 5 : 4" icon="check" title="No account on this list." />
        @endforelse
    </x-ui.table>
</x-ui.card>
@if ($list['total'] > count($shown))
    <p class="ui-hint dash-row">{{ number_format(count($shown)) }} of {{ number_format($list['total']) }} accounts are shown (the largest first; each upload keeps at most {{ number_format($cap) }} per list and district). The Excel and PDF files hold up to {{ number_format($cap) }}.</p>
@endif
