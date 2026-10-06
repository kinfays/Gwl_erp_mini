<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Commercial</h2>
            <p>Billing and meter-reading reports, loaded from the billing system's weekly and monthly Excel exports.</p>
        </div>
        @if ($canSeeUploads)
            <div class="ph-right">
                <a class="btn {{ $canUpload ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('commercial.batches') }}">{{ $canUpload ? 'Upload a report' : 'Report uploads' }}</a>
            </div>
        @endif
    </div>

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

    <p class="form-hint" style="margin-top:12px">Dashboards, trends and rankings arrive in the next phases; this page only shows what has been loaded.</p>
</div>
