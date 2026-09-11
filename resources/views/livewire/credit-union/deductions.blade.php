<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Payroll Deductions</h2>
            <p>Import the monthly GWCL deduction file, match it to members, and post it to their ledgers.</p>
        </div>
        <div class="ph-right">
            <a class="btn btn-secondary" href="{{ route('credit-union.deductions.template') }}">Download Template</a>
            <button type="button" class="btn btn-primary" wire:click="openUpload">Import Batch</button>
        </div>
    </div>

    @if ($showUpload)
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">Import Deduction Batch</span>
                <div class="ph-right">
                    <button type="button" class="btn btn-secondary" wire:click="closeUpload">Cancel</button>
                </div>
            </div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Period Month</label>
                        <input type="date" class="form-input" wire:model.defer="form.period_month">
                        <span class="form-hint">Stored as the first day of the month.</span>
                        @error('form.period_month') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Amount Received (GHS)</label>
                        <input type="number" step="0.01" class="form-input" wire:model.defer="form.amount_received">
                        <span class="form-hint">The bulk remittance total this file is reconciled against.</span>
                        @error('form.amount_received') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Bank Reference</label>
                        <input class="form-input" wire:model.defer="form.bank_reference" placeholder="e.g. GCB 659863">
                        @error('form.bank_reference') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Banked Date</label>
                        <input type="date" class="form-input" wire:model.defer="form.banked_date">
                        @error('form.banked_date') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Deduction File</label>
                        <input type="file" class="form-input" wire:model="file">
                        <span class="form-hint">Expected columns: {{ implode(', ', $expectedHeadings) }}</span>
                        @error('file') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Notes</label>
                        <textarea rows="2" class="form-input" wire:model.defer="form.notes"></textarea>
                        @error('form.notes') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-secondary" wire:click="previewFile" wire:loading.attr="disabled">Preview File</button>
                        <button type="button" class="btn btn-primary" wire:click="runImport" wire:loading.attr="disabled">Create Batch</button>
                    </div>
                </div>

                @if ($preview)
                    <div style="display:flex;gap:12px;font-size:10px;margin-top:4px;flex-wrap:wrap">
                        <span style="color:#3B6D11">OK {{ $preview['postable_count'] }} rows ready to post</span>
                        <span style="color:#A32D2D">! {{ $preview['error_count'] }} invalid rows</span>
                        <span style="color:#A36A2D">? {{ $preview['warning_count'] }} rows need resolution</span>
                        <span style="color:var(--color-text-secondary)">
                            {{ $preview['failure_percent'] }}% failure rate; max {{ $preview['max_failure_percent'] }}%
                        </span>
                        <span style="color:var(--color-text-secondary)">
                            Postable total GHS {{ number_format($preview['postable_total'], 2) }};
                            loan repayments captured GHS {{ number_format($preview['loan_repayment_total'], 2) }} (held for the loans phase)
                        </span>
                    </div>

                    @if ($preview['blocked'])
                        <p class="form-error" style="margin-top:8px">
                            This file is blocked: too many rows cannot be posted. Fix the file or register the missing members first.
                        </p>
                    @endif

                    @if ($preview['errors'] || $preview['warnings'])
                        <table style="margin-top:12px">
                            <thead><tr><th>Row</th><th>Issue</th></tr></thead>
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
            <span class="pg-title">Deduction Batches</span>
            <div class="ph-right">
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
                    <th>Period</th>
                    <th>Bank Reference</th>
                    <th>Lines</th>
                    <th>Received</th>
                    <th>Posted</th>
                    <th>Variance</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td>{{ $batch->period_month->format('M Y') }}</td>
                        <td>{{ $batch->bank_reference ?? '-' }}</td>
                        <td>{{ $batch->lines_count }}</td>
                        <td>{{ number_format($batch->amount_received, 2) }}</td>
                        <td>{{ number_format($batch->amount_posted, 2) }}</td>
                        <td>{{ $batch->hasBeenPosted() ? number_format($batch->variance(), 2) : '-' }}</td>
                        <td>{{ str($batch->status)->title() }}</td>
                        <td><a class="btn btn-secondary" href="{{ route('credit-union.deductions.show', $batch) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8">No deduction batches imported yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $batches->links() }}</div>
    </div>
</div>
