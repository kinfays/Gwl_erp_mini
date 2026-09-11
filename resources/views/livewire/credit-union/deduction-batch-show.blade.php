<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Deduction Batch - {{ $batch->period_month->format('F Y') }}</h2>
            <p>
                {{ $batch->bank_reference ?? 'No bank reference' }} &middot;
                {{ str($batch->status)->title() }}
                @if ($batch->posted_at)
                    &middot; posted {{ $batch->posted_at->format('d M Y H:i') }}
                @endif
            </p>
        </div>
        <div class="ph-right">
            <a class="btn btn-secondary" href="{{ route('credit-union.deductions') }}">Back to Batches</a>
            @if ($batch->isPostable())
                <button type="button" class="btn btn-primary" wire:click="postBatch" wire:loading.attr="disabled">Post Batch</button>
            @endif
        </div>
    </div>

    @error('batch') <p class="form-error" style="margin-top:10px">{{ $message }}</p> @enderror
    @error('lines') <p class="form-error" style="margin-top:10px">{{ $message }}</p> @enderror

    <div class="stats" style="margin-top:14px">
        <div class="stat">
            <div class="stat-lbl">Amount Received</div>
            <div class="stat-val">{{ number_format($reconciliation['amount_received'], 2) }}</div>
            <div class="stat-sub">GHS from the bulk remittance</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Amount Posted</div>
            <div class="stat-val">{{ number_format($reconciliation['amount_posted'], 2) }}</div>
            <div class="stat-sub">Shares + savings only</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Variance</div>
            <div class="stat-val">{{ number_format($reconciliation['variance'], 2) }}</div>
            <div class="stat-sub">{{ $reconciliation['is_reconciled'] ? 'Reconciled' : 'Not reconciled' }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Unresolved Rows</div>
            <div class="stat-val">{{ number_format($reconciliation['unresolved_total'], 2) }}</div>
            <div class="stat-sub">
                {{ $reconciliation['unmatched_count'] }} unmatched,
                {{ $reconciliation['invalid_associate_count'] }} associate,
                {{ $reconciliation['skipped_count'] }} skipped
            </div>
        </div>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Reconciliation</span>
        </div>
        <table>
            <tbody>
                <tr><th>Lines in batch</th><td>{{ $reconciliation['line_count'] }} ({{ $reconciliation['matched_count'] }} matched)</td></tr>
                <tr><th>Shares in file</th><td>{{ number_format($reconciliation['shares_total'], 2) }}</td></tr>
                <tr><th>Savings in file</th><td>{{ number_format($reconciliation['savings_total'], 2) }}</td></tr>
                <tr>
                    <th>Loan repayments in file</th>
                    <td>
                        {{ number_format($reconciliation['loan_repayment_total'], 2) }}
                        <span class="form-hint">
                            {{ number_format($reconciliation['unposted_loan_repayment_total'], 2) }} captured but not yet posted -
                            loan accounts arrive in a later phase.
                        </span>
                    </td>
                </tr>
                <tr><th>Imported</th><td>{{ optional($batch->imported_at)->format('d M Y H:i') ?? '-' }}</td></tr>
            </tbody>
        </table>
    </div>

    @if ($editingLineId)
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Resolution Note</span>
            </div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Note</label>
                        <textarea rows="2" class="form-input" wire:model.defer="resolutionNotes"></textarea>
                        @error('resolutionNotes') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="saveResolution">Save Note</button>
                        <button type="button" class="btn btn-secondary" wire:click="cancelResolving">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Batch Lines</span>
            <div class="ph-right">
                <select class="form-input" wire:model.live="matchFilter">
                    <option value="">All lines</option>
                    @foreach ($matchStatuses as $status)
                        <option value="{{ $status }}">{{ str($status)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Staff ID</th>
                    <th>Name</th>
                    <th>Member</th>
                    <th>Shares</th>
                    <th>Savings</th>
                    <th>Loan Repayment</th>
                    <th>Match</th>
                    <th>Resolution</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    <tr>
                        <td>{{ $line->staff_id_raw }}</td>
                        <td>{{ $line->name_raw ?? '-' }}</td>
                        <td>
                            @if ($line->member)
                                <a href="{{ route('credit-union.members.show', $line->member) }}">{{ $line->member->member_number }}</a>
                            @else
                                -
                            @endif
                        </td>
                        <td>{{ number_format($line->shares_amount, 2) }}</td>
                        <td>{{ number_format($line->savings_amount, 2) }}</td>
                        <td>
                            {{ number_format($line->loan_repayment_amount, 2) }}
                            @if ($line->hasUnpostedLoanRepayment())
                                <span class="form-hint">unposted</span>
                            @endif
                        </td>
                        <td>{{ str($line->match_status)->replace('_', ' ')->title() }}</td>
                        <td>{{ $line->resolution_notes ?? '-' }}</td>
                        <td>
                            @unless ($line->isMatched())
                                <button type="button" class="btn btn-secondary" wire:click="startResolving({{ $line->id }})">Note</button>
                                @if ($batch->isPostable())
                                    <button type="button" class="btn btn-secondary" wire:click="rematchLine({{ $line->id }})">Rematch</button>
                                @endif
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9">No lines for this filter.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $lines->links() }}</div>
    </div>
</div>
