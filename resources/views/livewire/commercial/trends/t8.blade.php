@foreach ($parts as $part)
    @if ($part['data'] === null)
        <x-commercial.cross-part :part="$part" />
    @else
        @php $p = $part['data']; @endphp
        <x-commercial.cross-part :part="$part" :description="'Days covered by live uploads, the gaps, and how late each upload was (import day minus the last day of its period; the customer list: minus its as-of date). On time = within '.($p['deadline_days'] ?? config('gwl.commercial_upload_deadline_days', 3)).' days.'">
            @if (! ($p['label'] ?? null) || $p['uploads'] === [])
                <p class="ui-hint" data-empty-part>{{ $p['note'] ?? 'No upload of this type for the districts you may see.' }}</p>
            @else
                <p class="ui-hint"><strong>{{ $p['month_label'] }}</strong>. On time: {{ $p['on_time'] }} of {{ $p['total'] }} uploads @if ($p['on_time_share'] !== null) ({{ number_format($p['on_time_share'], 1) }}%)@endif.</p>
                @foreach ($p['districts'] as $dist)
                    <div style="margin-bottom:10px" data-district="{{ $dist['id'] }}">
                        <strong>{{ $dist['name'] }}</strong> · {{ $dist['covered_days'] }} of {{ $dist['days_in_month'] }} days covered · {{ $dist['on_time'] }} of {{ $dist['uploads'] }} on time ({{ number_format($dist['on_time_share'], 0) }}%)
                        <div role="img" aria-label="{{ $dist['name'] }}: {{ $dist['covered_days'] }} of {{ $dist['days_in_month'] }} days covered" style="display:flex;gap:2px;margin-top:4px;flex-wrap:wrap">
                            @foreach ($dist['days'] as $day => $on)
                                <span title="{{ $day }}: {{ $on ? 'covered' : 'not covered' }}" style="width:18px;height:18px;font-size:10px;line-height:18px;text-align:center;border-radius:3px;{{ $on ? 'background:var(--color-success-soft);color:var(--color-success-ink)' : 'background:var(--color-danger-soft);color:var(--color-danger-ink)' }}">{{ $day }}</span>
                            @endforeach
                        </div>
                        @if ($dist['gaps'] !== [])
                            <p class="ui-hint">Gaps: @foreach ($dist['gaps'] as $g){{ $g[0] === $g[1] ? \Illuminate\Support\Carbon::parse($g[0])->format('j M') : \Illuminate\Support\Carbon::parse($g[0])->format('j M').' to '.\Illuminate\Support\Carbon::parse($g[1])->format('j M') }}{{ ! $loop->last ? '; ' : '' }}@endforeach</p>
                        @else
                            <p class="ui-hint">No gaps.</p>
                        @endif
                    </div>
                @endforeach
                <h4 style="margin-top:12px">Each upload</h4>
                <x-ui.table :label="$part['title'].' uploads'" :sticky="count($p['uploads']) > 12" dense>
                    <x-slot:head><tr><th>Period</th><th>Imported</th><th class="num">Days late</th><th>On time</th></tr></x-slot:head>
                    @foreach (array_slice($p['uploads'], 0, 100) as $u)
                        <tr data-late="{{ $u['late'] ? 'yes' : 'no' }}"><td>{{ $u['label'] }}</td><td>{{ \Illuminate\Support\Carbon::parse($u['imported'])->format('j M Y') }}</td><td class="num">{{ $u['lateness'] }}</td>
                            <td @if ($u['late']) style="background:var(--color-danger-soft);color:var(--color-danger-ink)" @endif>{{ $u['late'] ? 'late' : 'on time' }}</td></tr>
                    @endforeach
                </x-ui.table>
                <h4 style="margin-top:12px">Districts by last upload</h4>
                <x-ui.table :label="$part['title'].' recency'" dense>
                    <x-slot:head><tr><th>District</th><th>Last import</th><th class="num">Days ago</th><th>Period</th></tr></x-slot:head>
                    @foreach ($p['recency'] as $row)
                        <tr><td>{{ $row['name'] }}</td><td>{{ \Illuminate\Support\Carbon::parse($row['last_import'])->format('j M Y') }}</td><td class="num">{{ $row['days_ago'] }}</td><td>{{ $row['last_label'] }}</td></tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-commercial.cross-part>
    @endif
@endforeach
