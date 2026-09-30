<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <title>Letter register {{ $holder->full_name }} {{ $periodLabel }}</title>
        <style>
            @page { margin: 28px 24px; }
            body { font-family: DejaVu Sans, Arial, sans-serif; color:#172234; font-size:8px; }
            h1 { font-size:15px; margin:0 0 2px; }
            .sub { margin:0 0 10px; color:#66758b; font-size:9px; }
            .meta { width:100%; border-collapse:collapse; margin-bottom:10px; }
            .meta td { padding:2px 10px 2px 0; vertical-align:top; border:0; font-size:9px; }
            .meta .k { width:70px; color:#66758b; }
            table.register { width:100%; border-collapse:collapse; table-layout:fixed; }
            table.register th, table.register td { border:1px solid #b9c4d2; padding:3px 4px; text-align:left; vertical-align:top; word-wrap:break-word; }
            table.register th { background:#edf2f7; font-weight:600; font-size:7.5px; }
            .num { text-align:right; }
            .sign { width:100%; margin-top:30px; border-collapse:collapse; }
            .sign td { width:50%; padding-right:40px; border:0; vertical-align:bottom; font-size:9px; }
            .line { border-top:1px solid #172234; padding-top:3px; color:#66758b; }
            .foot { margin-top:10px; color:#66758b; font-size:8px; }
        </style>
    </head>
    <body>
        <h1>Letter register</h1>
        <p class="sub">{{ $periodLabel }} &middot; {{ $scopeLabel }}</p>

        <table class="meta">
            <tr>
                <td class="k">Holder</td>
                <td>{{ $holder->full_name }} ({{ $holder->staff_id }})</td>
                <td class="k">Office</td>
                <td>{{ collect([$holder->department?->department_name, $holder->district?->district_name, $holder->region?->region_name])->filter()->unique()->join(' · ') ?: '-' }}</td>
            </tr>
            <tr>
                <td class="k">Generated</td>
                <td>{{ $generatedAt->format('d M Y, H:i') }}</td>
                <td class="k">Rows</td>
                <td>{{ $rows->count() }}</td>
            </tr>
        </table>

        <table class="register">
            @php
                $columns = app(\App\Services\Letters\LetterRegisterService::class)->columns();
                $withScanned = array_key_exists('scanned', $columns);
            @endphp
            <colgroup>
                <col style="width:3%"><col style="width:6%"><col style="width:6%"><col style="width:6%"><col style="width:5%">
                <col style="width:6%"><col style="width:8%"><col style="width:8%"><col style="width:{{ $withScanned ? 11 : 13 }}%"><col style="width:6%">
                <col style="width:8%"><col style="width:6%"><col style="width:7%"><col style="width:{{ $withScanned ? 10 : 12 }}%">
                @if ($withScanned)<col style="width:4%">@endif
            </colgroup>
            <thead>
                <tr>
                    @foreach ($columns as $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td class="num">{{ $row['no'] }}</td>
                        <td>{{ $row['date_received'] }}</td>
                        <td>{{ $row['sn_number'] }}</td>
                        <td>{{ $row['ref_no'] }}</td>
                        <td>{{ $row['type'] }}</td>
                        <td>{{ $row['date_on_letter'] }}</td>
                        <td>{{ $row['sender'] }}</td>
                        <td>{{ $row['received_from'] }}</td>
                        <td>{{ $row['subject'] }}</td>
                        <td>{{ $row['date_out'] }}</td>
                        <td>{{ $row['sent_to'] }}</td>
                        <td>{{ $row['transmittal_no'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td>{{ $row['remarks'] }}</td>
                        @if ($withScanned)
                            <td>{{ $row['scanned'] ?? '' }}</td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) }}">No letters were received in this period.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <table class="sign">
            <tr>
                <td><div class="line">Prepared by</div></td>
                <td><div class="line">Checked by</div></td>
            </tr>
        </table>

        <p class="foot">Remarks are shortened to fit; the full text is in the Excel register. Hand-overs that were not confirmed, or were recalled or rejected, are not listed.</p>
    </body>
</html>
