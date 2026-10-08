<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
@php
    $large = $layout === 'large';
    // A4 is 210 x 297 mm; 8 mm margins leave 194 x 281 mm. The rows are kept a little short of that so a full page never
    // spills a nearly empty one after it.
    $cellWidth = round(194 / $columns, 2);
    $cellHeight = $large ? 50 : 34;
    $qrSize = $large ? 36 : 25;
    $pad = $large ? 4 : 1.5;
    $qrCol = $qrSize + 2 * $pad;
    $textCol = round($cellWidth - $qrCol - 1, 2);
@endphp
<style>
    @page { margin: 8mm; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #000; margin: 0; }
    table.sheet { width: 194mm; border-collapse: collapse; table-layout: fixed; }
    table.sheet td.cell { width: {{ $cellWidth }}mm; height: {{ $cellHeight }}mm; padding: 0; border: 0.3mm dashed #999; vertical-align: middle; }
    div.box { width: {{ $cellWidth - 0.6 }}mm; height: {{ $cellHeight - 0.6 }}mm; overflow: hidden; }
    table.label { width: {{ $cellWidth - 0.6 }}mm; border-collapse: collapse; table-layout: fixed; }
    table.label td { vertical-align: middle; padding: {{ $pad }}mm; }
    td.qr { width: {{ $qrSize }}mm; }
    td.qr img { width: {{ $qrSize }}mm; height: {{ $qrSize }}mm; }
    td.text { width: {{ $textCol - 2 * $pad }}mm; padding-left: 0; }
    .code { font-size: {{ $large ? '12pt' : '9pt' }}; font-weight: bold; margin: 0 0 1mm; }
    .kind { font-size: {{ $large ? '8pt' : '6pt' }}; margin: 0 0 1mm; }
    .where { font-size: {{ $large ? '7.5pt' : '6pt' }}; margin: 0 0 1mm; color: #333; }
    .scan { font-size: {{ $large ? '8.5pt' : '6.5pt' }}; font-weight: bold; margin: 1mm 0 0; }
    .page-break { page-break-after: always; }
</style>
</head>
<body>
@foreach ($pages as $pageIndex => $page)
    <table class="sheet @if (! $loop->last) page-break @endif">
        @foreach (array_chunk($page, $columns) as $row)
            <tr>
                @foreach ($row as $label)
                    <td class="cell">
                        <div class="box"><table class="label">
                            <tr>
                                <td class="qr"><img src="{{ $label['qr'] }}" alt=""></td>
                                <td class="text">
                                    <p class="code">{{ $label['code'] }}</p>
                                    <p class="kind">{{ $label['kind'] }}</p>
                                    <p class="where">
                                        {{ \Illuminate\Support\Str::limit($label['site'].($label['where'] !== '' ? ', '.$label['where'] : ''), $large ? 90 : 44) }}
                                        @if ($label['region'] !== '')<br>{{ $label['region'] }}@endif
                                    </p>
                                    <p class="scan">{{ $label['line'] }}</p>
                                </td>
                            </tr>
                        </table></div>
                    </td>
                @endforeach
                @for ($i = count($row); $i < $columns; $i++)
                    <td class="cell" style="border-color:#fff;"></td>
                @endfor
            </tr>
        @endforeach
    </table>
@endforeach
</body>
</html>
