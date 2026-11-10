<!DOCTYPE html>
{{-- Layout only: every word printed here comes from PvPrintService (headings, labels, sentences, the footer). --}}
<html>
    <head>
        <meta charset="utf-8">
        <title>{{ $doc['title'] }}</title>
        <style>
            @page { margin: 34px 36px 52px; }
            body { font-family: DejaVu Sans, Arial, sans-serif; color: #172234; font-size: 9.5px; }
            h1 { font-size: 17px; margin: 0; }
            h2 { font-size: 11px; margin: 14px 0 4px; }
            h3 { font-size: 9.5px; margin: 8px 0 3px; color: #475569; }
            .head { width: 100%; border-bottom: 2px solid #172234; padding-bottom: 6px; margin-bottom: 8px; }
            .head td { border: 0; padding: 0; vertical-align: bottom; }
            .copy { text-align: right; font-size: 10px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; }
            .banner { border: 2px solid #b42318; color: #b42318; font-weight: bold; font-size: 13px; text-align: center; padding: 5px; margin: 0 0 8px; }
            table { width: 100%; border-collapse: collapse; }
            .kv td { border: 1px solid #b9c4d2; padding: 3px 6px; vertical-align: top; }
            .kv td.k { width: 22%; background: #edf2f7; font-weight: bold; }
            .amount { margin: 8px 0 2px; font-size: 15px; font-weight: bold; }
            .words { margin: 0 0 6px; font-style: italic; }
            .block { border: 1px solid #b9c4d2; padding: 5px 7px; margin: 3px 0 6px; white-space: pre-line; }
            .grid th, .grid td { border: 1px solid #b9c4d2; padding: 2.5px 4px; text-align: left; vertical-align: top; word-wrap: break-word; }
            .grid th { background: #edf2f7; font-size: 8px; }
            .grid td.num, .grid th.num { text-align: right; }
            .muted { color: #475569; }
            .tl td { border-bottom: 1px solid #dde4ee; padding: 2px 4px; vertical-align: top; }
            .tl td.when { width: 17%; white-space: nowrap; }
            .small { font-size: 8px; }
            .page { page-break-after: always; }
            .page.last { page-break-after: auto; }
            .foot { position: fixed; bottom: -36px; left: 0; right: 0; font-size: 7.5px; color: #475569; border-top: 1px solid #b9c4d2; padding-top: 3px; }
            .foot td { border: 0; padding: 0; }
            .foot td.r { text-align: right; padding-right: 96px; }
        </style>
    </head>
    <body>
        <div class="foot">
            <table><tr><td>{{ $doc['footer']['left'] }}</td><td class="r">{{ $doc['footer']['right'] }}</td></tr></table>
        </div>

        @if ($doc['cover'])
            @php($cover = $doc['cover'])
            <div @class(['page', 'last' => count($doc['pages']) === 0])>
                <table class="head"><tr><td><h1>{{ $cover['heading'] }}</h1></td><td class="copy">{{ $doc['copy'] }}</td></tr></table>
                <table class="kv">
                    @foreach ($cover['facts'] as [$label, $value])
                        <tr><td class="k">{{ $label }}</td><td>{{ $value }}</td></tr>
                    @endforeach
                </table>
                <h3>{{ $cover['counts_label'] }}</h3>
                <p>{{ $cover['status_counts'] === [] ? '-' : implode('   ', $cover['status_counts']) }}</p>
                @foreach ($cover['notes'] as $note)<p class="muted small">{{ $note }}</p>@endforeach

                @php($table = $doc['table'])
                <h2>{{ $table['heading'] }}</h2>
                <table class="grid">
                    <thead><tr>@foreach ($table['head'] as $i => $heading)<th @class(['num' => $i === 4])>{{ $heading }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse ($table['rows'] as $row)
                            <tr>@foreach ($row as $i => $cell)<td @class(['num' => $i === 4])>{{ $cell }}</td>@endforeach</tr>
                        @empty
                            <tr><td colspan="{{ count($table['head']) }}" class="muted">{{ $table['empty'] }}</td></tr>
                        @endforelse
                        <tr><td colspan="4"><strong>{{ $table['total_label'] }}</strong></td><td class="num"><strong>{{ $table['total'] }}</strong></td><td colspan="2"></td></tr>
                    </tbody>
                </table>
                @if ($cover['own_pages_note'])<p class="muted small">{{ $cover['own_pages_note'] }}</p>@endif
            </div>
        @endif

        @foreach ($doc['pages'] as $page)
            <div @class(['page', 'last' => $loop->last])>
                <table class="head"><tr><td><h1>{{ $page['heading'] }}</h1></td><td class="copy">{{ $page['copy'] }}</td></tr></table>
                @if ($page['mark'])<div class="banner">{{ $page['mark'] }}</div>@endif

                <table class="kv">
                    @foreach ($page['rows'] as [$label, $value])
                        <tr><td class="k">{{ $label }}</td><td>{{ $value }}</td></tr>
                    @endforeach
                </table>

                <div class="amount">{{ $page['amount_label'] }}: {{ $page['amount'] }}</div>
                <div class="words">{{ $page['words_label'] }}: {{ $page['words'] }}</div>

                <h3>{{ $page['details_label'] }}</h3>
                <div class="block">{{ $page['details'] }}</div>
                @if ($page['remarks'])
                    <h3>{{ $page['remarks_label'] }}</h3>
                    <div class="block">{{ $page['remarks'] }}</div>
                @endif

                <p class="small"><strong>{{ $page['marks_label'] }}</strong></p>

                <h2>{{ $page['timeline_label'] }}</h2>
                @forelse ($page['timeline'] as $round)
                    <h3>{{ $round['round'] }}</h3>
                    <table class="tl">
                        @foreach ($round['lines'] as $line)
                            <tr>
                                <td class="when">{{ $line['when'] }}</td>
                                <td>
                                    <strong>{{ $line['what'] }}</strong> <span class="muted">- {{ $line['by'] }}</span>
                                    @if ($line['reason'])<div>{{ $line['reason'] }}</div>@endif
                                    @if ($line['changed'])<div class="muted">{{ $line['changed'] }}</div>@endif
                                    @if ($line['comment'])<div style="white-space: pre-line">{{ $line['comment'] }}</div>@endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                @empty
                    <p class="muted">{{ $page['timeline_empty'] }}</p>
                @endforelse

                @if ($doc['footer']['right'] === null)<p class="muted small">{{ $page['printed'] }}</p>@endif
            </div>
        @endforeach
    </body>
</html>
