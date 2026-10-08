<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Expiry register - site walk-round</title>
<style>
    @page { margin: 12mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #000; }
    h1 { font-size: 15pt; margin: 0 0 1mm; }
    .meta { color: #444; margin: 0 0 4mm; font-size: 8pt; }
    h2 { font-size: 11pt; margin: 5mm 0 1.5mm; padding-bottom: 0.8mm; border-bottom: 0.4mm solid #000; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 2mm; table-layout: fixed; }
    th { text-align: left; font-size: 7.5pt; background: #eee; border: 0.2mm solid #888; padding: 1mm; }
    td { border: 0.2mm solid #888; padding: 1mm; vertical-align: top; word-wrap: break-word; }
    td.num { text-align: right; }
    .overdue { font-weight: bold; }
    .site { page-break-inside: auto; }
    tr { page-break-inside: avoid; }
</style>
</head>
<body>
<h1>Expiry register: site walk-round</h1>
<p class="meta">Printed {{ $generatedAt->format('d M Y, H:i') }} by {{ $generatedBy }}. {{ $count }} item{{ $count === 1 ? '' : 's' }}, grouped by site, soonest first. Tick "Checked" and note anything found on the walk.</p>

@foreach ($groups as $site => $rows)
    <div class="site">
        <h2>{{ $site }} <span style="font-weight:normal;font-size:8pt">({{ count($rows) }})</span></h2>
        <table>
            <colgroup>
                <col style="width:24%"><col style="width:13%"><col style="width:14%"><col style="width:10%"><col style="width:6%"><col style="width:8%"><col style="width:5%"><col style="width:20%">
            </colgroup>
            <thead>
                <tr><th>What</th><th>Type</th><th>Where</th><th>Due</th><th>Days</th><th>Bucket</th><th>Checked</th><th>Notes</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['what'] }}</td>
                        <td>{{ $row['type_label'] }}</td>
                        <td>{{ $row['where'] }}</td>
                        <td>{{ $row['due_on']->format('d M Y') }}</td>
                        <td class="num @if ($row['days'] < 0) overdue @endif">{{ $row['days'] }}</td>
                        <td>{{ $buckets[$row['bucket']] }}</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach

@if ($count === 0)
    <p>Nothing is due in this window.</p>
@endif
</body>
</html>
