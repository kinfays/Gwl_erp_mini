{{-- The route-grain headline figures of the Summary and Home screens (Phase 8f): reading by route (coverage, skip rate) and the Detailed Billing Report (collection ratio, estimated
     share, owing balance), each with the period it used and "n of d days are covered". Expects $routeGrain (TrendReport::headline(), or null). Nothing is drawn without it, so a
     region with no route-grain upload sees the page it always had. --}}
@if ($routeGrain)
    @php
        $fmt = fn ($m) => $m['value'] === null ? '–' : ($m['unit'] === 'pct' ? number_format($m['value'], 1).'%' : 'GH¢ '.number_format($m['value'], 2));
    @endphp
    <div class="dash-row" data-route-grain>
        <h3 style="margin-bottom:8px">Route-grain headline figures <span class="ui-hint">{{ $routeGrain['region'] }}@if ($routeGrain['typed']), your date range @else, the newest upload of each report @endif</span></h3>
        <div class="ui-grid ui-grid-2">
            @foreach (['reading', 'billing'] as $kind)
                @if ($routeGrain[$kind])
                    @php $part = $routeGrain[$kind]; @endphp
                    <x-ui.card :title="$part['title']" :description="$part['label'].' · '.$part['coverage_text']" data-route-grain-part="{{ $kind }}">
                        @if ($part['held_back'])
                            <p class="ui-hint">These figures are held back: too few CCAs to show them without naming them.</p>
                        @else
                            <div class="ui-stat-grid">
                                @foreach ($part['measures'] as $m)
                                    <x-ui.stat-tile :label="$m['label']" :value="$fmt($m)" icon="gauge"
                                        :delta="$m['met'] === null ? null : ($m['met'] ? 'Within target' : 'Outside target')" :delta-tone="$m['met'] === null ? 'neutral' : ($m['met'] ? 'good' : 'bad')"
                                        :meta="$m['target'] === null ? null : ($m['key'] === 'skip_rate' ? 'Configured target: at most ' : 'Configured target: at least ').number_format($m['target'], 0).'%'" />
                                @endforeach
                            </div>
                        @endif
                    </x-ui.card>
                @endif
            @endforeach
        </div>
    </div>
@endif
