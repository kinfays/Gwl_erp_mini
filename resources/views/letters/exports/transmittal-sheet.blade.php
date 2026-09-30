<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <title>Transmittal {{ $batch->batch_no }}</title>
        <style>
            body { font-family: DejaVu Sans, Arial, sans-serif; color:#172234; font-size:11px; }
            h1 { font-size:18px; margin:0 0 2px; }
            .sub { margin:0 0 14px; color:#66758b; }
            .meta { width:100%; border-collapse:collapse; margin-bottom:14px; }
            .meta td { padding:3px 8px 3px 0; vertical-align:top; border:0; }
            .meta .k { width:90px; color:#66758b; }
            table.lines { width:100%; border-collapse:collapse; }
            table.lines th, table.lines td { border:1px solid #b9c4d2; padding:6px; text-align:left; vertical-align:top; }
            table.lines th { background:#edf2f7; font-weight:600; }
            .num { width:22px; text-align:right; }
            .remarks { width:120px; }
            .sign { width:100%; margin-top:38px; border-collapse:collapse; }
            .sign td { width:33%; padding-right:24px; border:0; vertical-align:bottom; }
            .line { border-top:1px solid #172234; padding-top:4px; color:#66758b; }
            .foot { margin-top:18px; color:#66758b; font-size:10px; }
        </style>
    </head>
    <body>
        <h1>Letter transmittal {{ $batch->batch_no }}</h1>
        <p class="sub">{{ $batch->letters_count }} {{ \Illuminate\Support\Str::plural('letter', $batch->letters_count) }} handed over on {{ $batch->dispatched_at?->format('d M Y, H:i') }}</p>

        <table class="meta">
            <tr>
                <td class="k">From</td>
                <td>{{ $batch->fromSecretariat?->full_name }}@if ($batch->fromSecretariat?->department) · {{ $batch->fromSecretariat->department->department_name }}@endif</td>
                <td class="k">To</td>
                <td>{{ $batch->toSecretariat?->full_name }}@if ($batch->toSecretariat?->department) · {{ $batch->toSecretariat->department->department_name }}@endif</td>
            </tr>
            @if ($batch->note)
                <tr>
                    <td class="k">Note</td>
                    <td colspan="3">{{ $batch->note }}</td>
                </tr>
            @endif
        </table>

        <table class="lines">
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th>SN No</th>
                    <th>Ref No</th>
                    <th>Date on letter</th>
                    <th>Subject</th>
                    <th>Sender</th>
                    @if (config('gwl.letters_scans_enabled'))
                        <th>Scan</th>
                    @endif
                    <th class="remarks">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($batch->routingHistories as $hop)
                    <tr>
                        <td class="num">{{ $loop->iteration }}</td>
                        <td>{{ $hop->letter?->sn_number }}</td>
                        <td>{{ $hop->letter?->ref_no ?: '-' }}</td>
                        <td>{{ $hop->letter?->date_on_letter?->format('d M Y') }}</td>
                        <td>{{ $hop->letter?->subject }}</td>
                        <td>{{ $hop->letter?->sender_name }}</td>
                        @if (config('gwl.letters_scans_enabled'))
                            <td>{{ ($hop->letter?->active_scans_count ?? 0) > 0 ? 'Yes' : '-' }}</td>
                        @endif
                        <td>&nbsp;</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="sign">
            <tr>
                <td><div class="line">Dispatched by ({{ $batch->fromSecretariat?->full_name }})</div></td>
                <td><div class="line">Received by ({{ $batch->toSecretariat?->full_name }})</div></td>
                <td><div class="line">Date &amp; time received</div></td>
            </tr>
        </table>

        <p class="foot">Receipt is confirmed in the ERP (Letters &rsaquo; Transmittals) before the recipient reviews or dispatches these letters.</p>
    </body>
</html>
