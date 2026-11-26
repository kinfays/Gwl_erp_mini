<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <title>{{ $report['title'] }}</title>
        <style>
            @page { margin: 26px 26px 34px; }
            body { font-family: DejaVu Sans, Arial, sans-serif; color:#172234; font-size:8.5px; }
            h1 { font-size:15px; margin:0 0 3px; }
            h2 { font-size:10.5px; margin:14px 0 4px; }
            .meta { margin:0 0 6px; color:#475569; font-size:8.5px; }
            .meta span { margin-right:12px; }
            .notes { margin:6px 0 4px; padding:5px 8px; background:#f4f7fb; border:1px solid #d5dde8; font-size:8px; }
            .notes p { margin:0 0 2px; }
            table { width:100%; border-collapse:collapse; table-layout:auto; }
            th, td { border:1px solid #b9c4d2; padding:2.5px 4px; text-align:left; vertical-align:top; word-wrap:break-word; }
            th { background:#edf2f7; font-weight:600; font-size:7.5px; }
            td.num { text-align:right; }
            .sign { margin-top:22px; font-size:9px; }
            .sign span { display:inline-block; width:46%; border-top:1px solid #172234; padding-top:3px; margin-right:3%; }
        </style>
    </head>
    <body>
        <h1>{{ $report['title'] }}</h1>
        <p class="meta">
            @foreach ($report['meta'] as $label => $value)
                <span><strong>{{ $label }}:</strong> {{ $value }}</span>
            @endforeach
            <span><strong>Generated:</strong> {{ $generatedAt->format('d M Y, H:i') }}</span>
            <span><strong>Rows:</strong> {{ number_format($report['row_count']) }}</span>
        </p>

        @if ($report['notes'])
            <div class="notes">
                @foreach ($report['notes'] as $note)
                    <p>{{ $note }}</p>
                @endforeach
            </div>
        @endif

        @foreach ($report['tables'] as $table)
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
                                <td @class(['num' => is_int($cell) || is_float($cell)])>
                                    @if (is_float($cell)){{ number_format($cell, 2) }}@elseif (is_int($cell)){{ number_format($cell) }}@else{{ $cell }}@endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($table['headings']) }}">Nothing to show.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endforeach

        <p class="sign"><span>Prepared by</span><span>Checked by</span></p>
    </body>
</html>
