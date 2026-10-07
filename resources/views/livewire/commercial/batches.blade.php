<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Report Uploads</h2>
            <p>Upload the meter-reading and billing summary exports. Each file is checked against its own totals before it is loaded.</p>
        </div>
        @if ($canUpload)
            <div class="ph-right">
                <button type="button" class="btn btn-primary" wire:click="openUpload">Upload Report</button>
            </div>
        @endif
    </div>

    @foreach ($overdue as $late)
        <x-ui.alert tone="warning" class="dash-row" wire:key="late-{{ $late['region_id'] }}-{{ $late['report_type'] }}">
            {{ ucfirst($late['label']) }} upload overdue for {{ $late['region'] }}: {{ $late['days'] }} days since the last one on {{ $late['last_upload']->format('d M Y') }} (reminder limit {{ $late['limit'] }} days).
        </x-ui.alert>
    @endforeach

    @if ($showUpload)
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">Upload a report</span>
                <div class="ph-right">
                    <button type="button" class="btn btn-secondary" wire:click="closeUpload">Cancel</button>
                </div>
            </div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Report file (.xlsx)</label>
                        <input type="file" class="form-input" wire:model="file" accept=".xlsx">
                        <span class="form-hint">rptReadingSummDate (Customer Meter Reading Report - CCA Summary) or rptBillingSumm_ExP (Billing Summary Report By Routes). The report type is detected from the file. Up to {{ $maxMb }} MB.</span>
                        @error('file') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Notes</label>
                        <textarea rows="2" class="form-input" wire:model.defer="notes" placeholder="Optional, e.g. week ending 27 Sep"></textarea>
                        @error('notes') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-secondary" wire:click="previewFile" wire:loading.attr="disabled" wire:target="previewFile,file">Preview File</button>
                        <button type="button" class="btn btn-primary" wire:click="runImport" wire:loading.attr="disabled" wire:target="runImport">Import</button>
                    </div>
                </div>

                @if ($preview)
                    <div style="display:flex;gap:12px;font-size:11px;margin-top:4px;flex-wrap:wrap">
                        @if ($preview['report_type'] ?? null)
                            <strong>{{ \App\Models\CommercialImportBatch::typeLabel($preview['report_type']) }}</strong>
                        @endif
                        @if (($preview['region']['name'] ?? null) || ($preview['region']['raw'] ?? null))
                            <span>{{ $preview['region']['name'] ?? $preview['region']['raw'] }}</span>
                        @endif
                        @if ($preview['period']['from'] ?? null)
                            <span>{{ \Illuminate\Support\Carbon::parse($preview['period']['from'])->format('M Y') }}@if (\Illuminate\Support\Carbon::parse($preview['period']['from'])->format('Y-m') !== \Illuminate\Support\Carbon::parse($preview['period']['to'])->format('Y-m')) - {{ \Illuminate\Support\Carbon::parse($preview['period']['to'])->format('M Y') }}@endif</span>
                        @endif
                        <span>{{ number_format($preview['total_rows']) }} rows</span>
                        <span style="color:#3B6D11">{{ number_format($preview['matched_count']) }} matched</span>
                        @if ($preview['unmatched_count'] > 0)
                            <span style="color:#A36A2D">{{ $preview['unmatched_count'] }} unmatched</span>
                        @endif
                        @if (($preview['system_count'] ?? 0) > 0)
                            <span style="color:var(--color-text-secondary)">{{ $preview['system_count'] }} system-account rows (kept, never ranked)</span>
                        @endif
                        <span style="color:#A32D2D">{{ $preview['error_count'] }} blocking</span>
                        <span style="color:#A36A2D">{{ $preview['warning_count'] }} warnings</span>
                    </div>

                    @if ($preview['checks'])
                        <table style="margin-top:12px">
                            <thead><tr><th>Reconciliation check</th><th>Result</th><th>Detail</th></tr></thead>
                            <tbody>
                                @foreach ($preview['checks'] as $check)
                                    <tr>
                                        <td>{{ $check['label'] }}</td>
                                        <td><x-ui.status-pill domain="commercial" :status="$check['passed'] ? 'pass' : 'fail'" /></td>
                                        <td>{{ $check['detail'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if ($preview['blocked'])
                        <p class="form-error" style="margin-top:8px">
                            This file is blocked and cannot be imported until the problems below are fixed. A file that does not add up to its own totals has been truncated, filtered or edited: export it again from the billing system.
                        </p>
                    @endif

                    @if (! empty($preview['unresolved_region']))
                        <div class="form-row" style="margin-top:8px">
                            <div class="form-field">
                                <label class="form-label">The report says region "{{ $preview['unresolved_region'] }}". Which region is that?</label>
                                <select class="form-input" wire:model="aliasRegionId">
                                    <option value="">Choose a region</option>
                                    @foreach ($regions as $region)
                                        <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                                    @endforeach
                                </select>
                                @error('aliasRegionId') <span class="form-error">{{ $message }}</span> @enderror
                            </div>
                            <div class="form-field" style="justify-content:end">
                                @if ($canResolve)
                                    <button type="button" class="btn btn-secondary" wire:click="saveRegionAlias">Remember this region</button>
                                @else
                                    <span class="form-hint">Ask a Commercial officer who can resolve matches to map this region.</span>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if ($preview['errors'] || $preview['warnings'])
                        <table style="margin-top:12px">
                            <thead><tr><th>Where</th><th>Issue</th></tr></thead>
                            <tbody>
                                @foreach ($preview['errors'] as $issue)
                                    <tr><td>{{ $issue['row'] }}</td><td class="form-error">{{ $issue['message'] }}</td></tr>
                                @endforeach
                                @foreach ($preview['warnings'] as $issue)
                                    <tr><td>{{ $issue['row'] }}</td><td>{{ $issue['message'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Batches</span>
            <div class="ph-right">
                <select class="form-input" wire:model.live="typeFilter">
                    <option value="">All reports</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}">{{ \App\Models\CommercialImportBatch::typeLabel($type) }}</option>
                    @endforeach
                </select>
                <select class="form-input" wire:model.live="statusFilter">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}">{{ str($status)->title() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Report</th>
                    <th>Region</th>
                    <th>Period</th>
                    <th>Rows</th>
                    <th>Needs matching</th>
                    <th>Uploaded</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td>{{ $batch->id }}</td>
                        <td>
                            {{ \App\Models\CommercialImportBatch::typeLabel($batch->report_type) }}
                            <span class="form-hint">{{ $batch->source_filename }}</span>
                        </td>
                        <td>{{ $batch->region?->region_name ?? $batch->region_label_raw ?? '-' }}</td>
                        <td>
                            {{ $batch->period_from->format('M Y') }}@if ($batch->period_from->format('Y-m') !== $batch->period_to->format('Y-m')) - {{ $batch->period_to->format('M Y') }}@endif
                            @if ($batch->customer_segment && $batch->customer_segment !== 'all')
                                <span class="form-hint">{{ str($batch->customer_segment)->replace('_', ' ')->title() }}</span>
                            @endif
                        </td>
                        <td>{{ number_format($batch->row_count) }}</td>
                        <td>{{ number_format(max(0, $batch->row_count - $batch->matched_count)) }}</td>
                        <td>
                            {{ optional($batch->imported_at)->format('d M Y H:i') ?? '-' }}
                            <span class="form-hint">{{ $batch->importer?->full_name ?? '' }}</span>
                        </td>
                        <td><x-ui.status-pill domain="commercial" :status="$batch->status" /></td>
                        <td><a class="btn btn-secondary" href="{{ route('commercial.batches.show', $batch) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9">No reports have been uploaded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $batches->links() }}</div>
    </div>
</div>
