<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <title>Asset summary</title>
        <style>
            @page { margin: 28px 28px; }
            body { font-family: DejaVu Sans, Arial, sans-serif; color:#172234; font-size:9px; }
            h1 { font-size:16px; margin:0 0 2px; }
            h2 { font-size:11px; margin:16px 0 4px; }
            .sub { margin:0 0 8px; color:#66758b; font-size:9px; }
            table { width:100%; border-collapse:collapse; table-layout:auto; }
            th, td { border:1px solid #b9c4d2; padding:3px 5px; text-align:left; vertical-align:top; word-wrap:break-word; }
            th { background:#edf2f7; font-weight:600; font-size:8px; }
            .foot { margin-top:12px; color:#66758b; font-size:8px; }
        </style>
    </head>
    <body>
        <h1>Asset summary</h1>
        <p class="sub">
            Generated {{ $generatedAt->format('d M Y, H:i') }}
            &middot; {{ $filters['district'] ?: 'All districts' }}
            @if ($filters['from'] || $filters['to'])
                &middot; Maintenance and issues: {{ $filters['from'] ?: 'start' }} to {{ $filters['to'] ?: 'today' }}
            @endif
        </p>

        @foreach ($tables as $table)
            <h2>{{ $table['title'] }}</h2>
            <table>
                <thead>
                    <tr>
                        @foreach ($table['headings'] as $heading)
                            <th>{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($table['rows'] as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($table['headings']) }}">Nothing to show.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endforeach

        <p class="foot">The date range applies to maintenance tickets and issue reports only.</p>
    </body>
</html>
