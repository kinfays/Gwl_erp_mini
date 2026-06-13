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
        .stats td { width: 20%; vertical-align: top; }
        .value { font-size: 15px; font-weight: bold; }
        .badge { color: #66758b; }
        .grid { display: table; width: 100%; table-layout: fixed; }
        .col { display: table-cell; width: 50%; padding-right: 8px; vertical-align: top; }
    </style>
</head>
<body>
    <h1>Transport Report</h1>
    <div class="meta">
        Period: {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}
        @if ($department)
            | Department: {{ $department->department_name }}
        @else
            | Department: All
        @endif
    </div>

    <table class="stats">
        <tr>
            @foreach ($payload['statCards'] as $card)
                <td>
                    <div>{{ $card['label'] }}</div>
                    <div class="value">{{ $card['value'] }}</div>
                    <div class="badge">{{ $card['badge'] }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <div class="grid">
        <div class="col">
            <h2>Monthly Fleet Expenses</h2>
            <table>
                <thead><tr><th>Month</th><th>Amount</th></tr></thead>
                <tbody>
                    @foreach ($payload['monthlyExpenses']['labels'] as $index => $label)
                        <tr><td>{{ $label }}</td><td>{{ number_format($payload['monthlyExpenses']['data'][$index] ?? 0, 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>

            <h2>Expense Breakdown</h2>
            <table>
                <thead><tr><th>Type</th><th>Amount</th></tr></thead>
                <tbody>
                    @foreach ($payload['expenseByType']['labels'] as $index => $label)
                        <tr><td>{{ $label }}</td><td>{{ number_format($payload['expenseByType']['data'][$index] ?? 0, 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>

            <h2>Upcoming Document Renewals</h2>
            <table>
                <thead><tr><th>Vehicle</th><th>Document</th><th>Expiry</th><th>Days</th></tr></thead>
                <tbody>
                    @forelse ($payload['upcomingExpiryDocs'] as $row)
                        <tr><td>{{ $row['vehicle'] }}</td><td>{{ $row['document'] }}</td><td>{{ $row['expiry_date'] }}</td><td>{{ $row['days_remaining'] }}</td></tr>
                    @empty
                        <tr><td colspan="4">No renewals due.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="col">
            <h2>Vehicle Status</h2>
            <table>
                <thead><tr><th>Status</th><th>Count</th></tr></thead>
                <tbody>
                    @foreach ($payload['vehicleStatusCounts']['labels'] as $index => $label)
                        <tr><td>{{ $label }}</td><td>{{ $payload['vehicleStatusCounts']['data'][$index] ?? 0 }}</td></tr>
                    @endforeach
                </tbody>
            </table>

            <h2>Top Expensive Vehicles</h2>
            <table>
                <thead><tr><th>Vehicle</th><th>Total Spend</th></tr></thead>
                <tbody>
                    @forelse ($payload['topExpensiveVehicles']['rows'] as $row)
                        <tr><td>{{ $row['vehicle'] }}</td><td>{{ number_format($row['total'], 2) }}</td></tr>
                    @empty
                        <tr><td colspan="2">No expense records.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <h2>Maintenance Due By Mileage</h2>
            <table>
                <thead><tr><th>Vehicle</th><th>Current</th><th>Remaining</th><th>Next</th></tr></thead>
                <tbody>
                    @foreach ($payload['maintenanceDueSoon']['rows'] as $row)
                        <tr><td>{{ $row['vehicle'] }}</td><td>{{ number_format($row['current_mileage']) }}</td><td>{{ number_format($row['remaining_km']) }}</td><td>{{ number_format($row['next_maintenance_mileage']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
