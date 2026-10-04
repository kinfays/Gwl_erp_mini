<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <title>Asset audit {{ $audit->title }}</title>
        <style>
            @page { margin: 28px 24px; }
            body { font-family: DejaVu Sans, Arial, sans-serif; color:#172234; font-size:7.5px; }
            h1 { font-size:15px; margin:0 0 2px; }
            .sub { margin:0 0 10px; color:#66758b; font-size:9px; }
            .meta { width:100%; border-collapse:collapse; margin-bottom:10px; }
            .meta td { padding:2px 10px 2px 0; vertical-align:top; border:0; font-size:9px; }
            .meta .k { width:80px; color:#66758b; }
            table.register { width:100%; border-collapse:collapse; table-layout:fixed; }
            table.register th, table.register td { border:1px solid #b9c4d2; padding:3px 4px; text-align:left; vertical-align:top; word-wrap:break-word; }
            table.register th { background:#edf2f7; font-weight:600; font-size:7px; }
            .sign { width:100%; margin-top:30px; border-collapse:collapse; }
            .sign td { width:50%; padding-right:40px; border:0; vertical-align:bottom; font-size:9px; }
            .line { border-top:1px solid #172234; padding-top:3px; color:#66758b; }
            .foot { margin-top:10px; color:#66758b; font-size:8px; }
        </style>
    </head>
    <body>
        <h1>Asset physical verification</h1>
        <p class="sub">{{ $audit->title }} &middot; {{ $audit->scopeSummary() }}</p>

        <table class="meta">
            <tr>
                <td class="k">Started</td>
                <td>{{ $audit->started_at?->format('d M Y, H:i') }} by {{ $audit->startedBy?->full_name ?: '-' }}</td>
                <td class="k">Completed</td>
                <td>{{ $audit->completed_at?->format('d M Y, H:i') ?: '-' }}</td>
            </tr>
            <tr>
                <td class="k">Reconciliation</td>
                <td>{{ $audit->reconciliation_rate !== null ? $audit->reconciliation_rate.'%' : '-' }}</td>
                <td class="k">Result counts</td>
                <td>{{ $summary['matched'] }} matched &middot; {{ $summary['mismatch'] }} mismatch &middot; {{ $summary['not_found'] }} not found (of {{ $summary['total'] }})</td>
            </tr>
            <tr>
                <td class="k">Generated</td>
                <td>{{ $generatedAt->format('d M Y, H:i') }}</td>
                <td class="k"></td>
                <td></td>
            </tr>
        </table>

        <table class="register">
            <thead>
                <tr>
                    @foreach ($columns as $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach (array_keys($columns) as $key)
                            <td>{{ $row[$key] }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="sign">
            <tr>
                <td><div class="line">Audited by</div></td>
                <td><div class="line">Reviewed by</div></td>
            </tr>
        </table>

        <p class="foot">Expected values are the system record at the moment the audit started.</p>
    </body>
</html>
