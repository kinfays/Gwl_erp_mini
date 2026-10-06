<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>{{ $batchTypeLabel }} - batch #{{ $batch->id }}</h2>
            <p>
                {{ $batch->region?->region_name ?? $batch->region_label_raw ?? 'No region' }} &middot;
                {{ $batch->period_from->format('M Y') }}@if ($batch->period_from->format('Y-m') !== $batch->period_to->format('Y-m')) - {{ $batch->period_to->format('M Y') }}@endif
                @if ($batch->customer_segment && $batch->customer_segment !== 'all')
                    &middot; {{ str($batch->customer_segment)->replace('_', ' ')->title() }}
                @endif
                &middot; <x-ui.status-pill domain="commercial" :status="$batch->status" />
            </p>
        </div>
        <div class="ph-right">
            <a class="btn btn-secondary" href="{{ route('commercial.batches') }}">Back to Uploads</a>
            @if ($canResolve && ! $batch->isVoided() && ($batch->row_count - $batch->matched_count) > 0)
                <button type="button" class="btn btn-secondary" wire:click="rematch" wire:loading.attr="disabled">Re-match</button>
            @endif
            @if ($canVoid && ! $batch->isVoided())
                <button type="button" class="btn btn-secondary" wire:click="startVoid">Void batch</button>
            @endif
        </div>
    </div>

    @if ($batch->isVoided())
        <p class="form-error" style="margin-top:10px">
            Voided {{ optional($batch->voided_at)->format('d M Y H:i') }}@if ($batch->voider) by {{ $batch->voider->full_name }}@endif: {{ $batch->void_reason }}.
            Its rows stay on record but no longer count in any analysis.
        </p>
    @elseif ($batch->status === \App\Models\CommercialImportBatch::STATUS_SUPERSEDED)
        <p class="form-hint" style="margin-top:10px">A newer batch now supplies this data; this one stays on record but is no longer used.</p>
    @endif

    @if ($confirmingVoid)
        <div class="pg">
            <div class="pg-head"><span class="pg-title">Void this batch</span></div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Reason</label>
                        <textarea rows="2" class="form-input" wire:model.defer="voidReason" placeholder="Why is this batch wrong or no longer wanted?"></textarea>
                        @error('voidReason') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="voidBatch" wire:loading.attr="disabled">Void batch</button>
                        <button type="button" class="btn btn-secondary" wire:click="cancelVoid">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="stats" style="margin-top:14px">
        <div class="stat">
            <div class="stat-lbl">Rows</div>
            <div class="stat-val">{{ number_format($batch->row_count) }}</div>
            <div class="stat-sub">{{ $batch->isReading() ? 'reader-month rows' : 'route rows' }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Needs matching</div>
            <div class="stat-val">{{ number_format(max(0, $batch->row_count - $batch->matched_count)) }}</div>
            <div class="stat-sub">{{ $batch->isReading() ? 'readers not in the directory' : 'routes without a district' }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Warnings at import</div>
            <div class="stat-val">{{ number_format($batch->warning_count) }}</div>
            <div class="stat-sub">listed below</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Reconciliation</div>
            <div class="stat-val">{{ $batch->reconciliation_passed ? 'Passed' : 'Failed' }}</div>
            <div class="stat-sub">checked against the file's own totals</div>
        </div>
    </div>

    <div class="pg">
        <div class="pg-head"><span class="pg-title">File</span></div>
        <table>
            <tbody>
                <tr><th>Source file</th><td>{{ $batch->source_filename }}</td></tr>
                <tr><th>Uploaded</th><td>{{ optional($batch->imported_at)->format('d M Y H:i') ?? '-' }}@if ($batch->importer) by {{ $batch->importer->full_name }}@endif</td></tr>
                @if ($batch->supersedes)
                    <tr><th>Replaced</th><td><a href="{{ route('commercial.batches.show', $batch->supersedes) }}">Batch #{{ $batch->supersedes->id }}</a></td></tr>
                @endif
                @if ($batch->billing_status_raw)
                    <tr><th>Billing status</th><td>{{ $batch->billing_status_raw }}</td></tr>
                @endif
                @if ($batch->notes)
                    <tr><th>Notes</th><td>{{ $batch->notes }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    <div class="pg">
        <div class="pg-head"><span class="pg-title">Reconciliation</span></div>
        <table>
            <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
            <tbody>
                @forelse ($checks as $check)
                    <tr>
                        <td>{{ $check['label'] }}</td>
                        <td><x-ui.status-pill domain="commercial" :status="$check['passed'] ? 'pass' : 'fail'" /></td>
                        <td>{{ $check['detail'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">No checks were recorded for this batch.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($importWarnings)
        <div class="pg">
            <div class="pg-head"><span class="pg-title">Warnings at import</span></div>
            <ul style="padding:12px 14px 12px 30px;font-size:12px;list-style:disc">
                @foreach ($importWarnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($batch->isReading())
        <div class="pg">
            <div class="pg-head"><span class="pg-title">By month</span></div>
            <table>
                <thead>
                    <tr><th>Month</th><th>Verified strength</th><th>Readers</th><th>Read</th><th>Skipped</th><th>Visited</th></tr>
                </thead>
                <tbody>
                    @foreach ($monthSummary as $month)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($month->month)->format('M Y') }}</td>
                            <td>{{ number_format($strengths[substr((string) $month->month, 0, 10)] ?? 0) }}</td>
                            <td>{{ number_format($month->readers) }}</td>
                            <td>{{ number_format($month->read_total) }}</td>
                            <td>{{ number_format($month->skipped_total) }}</td>
                            <td>{{ number_format($month->visited_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($systemRows > 0)
                <p class="form-hint" style="padding:8px 14px">Totals above exclude the system account ({{ $systemRows }} rows), which is kept but never counted as a reader.</p>
            @endif
        </div>

        @if ($unmatchedReaders->isNotEmpty())
            <div class="pg">
                <div class="pg-head"><span class="pg-title">Readers not in the staff directory</span></div>
                <table>
                    <thead><tr><th>Staff ID</th><th>Name in report</th><th>Months</th><th>Visited</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($unmatchedReaders as $reader)
                            <tr>
                                <td>{{ $reader->reader_staff_id }}</td>
                                <td>{{ $reader->reader_name }}</td>
                                <td>{{ $reader->months }}</td>
                                <td>{{ number_format($reader->visited_total) }}</td>
                                <td>
                                    @if ($canResolve && ! $batch->isVoided())
                                        <button type="button" class="btn btn-secondary" wire:click="startLinkingReader('{{ $reader->reader_staff_id }}')">Link to employee</button>
                                    @endif
                                </td>
                            </tr>
                            @if ($linkingReaderId === (string) $reader->reader_staff_id)
                                <tr>
                                    <td colspan="5">
                                        <div class="form-row">
                                            <div class="form-field">
                                                <label class="form-label">Find the employee (staff ID or name)</label>
                                                <input class="form-input" wire:model.live.debounce.300ms="employeeSearch">
                                                @error('employeeSearch') <span class="form-error">{{ $message }}</span> @enderror
                                            </div>
                                            <div class="form-field" style="justify-content:end">
                                                <button type="button" class="btn btn-secondary" wire:click="cancelLinkingReader">Cancel</button>
                                            </div>
                                        </div>
                                        @foreach ($candidates as $employee)
                                            <div style="display:flex;gap:10px;align-items:center;margin-top:6px">
                                                <span>{{ $employee->staff_id }} - {{ $employee->full_name }}</span>
                                                <button type="button" class="btn btn-primary" wire:click="linkReader({{ $employee->id }})">Link</button>
                                            </div>
                                        @endforeach
                                        @if ($candidates->isEmpty())
                                            <p class="form-hint">No employee found.</p>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Reader rows</span>
                <div class="ph-right">
                    <select class="form-input" wire:model.live="matchFilter">
                        <option value="">All rows</option>
                        @foreach ($matchStatuses as $status)
                            <option value="{{ $status }}">{{ str($status)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <table>
                <thead>
                    <tr><th>Staff ID</th><th>Name in report</th><th>Month</th><th>Read</th><th>Skipped</th><th>Visited</th><th>Match</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->reader_staff_id }}</td>
                            <td>{{ $row->employee?->full_name ?? $row->reader_name_raw }}</td>
                            <td>{{ $row->month->format('M Y') }}</td>
                            <td>{{ number_format($row->read_count) }}</td>
                            <td>{{ number_format($row->skipped_count) }}</td>
                            <td>{{ number_format($row->visited_count) }}</td>
                            <td><x-ui.status-pill domain="commercial" :status="$row->match_status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No rows for this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div style="padding:12px">{{ $rows->links() }}</div>
        </div>
    @else
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
                                    @if ($canResolve && ! $batch->isVoided())
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
                                                    @foreach ($districtOptions as $option)
                                                        <option value="{{ $option->id }}">{{ $option->district_name }}</option>
                                                    @endforeach
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
            </div>
        @endif

        @if ($bands->isNotEmpty())
            <div class="pg">
                <div class="pg-head"><span class="pg-title">Domestic consumption bands (category {{ $bands->first()->category_code }})</span></div>
                <table>
                    <thead><tr><th>Band ('000 litres)</th><th>Customers</th><th>Volume</th><th>Amount (GH¢)</th></tr></thead>
                    <tbody>
                        @foreach ($bands as $band)
                            <tr>
                                <td>{{ $band->band }}</td>
                                <td>{{ number_format($band->customers) }}</td>
                                <td>{{ number_format($band->volume, 2) }}</td>
                                <td>{{ number_format($band->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Routes</span>
                <div class="ph-right">
                    <input class="form-input" type="search" placeholder="Search route or district" wire:model.live.debounce.300ms="routeSearch">
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>District</th><th>Route</th><th>Volume</th><th>Billing</th><th>Payments</th><th>Closing balance</th><th>Billed</th><th>Unbilled</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $route)
                        <tr>
                            <td>
                                {{ $route->district?->district_name ?? $route->district_label_raw }}
                                @unless ($route->district_id)<span class="form-hint">not matched</span>@endunless
                            </td>
                            <td>{{ $route->route_code }}</td>
                            <td>{{ number_format((float) $route->volume_total, 2) }}</td>
                            <td>{{ number_format((float) $route->billing_for_period, 2) }}</td>
                            <td>{{ number_format((float) $route->total_payments, 2) }}</td>
                            <td>{{ number_format((float) $route->closing_balance, 2) }}</td>
                            <td>{{ number_format((int) $route->billed_total) }}</td>
                            <td>{{ number_format((int) $route->unbilled_total) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No routes for this search.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div style="padding:12px">{{ $rows->links() }}</div>
        </div>
    @endif
</div>
