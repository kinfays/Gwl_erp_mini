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

    @include('livewire.commercial.partials.uploads-switch', ['current' => 'reports'])

    @foreach ($overdue as $late)
        <x-ui.alert tone="warning" class="dash-row" wire:key="late-{{ $late['region_id'] }}-{{ $late['district_id'] ?? 'r' }}-{{ $late['report_type'] }}">
            @if ($late['last_upload'] === null)
                {{ ucfirst($late['label']) }} upload overdue for {{ $late['region'] }}: none has arrived (reminder limit {{ $late['limit'] }} days).
            @else
                {{ ucfirst($late['label']) }} upload overdue for {{ $late['region'] }}: {{ $late['days'] }} days since the last one on {{ $late['last_upload']->format('d M Y') }} (reminder limit {{ $late['limit'] }} days).
            @endif
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
                            <span style="color:var(--color-text-secondary)">excluded (system account): {{ $preview['system_count'] }} rows, not imported</span>
                        @endif
                        @if (($preview['skipped_districts']['rows'] ?? 0) > 0)
                            <span style="color:var(--color-text-secondary)">{{ number_format($preview['skipped_districts']['rows']) }} rows of other districts skipped, not imported</span>
                        @endif
                        @if (! empty($preview['flagged']['routes']) || ! empty($preview['flagged']['districts']))
                            <span style="color:#A36A2D">{{ count($preview['flagged']['routes'] ?? []) }} route(s) and {{ count($preview['flagged']['districts'] ?? []) }} district figure(s) flagged (the file still imports)</span>
                        @endif
                        <span style="color:#A32D2D">{{ $preview['error_count'] }} blocking</span>
                        <span style="color:#A36A2D">{{ $preview['warning_count'] }} warnings</span>
                    </div>

                    @if (! empty($preview['route_report']))
                        {{-- Phase 8c: what the reading report BY ROUTE holds (the generic preview has no sheets, routes or owners) --}}
                        @php $rr = $preview['route_report']; @endphp
                        <div data-route-report style="margin-top:10px;font-size:12px">
                            <p>
                                <strong>Period read:</strong> {{ $rr['period_label'] ?? 'not found in the file' }}@if ($rr['period_kind'] ?? null) ({{ $rr['period_kind'] === 'range' ? 'a date range' : ($rr['period_kind'] === 'month' ? 'a whole month' : 'one day') }})@endif.
                                <strong>Region:</strong> {{ $preview['region']['name'] ?? $preview['region']['raw'] ?? 'not found' }}.
                                <strong>{{ number_format($rr['sheets']) }}</strong> CCA sheets, <strong>{{ number_format($rr['routes']) }}</strong> routes, <strong>{{ number_format($rr['districts']) }}</strong> districts, <strong>{{ number_format($rr['ccas']) }}</strong> CCAs.
                            </p>
                            @if ($rr['unplaced_rows'] > 0)
                                <p class="form-hint">{{ number_format($rr['unplaced_rows']) }} route rows name a district the system does not know yet. They import anyway, and you can resolve the district afterwards on the batch page.</p>
                            @endif
                            @if ($rr['flagged_rows'] > 0 || $rr['flagged_sheets'] > 0)
                                <p class="form-hint">{{ number_format($rr['flagged_rows']) }} row(s) and {{ number_format($rr['flagged_sheets']) }} sheet total(s) do not add up. They import flagged; the file is blocked only when too many do.</p>
                            @endif
                            @if ($rr['owner_decision_count'] > 0)
                                <details open style="margin-top:6px">
                                    <summary>{{ number_format($rr['owner_decision_count']) }} route(s) under more than one CCA: the owner is the CCA with the visits ({{ number_format($rr['assigned_no_visits']) }} row(s) kept as assigned, no visits)</summary>
                                    <table style="margin-top:6px">
                                        <thead><tr><th>Route</th><th>Owner (counts the strength)</th><th>Also listed, no visits counted</th></tr></thead>
                                        <tbody>
                                            @foreach ($rr['owner_decisions'] as $decision)
                                                <tr><td>{{ $decision['route'] }}</td><td>{{ $decision['owner'] }} {{ $decision['owner_name'] }}</td><td>{{ implode(', ', $decision['others']) }}</td></tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    @if ($rr['owner_decision_count'] > count($rr['owner_decisions']))<p class="form-hint">The first {{ count($rr['owner_decisions']) }} are listed.</p>@endif
                                </details>
                            @endif
                            @if ($rr['no_routes_count'] > 0)
                                <p style="margin-top:6px"><strong>{{ number_format($rr['no_routes_count']) }} CCA(s) with no routes</strong> (kept on record): {{ collect($rr['no_routes'])->map(fn ($c) => trim($c['staff_id'].' '.$c['name']))->implode(', ') }}@if ($rr['no_routes_count'] > count($rr['no_routes'])) …@endif</p>
                            @endif
                            @if ($rr['no_visits_count'] > 0)
                                <p style="margin-top:6px"><strong>{{ number_format($rr['no_visits_count']) }} CCA(s) with routes but no visits</strong> (kept on record): {{ collect($rr['no_visits'])->map(fn ($c) => trim($c['staff_id'].' '.$c['name']))->implode(', ') }}@if ($rr['no_visits_count'] > count($rr['no_visits'])) …@endif</p>
                            @endif
                        </div>
                    @endif

                    @if (! empty($preview['details_report']))
                        {{-- Phase 8d: the Detailed Billing Report is recognised from the top of its first sheet; it is read in the background by its own form --}}
                        @php $dr = $preview['details_report']; @endphp
                        <div data-details-report style="margin-top:10px;font-size:12px">
                            <p>
                                <strong>Period read:</strong> {{ $dr['period_label'] ?? 'not found in the file' }}@if ($dr['period_kind'] ?? null) ({{ $dr['period_kind'] === 'range' ? 'a date range' : ($dr['period_kind'] === 'month' ? 'a whole month' : 'one day') }})@endif.
                                <strong>Region:</strong> {{ $preview['region']['name'] ?? $preview['region']['raw'] ?? 'not found' }}.
                                <strong>{{ number_format($dr['columns']) }}</strong> of the report's columns were found.
                            </p>
                        </div>
                    @endif

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

    @if ($canUpload)
        {{-- Phase 8d: the Detailed Billing Report is a plain POST (a 130 MB sheet is far bigger than a Livewire temporary upload may be) and is read in the background --}}
        <div class="pg" style="margin-top:14px" data-billing-details-form>
            <div class="pg-head"><span class="pg-title">Detailed billing report (one row per customer)</span></div>
            <form method="POST" action="{{ route('commercial.batches.billing-details') }}" enctype="multipart/form-data" style="padding:14px">
                @csrf
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label" for="billing-details-file">Report file (.xlsx)</label>
                        <input id="billing-details-file" type="file" name="file" class="form-input" accept=".xlsx" required>
                        <span class="form-hint">rptBillingDetails_ExP (Detailed Billing Report). The bill period and the region are read from the file. It is read in the background (a queue worker must be running), so the page you land on shows its progress. Up to {{ $billingDetailsMaxMb }} MB.</span>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="billing-details-notes">Notes</label>
                        <textarea id="billing-details-notes" name="notes" rows="2" class="form-input" placeholder="Optional">{{ old('notes') }}</textarea>
                    </div>
                </div>
                <label style="display:flex;gap:8px;align-items:center;font-size:12px;margin:6px 0">
                    <input type="hidden" name="review" value="0">
                    <input type="checkbox" name="review" value="1" checked> Show me what is in the file (routes, districts, customers, bill statuses and checks) before anything is loaded
                </label>
                <button type="submit" class="btn btn-primary">Upload detailed billing report</button>
            </form>
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
                        <option value="{{ $status }}">{{ \App\Models\CommercialImportBatch::statusLabel($status) }}</option>
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
                            @if ($batch->isReadingRoute() || $batch->isBillingDetails())
                                {{ \App\Services\Commercial\CommercialPeriods::describe($batch->period_from, $batch->period_to) }}
                            @else
                                {{ $batch->period_from->format('M Y') }}@if ($batch->period_from->format('Y-m') !== $batch->period_to->format('Y-m')) - {{ $batch->period_to->format('M Y') }}@endif
                            @endif
                            @if ($batch->customer_segment && $batch->customer_segment !== 'all')
                                <span class="form-hint">{{ str($batch->customer_segment)->replace('_', ' ')->title() }}</span>
                            @endif
                        </td>
                        <td>{{ number_format($batch->row_count) }}</td>
                        <td>{{ number_format(max(0, $batch->row_count - $batch->matched_count)) }}</td>
                        <td>
                            {{ optional($batch->imported_at ?? $batch->created_at)->format('d M Y H:i') ?? '-' }}
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

    <x-loading.drop-fill overlay wire:loading.flex wire:target="previewFile,file,runImport" label="Working on the file…" />
</div>
