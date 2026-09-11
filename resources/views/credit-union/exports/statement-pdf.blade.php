<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #172234; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 14px 0 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #dbe3ee; padding: 5px 6px; text-align: left; }
        th { background: #edf2f7; }
        .meta { color: #66758b; margin-bottom: 10px; }
        .stats { width: 100%; margin: 10px 0; }
        .stats td { width: 33%; vertical-align: top; border: 1px solid #dbe3ee; }
        .value { font-size: 15px; font-weight: bold; }
        .badge { color: #66758b; }
        .num { text-align: right; }
        .empty { color: #66758b; }
    </style>
</head>
<body>
    <h1>Credit Union Statement</h1>
    <div class="meta">
        {{ $member->full_name }} &middot; Member No. {{ $member->member_number }}
        &middot; {{ ucfirst($member->member_type) }} member
        @if ($member->staff_id)
            &middot; Staff ID {{ $member->staff_id }}
        @endif
        <br>
        Generated {{ $generatedAt->format('d M Y H:i') }}
        @if ($member->registered_at)
            &middot; Member since {{ $member->registered_at->format('d M Y') }}
        @endif
    </div>

    <table class="stats">
        <tr>
            <td>
                <div>Shares Balance</div>
                <div class="value">GHS {{ number_format($balances['shares'], 2) }}</div>
            </td>
            <td>
                <div>Savings Balance</div>
                <div class="value">GHS {{ number_format($balances['savings'], 2) }}</div>
            </td>
            <td>
                <div>Total Holdings</div>
                <div class="value">GHS {{ number_format($balances['total'], 2) }}</div>
                <div class="badge">Loans are not included in this statement.</div>
            </td>
        </tr>
    </table>

    <h2>Shares</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Source</th>
                <th>Reference</th>
                <th class="num">Amount</th>
                <th class="num">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($shares as $entry)
                <tr>
                    <td>{{ $entry->transaction_date->format('d M Y') }}</td>
                    <td>{{ ucfirst($entry->entry_type) }}</td>
                    <td>{{ ucwords(str_replace('_', ' ', $entry->source)) }}</td>
                    <td>{{ $entry->reference_no ?? '-' }}</td>
                    <td class="num">{{ number_format($entry->signedAmount(), 2) }}</td>
                    <td class="num">{{ number_format($entry->balance_after, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No shares activity recorded.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Savings</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Source</th>
                <th>Reference</th>
                <th class="num">Amount</th>
                <th class="num">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($savings as $entry)
                <tr>
                    <td>{{ $entry->transaction_date->format('d M Y') }}</td>
                    <td>{{ ucfirst($entry->entry_type) }}</td>
                    <td>{{ ucwords(str_replace('_', ' ', $entry->source)) }}</td>
                    <td>{{ $entry->reference_no ?? '-' }}</td>
                    <td class="num">{{ number_format($entry->signedAmount(), 2) }}</td>
                    <td class="num">{{ number_format($entry->balance_after, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No savings activity recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
