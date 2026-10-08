<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Site posters</title>
<style>
    @page { margin: 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #000; margin: 0; text-align: center; }
    .page { page-break-after: always; }
    .page.last { page-break-after: auto; }
    h1 { font-size: 34pt; margin: 8mm 0 4mm; }
    .site { font-size: 24pt; font-weight: bold; margin: 0 0 2mm; }
    .meta { font-size: 14pt; color: #333; margin: 0 0 10mm; }
    img.qr { width: 120mm; height: 120mm; }
    .how { font-size: 14pt; margin: 8mm 0 0; }
</style>
</head>
<body>
@foreach ($posters as $poster)
    <div class="page @if ($loop->last) last @endif">
        <h1>Report an incident or near miss</h1>
        <p class="site">{{ $poster['name'] }}</p>
        <p class="meta">{{ collect([$poster['kind'], $poster['district'], $poster['region']])->filter()->implode(' - ') }}</p>
        <img class="qr" src="{{ $poster['qr'] }}" alt="">
        <p class="how">Point your phone camera at the code, sign in to the ERP, and tell us what happened.<br>Near misses count: nothing happened this time, but it could have.</p>
    </div>
@endforeach
</body>
</html>
