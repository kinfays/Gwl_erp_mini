<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <title>Visitors Log {{ $date }}</title>
        <style>
            body { font-family: DejaVu Sans, Arial, sans-serif; color:#172234; font-size:12px; }
            h1 { font-size:18px; margin:0 0 4px; }
            p { margin:0 0 16px; color:#66758b; }
            table { width:100%; border-collapse:collapse; }
            th, td { border:1px solid #d7dee8; padding:7px; text-align:left; vertical-align:top; }
            th { background:#edf2f7; font-weight:600; }
            .badge { display:inline-block; padding:2px 6px; border-radius:8px; background:#eaf3de; color:#3b6d11; font-size:10px; }
            .out { background:#f1efe8; color:#5f5e5a; }
            @media print { button { display:none; } }
        </style>
    </head>
    <body>
        @isset($printMode)
            <button onclick="window.print()" style="margin-bottom:12px">Print / Save as PDF</button>
        @endisset

        <h1>Visitors Log</h1>
        <p>{{ \Carbon\Carbon::parse($date)->format('l, d F Y') }}</p>

        <table>
            <thead>
                <tr>
                    <th>Visitor</th>
                    <th>Visiting</th>
                    <th>Purpose</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Code</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($visitors as $visitor)
                    <tr>
                        <td>{{ $visitor->visitor_name }}<br>{{ $visitor->phone }}</td>
                        <td>{{ $visitor->staff?->full_name }}<br>{{ $visitor->staff?->department?->department_name }}</td>
                        <td>{{ $visitor->purpose }}</td>
                        <td>{{ $visitor->check_in_at?->format('h:i A') }}</td>
                        <td>{{ $visitor->check_out_at?->format('h:i A') ?: '-' }}</td>
                        <td>{{ $visitor->checkout_code }}</td>
                        <td><span class="badge {{ $visitor->check_out_at ? 'out' : '' }}">{{ $visitor->status }}</span></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">No visitors found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </body>
</html>
