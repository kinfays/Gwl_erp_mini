<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <title>{{ $title }}</title>
        <style>
            @page { margin: 28px 24px; }
            body { font-family: DejaVu Sans, Arial, sans-serif; color:#172234; font-size:8px; }
            h1 { font-size:15px; margin:0 0 2px; }
            .sub { margin:0 0 4px; color:#66758b; font-size:9px; }
            table.list { width:100%; border-collapse:collapse; margin-top:8px; }
            table.list th, table.list td { border:1px solid #b9c4d2; padding:3px 4px; text-align:left; vertical-align:top; word-wrap:break-word; }
            table.list th { background:#edf2f7; font-weight:600; font-size:7.5px; }
            .sign { width:100%; margin-top:30px; border-collapse:collapse; }
            .sign td { width:50%; padding-right:40px; border:0; vertical-align:bottom; font-size:9px; }
            .line { border-top:1px solid #172234; padding-top:3px; color:#66758b; }
        </style>
    </head>
    <body>
        <h1>{{ $title }}</h1>
        <p class="sub">Generated {{ $generatedAt->format('d M Y, H:i') }} &middot; {{ $rowCount }} {{ \Illuminate\Support\Str::plural('row', $rowCount) }}</p>
        @if (! empty($summary))
            <p class="sub">{{ $summary }}</p>
        @endif

        <table class="list">
            <thead>
                <tr>
                    @foreach ($headings as $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ max(count($headings), 1) }}">Nothing to show.</td></tr>
                @endforelse
            </tbody>
        </table>

        <table class="sign">
            <tr>
                <td><div class="line">Prepared by</div></td>
                <td><div class="line">Checked by</div></td>
            </tr>
        </table>
    </body>
</html>
