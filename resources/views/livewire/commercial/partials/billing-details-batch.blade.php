{{-- Phase 8d: the page of a Detailed Billing Report upload (rptBillingDetails_ExP). Expects the BatchShow data: $control, $inFlight, $perDistrict,
     $unmatchedDistricts, $districtOptions, $canRun, $canResolve, $describeDiffs, $blockedErrors, $checks, $importWarnings. Counts, route headings and
     figures only: the file's customers (names, addresses, phones, meter numbers) are never stored, and account numbers appear only on the Billing details lists. --}}
@php
    $scan = $control['scan'] ?? null;
    $progress = $batch->progress ?? [];
    $statusNames = ['REGULAR' => 'Regular', 'ESTIMATED' => 'Estimated (ESTIMATED)', 'ESTIMATE' => 'Estimated (ESTIMATE)', 'UNBILLED' => 'Unbilled'];
    $phaseText = ['queued' => 'waiting for a queue worker', 'reading' => 'reading the file', 'writing' => 'saving the figures', 'linking' => 'linking the customer list', 'review' => 'waiting for your check', 'done' => 'done', 'blocked' => 'blocked', 'failed' => 'stopped'][$batch->phase ?? 'queued'] ?? ($batch->phase ?? '');
@endphp
<div @if ($inFlight) wire:poll.3s @endif style="margin-top:14px">
    @error('run') <p class="form-error">{{ $message }}</p> @enderror

    @if ($inFlight)
        <x-ui.alert tone="info" class="dash-row" data-run-progress>
            This upload is being read in the background: {{ $phaseText }}.
            @if (! empty($progress['rows']))
                {{ number_format($progress['rows']) }} customer rows and {{ number_format($progress['routes'] ?? 0) }} routes read so far.
            @endif
            This page refreshes by itself. A queue worker must be running; a very large file takes several minutes.
        </x-ui.alert>
    @elseif ($batch->status === \App\Models\CommercialImportBatch::STATUS_AWAITING_REVIEW)
        <x-ui.alert tone="warning" class="dash-row" data-run-review>
            The file has been read. Check what it holds below: <strong>nothing counts in any analysis until you load it</strong>.
            @if ($canRun)
                <span style="display:inline-flex;gap:8px;margin-left:8px">
                    <button type="button" class="btn btn-primary" wire:click="loadFigures" wire:loading.attr="disabled">Load these figures</button>
                    <button type="button" class="btn btn-secondary" wire:click="discardRun" wire:confirm="Discard this upload and everything read from it? You can upload the file again.">Discard</button>
                </span>
            @endif
        </x-ui.alert>
    @elseif ($batch->status === \App\Models\CommercialImportBatch::STATUS_BLOCKED)
        <x-ui.alert tone="danger" class="dash-row" data-run-blocked>
            This file was blocked and nothing was loaded:
            <ul style="margin:6px 0 0 18px;list-style:disc">
                @foreach ($blockedErrors as $message)<li>{{ $message }}</li>@endforeach
            </ul>
            A file that does not add up to its own totals has been truncated, filtered or edited: export it again from the billing system. If the limits in Settings (Imports and reconciliation) were too strict, change them and run it again.
            @if ($canRun)
                <span style="display:inline-flex;gap:8px;margin-top:8px">
                    <button type="button" class="btn btn-secondary" wire:click="runAgain" wire:loading.attr="disabled">Run it again</button>
                    <button type="button" class="btn btn-secondary" wire:click="discardRun" wire:confirm="Discard this upload? You can upload the file again.">Discard</button>
                </span>
            @endif
        </x-ui.alert>
    @elseif ($batch->status === \App\Models\CommercialImportBatch::STATUS_FAILED)
        <x-ui.alert tone="danger" class="dash-row" data-run-failed>
            The import stopped: {{ $batch->error_message }}
            @if ($canRun)
                <span style="display:inline-flex;gap:8px;margin-top:8px">
                    <button type="button" class="btn btn-secondary" wire:click="runAgain" wire:loading.attr="disabled">Run it again</button>
                    <button type="button" class="btn btn-secondary" wire:click="discardRun" wire:confirm="Discard this upload? You can upload the file again.">Discard</button>
                </span>
            @endif
        </x-ui.alert>
    @endif

    @if ($scan)
        <div class="stats">
            <div class="stat"><div class="stat-lbl">Customers</div><div class="stat-val">{{ number_format($scan['customers']) }}</div><div class="stat-sub">rows kept, one per customer</div></div>
            <div class="stat"><div class="stat-lbl">Routes</div><div class="stat-val">{{ number_format($scan['routes']) }}</div><div class="stat-sub">in {{ number_format($scan['districts']) }} district(s)</div></div>
            <div class="stat"><div class="stat-lbl">Month total</div><div class="stat-val">{{ number_format($scan['month_total'], 2) }}</div><div class="stat-sub">GH¢ billed</div></div>
            <div class="stat"><div class="stat-lbl">Paid this month</div><div class="stat-val">{{ number_format($scan['paid_this_month'], 2) }}</div><div class="stat-sub">GH¢</div></div>
            <div class="stat"><div class="stat-lbl">Balance due</div><div class="stat-val">{{ number_format($scan['balance_due'], 2) }}</div><div class="stat-sub">GH¢, credits included</div></div>
            <div class="stat"><div class="stat-lbl">Needs matching</div><div class="stat-val">{{ number_format($needsMatching) }}</div><div class="stat-sub">routes whose district is not known</div></div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head"><span class="pg-title">File</span></div>
        <table>
            <tbody>
                <tr><th>Source file</th><td>{{ $batch->source_filename }}</td></tr>
                <tr><th>Bill period</th><td>{{ \App\Services\Commercial\CommercialPeriods::describe($batch->period_from, $batch->period_to) }} ({{ $control['period_raw'] ?? 'as printed' }})</td></tr>
                <tr><th>Uploaded</th><td>{{ optional($batch->created_at)->format('d M Y H:i') ?? '-' }}@if ($batch->importer) by {{ $batch->importer->full_name }}@endif</td></tr>
                @if ($batch->finished_at && $batch->started_at)
                    <tr><th>Read in</th><td>{{ $readSeconds = max(1, (int) $batch->started_at->diffInSeconds($batch->finished_at)) }} {{ $readSeconds === 1 ? 'second' : 'seconds' }}</td></tr>
                @endif
                @if ($batch->supersedes)
                    <tr><th>Replaced</th><td><a href="{{ route('commercial.batches.show', $batch->supersedes) }}">Batch #{{ $batch->supersedes->id }}</a></td></tr>
                @endif
                @if ($batch->notes)
                    <tr><th>Notes</th><td>{{ $batch->notes }}</td></tr>
                @endif
                @if (($scan['skipped_rows'] ?? 0) > 0)
                    <tr><th>Skipped</th><td>{{ number_format($scan['skipped_rows']) }} customer rows in {{ number_format($scan['skipped_routes']) }} route(s) of other districts (not yours): nothing of them was stored.</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    @if ($scan)
        <div class="pg">
            <div class="pg-head"><span class="pg-title">Bill status of the customers</span></div>
            <table data-bill-status>
                <thead><tr><th>Bill status</th><th>Customers</th><th>Share</th></tr></thead>
                <tbody>
                    @foreach ($scan['bill_status'] as $status => $count)
                        <tr><td>{{ $statusNames[$status] ?? ($status === '' ? '(blank)' : $status) }}</td><td>{{ number_format($count) }}</td><td>{{ $scan['customers'] > 0 ? number_format($count / $scan['customers'] * 100, 1) : '-' }}%</td></tr>
                    @endforeach
                </tbody>
            </table>
            <p class="form-hint" style="padding:8px 14px">ESTIMATED and ESTIMATE are both estimated bills: the meter is faulty or missing, or a working meter was not read.</p>
        </div>

        <div class="pg">
            <div class="pg-head"><span class="pg-title">Districts in the file</span></div>
            <table data-districts>
                <thead><tr><th>District</th><th>Routes</th><th>Customers</th><th>Month total</th><th>Paid this month</th><th>Balance due</th></tr></thead>
                <tbody>
                    @foreach ($perDistrict as $district)
                        <tr>
                            <td>{{ $district['name'] }}</td><td>{{ number_format($district['routes']) }}</td><td>{{ number_format($district['customers']) }}</td>
                            <td>{{ number_format($district['month_total'], 2) }}</td><td>{{ number_format($district['paid'], 2) }}</td><td>{{ number_format($district['balance_due'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($checks)
        <div class="pg">
            <div class="pg-head"><span class="pg-title">Reconciliation and integrity</span></div>
            <table>
                <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
                <tbody>
                    @foreach ($checks as $check)
                        <tr><td>{{ $check['label'] }}</td><td><x-ui.status-pill domain="commercial" :status="$check['passed'] ? 'pass' : 'fail'" /></td><td>{{ $check['detail'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! empty($control['flagged_routes']))
        <div class="pg" data-flagged-routes>
            <div class="pg-head"><span class="pg-title">Routes that disagree with their own totals row (the file still imported)</span></div>
            <table>
                <thead><tr><th>Route</th><th>What disagrees</th></tr></thead>
                <tbody>
                    @foreach ($control['flagged_routes'] as $flag)
                        <tr><td>{{ $flag['label'] }}</td><td>{{ $describeDiffs($flag['diffs']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($scan)
        <div class="pg" data-integrity>
            <div class="pg-head"><span class="pg-title">Other counts worth a look</span></div>
            <table>
                <tbody>
                    <tr><th>Accounts that appear more than once</th><td>{{ number_format($scan['integrity']['duplicates']) }}</td></tr>
                    <tr><th>Month total is not the five charges added (more than 5 pesewas)</th><td>{{ number_format($scan['integrity']['components_off']) }}</td></tr>
                    <tr><th>Negative consumption</th><td>{{ number_format($scan['integrity']['negative_consume']) }}</td></tr>
                    <tr><th>Bill month outside the bill period</th><td>{{ number_format($scan['integrity']['bill_month_outside']) }}</td></tr>
                    <tr><th>Rate x consume differs from the water charge (the billing system's own arithmetic)</th><td>{{ number_format($scan['integrity']['tariff_mismatch']) }}</td></tr>
                    @if (! empty($scan['unknown_statuses']))
                        <tr><th>Account status codes not in the status lookups</th><td>{{ implode(', ', $scan['unknown_statuses']) }}</td></tr>
                    @endif
                    @if (! empty($scan['unknown_categories']))
                        <tr><th>Service categories not in the category lookups</th><td>{{ implode(', ', $scan['unknown_categories']) }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    @endif

    @if (! empty($control['link']))
        @php $link = $control['link']; @endphp
        <div class="pg" data-customer-link>
            <div class="pg-head"><span class="pg-title">Link to the customer list</span></div>
            @if (! ($link['available'] ?? false))
                <p class="form-hint" style="padding:10px 14px">{{ $link['reason'] ?? 'The customer list was not linked.' }}</p>
            @else
                <table>
                    <tbody>
                        <tr><th>Accounts in this file</th><td>{{ number_format($link['accounts']) }}</td></tr>
                        <tr><th>Found in the customer list</th><td>{{ number_format($link['in_customer_list']) }}</td></tr>
                        <tr><th>In billing but not in the customer list</th><td>{{ number_format($link['not_in_customer_list']) }}</td></tr>
                        <tr><th>Customer-list accounts of these districts that are not in this file</th><td>{{ number_format($link['list_accounts_not_in_file']) }}</td></tr>
                        <tr><th>Accounts given their billing route (ops_route_id)</th><td>{{ number_format($link['linked']) }}@if (($link['routes_left_to_a_newer_upload'] ?? 0) > 0) ({{ number_format($link['routes_left_to_a_newer_upload']) }} route(s) left to a newer upload)@endif</td></tr>
                    </tbody>
                </table>
                <p class="form-hint" style="padding:8px 14px">Linking only records the billing route of each account. It does not change who sees which customer.</p>
            @endif
        </div>
    @endif

    @if ($importWarnings)
        <div class="pg">
            <div class="pg-head"><span class="pg-title">Warnings at import</span></div>
            <ul style="padding:12px 14px 12px 30px;font-size:12px;list-style:disc">
                @foreach ($importWarnings as $warning)<li>{{ $warning }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if ($unmatchedDistricts->isNotEmpty())
        <div class="pg">
            <div class="pg-head"><span class="pg-title">Districts not matched</span></div>
            <table>
                <thead><tr><th>District in report</th><th>Routes</th><th></th></tr></thead>
                <tbody>
                    @foreach ($unmatchedDistricts as $district)
                        <tr>
                            <td>{{ $district->district_label_raw }}</td>
                            <td>{{ $district->route_count }}</td>
                            <td>
                                @if ($canResolve && ! $batch->isVoided() && $batch->isLive())
                                    <button type="button" class="btn btn-secondary" wire:click="startResolvingDistrict(@js($district->district_label_raw))">Resolve</button>
                                @endif
                            </td>
                        </tr>
                        @if ($resolvingDistrictLabel === $district->district_label_raw)
                            <tr>
                                <td colspan="3">
                                    <div class="form-row">
                                        <div class="form-field">
                                            <label class="form-label">Which district is "{{ $district->district_label_raw }}"?</label>
                                            <select class="form-input" wire:model="resolveDistrictId">
                                                <option value="">Choose a district</option>
                                                @foreach ($districtOptions as $option)<option value="{{ $option->id }}">{{ $option->district_name }}</option>@endforeach
                                            </select>
                                            <span class="form-hint">Remembered for every later upload.</span>
                                            @error('resolveDistrictId') <span class="form-error">{{ $message }}</span> @enderror
                                        </div>
                                        <div class="form-field" style="justify-content:end">
                                            <button type="button" class="btn btn-primary" wire:click="saveDistrictResolution">Save and re-match</button>
                                            <button type="button" class="btn btn-secondary" wire:click="cancelResolvingDistrict">Cancel</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
            @if (! $batch->isLive())
                <p class="form-hint" style="padding:8px 14px">Districts can be matched once the upload is loaded.</p>
            @endif
        </div>
    @endif
</div>
