@php
    $s = $snapshot;
    $addressee = $s['addressee'];
    $letterhead = $s['letterhead'];
    $company = $s['company'];
    $signatory = $s['signatory'];
    $dots = '……………………………………….';
    $letterDate = \Carbon\Carbon::parse($s['letter_date'])->format(\App\Services\Leave\LeaveLetterService::LETTER_DATE_FORMAT);
    // "Hon. Patrick Yaw Boamah (Chairman), Ing. Dr. Clifford A. Braimah (Managing Director), Mr. Noah Tumfo, ..." as in the template's footer.
    $board = collect($company['board'] ?? [])
        ->map(fn ($member) => $member['name'].(($member['role'] ?? 'Member') !== 'Member' ? ' ('.$member['role'].')' : ''))
        ->implode(', ');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Leave approval letter</title>
    <style>
        @page { margin: 14mm 20mm 42mm 20mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #111; line-height: 1.42; }
        table { border-collapse: collapse; }
        .head { width: 100%; }
        .head td { vertical-align: top; }
        .brand { text-align: center; margin-bottom: 3mm; }
        .brand img { height: 16mm; }
        .brand .name { font-size: 14pt; font-weight: bold; letter-spacing: 1px; margin-top: 0.5mm; }
        .left { width: 55%; font-size: 8.8pt; }
        .right { width: 45%; text-align: right; font-size: 9pt; }
        .right .region { font-weight: bold; }
        .small-title { font-weight: bold; }
        .addressee { margin-top: 7mm; }
        .addressee .name { font-weight: bold; }
        .subject { margin: 4mm 0 3mm 0; font-weight: bold; }
        p { margin: 0 0 3mm 0; text-align: justify; }
        .sign-space { height: 22mm; }
        .signature { height: 25mm; }
        .signature img { display: block; }
        .sig-name { font-weight: bold; }
        .cc { margin-top: 6mm; font-size: 9pt; }
        .footer { position: fixed; left: 0; right: 0; bottom: -36mm; height: 34mm; border-top: 1px solid #555; padding-top: 1.5mm; font-size: 7.2pt; line-height: 1.35; }
    </style>
</head>
<body>
    <div class="footer">
        @if ($board !== '')<div><strong>Board of Directors:</strong> {{ $board }}</div>@endif
        @if (filled($company['registered_office']))<div><strong>Registered Office:</strong> {{ $company['registered_office'] }}</div>@endif
        <div>
            @if (filled($company['telephone']))<strong>Telephone:</strong> {{ $company['telephone'] }}&nbsp;&nbsp;@endif
            @if (filled($company['website']))<strong>Website:</strong> {{ $company['website'] }}&nbsp;&nbsp;@endif
            @if (filled($company['email']))<strong>E-mail:</strong> {{ $company['email'] }}@endif
        </div>
    </div>

    <div class="brand">
        @if ($logo)<img src="{{ $logo }}" alt="">@endif
        <div class="name">GHANA WATER LTD</div>
    </div>

    <table class="head">
        <tr>
            <td class="left">
                @if (! empty($company['bankers']))
                    <div><span class="small-title">Main Bankers:</span> {{ $company['bankers'][0] }}</div>
                    @foreach (array_slice($company['bankers'], 1) as $banker)
                        <div style="padding-left: 21mm">{{ $banker }}</div>
                    @endforeach
                    <br>
                @endif
                <div>My Ref. No.: {{ filled($reference_no) ? $reference_no : $dots }}</div>
                <div>Your Ref. No.: {{ $dots }}</div>
            </td>
            <td class="right">
                <div class="region">{{ $letterhead['region_name'] }}</div>
                @foreach ($letterhead['address_lines'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
                <div style="margin-top: 2mm">{{ $letterDate }}</div>
            </td>
        </tr>
    </table>

    <div class="addressee">
        <div class="name">{{ $addressee['name'] }}</div>
        @if (filled($addressee['designation']))<div>{{ $addressee['designation'] }}</div>@endif
        @if (filled($addressee['thro']))<div style="margin-top: 3mm">{{ $addressee['thro'] }}</div>@endif
        @if (filled($addressee['organisation']))<div>{{ $addressee['organisation'] }}</div>@endif
        @if (filled($addressee['station']))<div>{{ $addressee['station'] }}</div>@endif
    </div>

    <p style="margin-top: 5mm">{{ $addressee['salutation'] }}</p>

    <div class="subject">{{ $s['subject'] }}</div>

    <p>{{ $paragraphs['application'] }}</p>
    <p>{{ $paragraphs['approval'] }}</p>
    <p>{{ $paragraphs['dates'] }}</p>
    @if ($paragraphs['christmas'])<p>{{ $paragraphs['christmas'] }}</p>@endif
    @if ($paragraphs['balance'])<p>{{ $paragraphs['balance'] }}</p>@endif

    <p style="margin-top: 5mm; margin-bottom: 0">Yours faithfully,</p>

    @if ($signature)
        <div class="signature"><img src="{{ $signature }}" alt="" style="width: {{ $signature_size[0] }}mm; height: {{ $signature_size[1] }}mm"></div>
    @else
        <div class="sign-space"></div>
    @endif

    <div class="sig-name">{{ $signatory['name'] }}</div>
    <div>{{ $signatory['title'] }}</div>
    @if ($signatory['for_line'])<div>{{ $signatory['for_line'] }}</div>@endif

    <div class="cc">
        @if (empty($s['cc']))
            cc:
        @else
            @foreach ($s['cc'] as $copy)
                <div>{{ $loop->first ? 'cc: ' : '' }}<span @if (! $loop->first) style="padding-left: 6mm" @endif>{{ $copy }}</span></div>
            @endforeach
        @endif
    </div>
</body>
</html>
