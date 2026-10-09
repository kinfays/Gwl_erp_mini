<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    <div class="page-head">
        <div class="ph-left">
            <h2>Commercial</h2>
            <p>Billing and meter-reading reports, loaded from the billing system's weekly and monthly Excel exports.</p>
        </div>
        @if ($canSeeUploads || $canSeeCustomers)
            <div class="ph-right">
                @if ($canSeeCustomers)
                    <a class="btn btn-secondary" href="{{ route('commercial.customers') }}">Customer list</a>
                @endif
                @if ($canSeeUploads)
                    <a class="btn {{ $canUpload ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('commercial.batches') }}">{{ $canUpload ? 'Upload a report' : 'Report uploads' }}</a>
                @endif
            </div>
        @endif
    </div>

    @if ($kpis)
        <div class="dash-row" style="margin-top:14px">
            <h3 style="margin-bottom:8px">{{ $kpis['month'] }} <span class="ui-hint">latest complete month</span></h3>
            <div class="ui-stat-grid">
                @foreach ($kpis['tiles'] as $tile)
                    <x-ui.stat-tile :label="$tile['label']" :value="$tile['value']" :icon="$tile['icon']" :tone="$tile['tone']"
                        :meta="$tile['meta']" :delta="$tile['delta']" :delta-tone="$tile['delta_tone']" :delta-direction="$tile['direction']" />
                @endforeach
            </div>
        </div>
    @endif

    @if ($billingKpis)
        <div class="dash-row" style="margin-top:14px">
            <h3 style="margin-bottom:8px">Billing, {{ $billingKpis['period'] }} <span class="ui-hint">{{ $billingKpis['label'] }}</span></h3>
            <div class="ui-stat-grid">
                @foreach ($billingKpis['tiles'] as $tile)
                    <x-ui.stat-tile :label="$tile['label']" :value="$tile['value']" :icon="$tile['icon']" :tone="$tile['tone']" :meta="$tile['meta']"
                        :href="$canSeeBilling ? route('commercial.billing') : null" />
                @endforeach
            </div>
        </div>
    @endif

    @if (count($trend) > 0)
        <x-ui.card title="Monthly trend" description="Skip rate and coverage by month. The current month is in progress and still filling up." class="dash-row">
            @php $labels = array_map(fn ($row) => $row['label'].($row['in_progress'] ? ' (in progress)' : ''), $trend); @endphp
            <div wire:key="home-trend-{{ md5(json_encode($trend)) }}">
                <x-ui.chart type="line" label="Skip rate and coverage per month" unit="%" :labels="$labels"
                    :series="[
                        ['label' => 'Skip rate', 'data' => array_column($trend, 'skip_rate'), 'color' => 'warning'],
                        ['label' => 'Coverage', 'data' => array_column($trend, 'coverage'), 'color' => 'series-1'],
                    ]" height="240" />
            </div>
            @if ($canSeeReading)
                <x-slot:actions><a href="{{ route('commercial.reading') }}" class="btn btn-ghost btn-sm">Open Meter Reading</a></x-slot:actions>
            @endif
        </x-ui.card>
    @endif

    <div class="pg" style="margin-top:14px">
        <div class="pg-head">
            <span class="pg-title">Latest data loaded</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Report</th>
                    <th>Region</th>
                    <th>Period</th>
                    <th>Rows</th>
                    <th>Uploaded</th>
                    <th>Status</th>
                    @if ($canSeeUploads)<th></th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($latest as $batch)
                    <tr>
                        <td>{{ \App\Models\CommercialImportBatch::typeLabel($batch->report_type) }}</td>
                        <td>{{ $batch->region?->region_name ?? $batch->region_label_raw ?? '-' }}</td>
                        <td>
                            {{ $batch->period_from->format('M Y') }}@if ($batch->period_from->format('Y-m') !== $batch->period_to->format('Y-m')) - {{ $batch->period_to->format('M Y') }}@endif
                            @if ($batch->customer_segment && $batch->customer_segment !== 'all')
                                <span class="form-hint">{{ str($batch->customer_segment)->replace('_', ' ')->title() }}</span>
                            @endif
                        </td>
                        <td>{{ number_format($batch->row_count) }}</td>
                        <td>{{ optional($batch->imported_at)->format('d M Y H:i') ?? '-' }}</td>
                        <td><x-ui.status-pill domain="commercial" :status="$batch->status" /></td>
                        @if ($canSeeUploads)
                            <td><a class="btn btn-secondary" href="{{ route('commercial.batches.show', $batch) }}">Open</a></td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canSeeUploads ? 7 : 6 }}">No reports have been loaded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    
</div>
