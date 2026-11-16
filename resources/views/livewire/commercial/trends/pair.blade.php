{{-- The body of a T2 / T6 part (and of the "change on the previous period" block of the single-file screens): the periods compared, the headline measures, and the same by
     district (and by CCA and route for the route-grain reports). Expects $p (TrendReport kindPair), $vs (the label of the earlier period). --}}
<p class="ui-hint" data-periods>
    This period: <strong>{{ $p['current']['label'] ?? 'none' }}</strong>@if (! empty($p['current']['length'])) ({{ $p['current']['length'] }} days)@endif.
    @if ($p['compared'])
        {{ $vs }}: <strong>{{ $p['compared']['label'] }}</strong>@if (! empty($p['compared']['length'])) ({{ $p['compared']['length'] }} days)@endif.
    @endif
</p>
@if (! $p['compared'])
    <p class="ui-hint" data-no-comparable><x-ui.icon name="info" class="icon-sm" /> {{ $p['note'] }} No change is shown.</p>
@endif
@if (! empty($p['held_back']))
    <p class="ui-hint">The reading figures are held back: too few CCAs to show them without naming them.</p>
@endif
@if (($p['measures'] ?? []) !== [])
    <x-commercial.trend-table :groups="[['label' => null, 'measures' => $p['measures']]]" :vs="$vs" label="Headline figures" />
@endif
@foreach (['districts' => 'District', 'ccas' => 'CCA', 'routes' => 'Route'] as $set => $noun)
    @if (! empty($p[$set]))
        <h4 style="margin-top:14px">By {{ strtolower($noun) }}</h4>
        <x-commercial.trend-table :groups="$p[$set]" :vs="$vs" :noun="$noun" :label="'Change by '.strtolower($noun)" />
    @endif
@endforeach
