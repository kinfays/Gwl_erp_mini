<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Loans</h2>
            <p>Raise loan applications, track guarantor cover, and follow repayments to completion.</p>
        </div>
        <div class="ph-right">
            <button type="button" class="btn btn-primary" wire:click="openForm">New Loan Application</button>
        </div>
    </div>

    @if ($showForm)
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">New Loan Application</span>
                <div class="ph-right">
                    <button type="button" class="btn btn-secondary" wire:click="closeForm">Cancel</button>
                </div>
            </div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Member</label>
                        <select class="form-input" wire:model.live="form.member_id">
                            <option value="">Select member</option>
                            @foreach ($members as $member)
                                <option value="{{ $member->id }}">{{ $member->member_number }} - {{ $member->full_name }}</option>
                            @endforeach
                        </select>
                        @error('form.member_id') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Purpose</label>
                        <input class="form-input" wire:model.defer="form.purpose">
                        @error('form.purpose') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Principal (GHS)</label>
                        <input type="number" step="0.01" class="form-input" wire:model.live.debounce.500ms="form.principal_amount">
                        @error('form.principal_amount') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Term (months)</label>
                        <input type="number" class="form-input" wire:model.live.debounce.500ms="form.term_months">
                        @error('form.term_months') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                @if ($termsPreview)
                    <div class="stats" style="margin-bottom:12px">
                        <div class="stat">
                            <div class="stat-lbl">Savings Balance</div>
                            <div class="stat-val">{{ number_format($termsPreview['savings_balance_at_application'], 2) }}</div>
                            <div class="stat-sub">No-guarantor limit {{ number_format($termsPreview['no_guarantor_limit'], 2) }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-lbl">Interest @ {{ number_format($termsPreview['interest_rate'], 2) }}%</div>
                            <div class="stat-val">{{ number_format($termsPreview['interest_amount'], 2) }}</div>
                            <div class="stat-sub">Straight line over {{ $form['term_months'] }} months</div>
                        </div>
                        <div class="stat">
                            <div class="stat-lbl">Total Repayable</div>
                            <div class="stat-val">{{ number_format($termsPreview['total_repayable'], 2) }}</div>
                            <div class="stat-sub">{{ number_format($termsPreview['monthly_installment_amount'], 2) }} per month</div>
                        </div>
                        <div class="stat">
                            <div class="stat-lbl">Guarantor Shortfall</div>
                            <div class="stat-val">{{ number_format($termsPreview['guarantor_shortfall'], 2) }}</div>
                            <div class="stat-sub">
                                {{ $termsPreview['requires_guarantor'] ? 'Guarantor cover required' : 'No guarantor needed' }}
                            </div>
                        </div>
                    </div>
                @endif

                <div class="form-row">
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Raise Loan</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Loan Register</span>
            <div class="ph-right">
                <input class="form-input" wire:model.live="search" placeholder="Loan no., member name or number">
                <select class="form-input" wire:model.live="statusFilter">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}">{{ str($status)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Loan No.</th>
                    <th>Member</th>
                    <th>Principal</th>
                    <th>Total Repayable</th>
                    <th>Monthly</th>
                    <th>Outstanding</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($loans as $loan)
                    <tr>
                        <td>{{ $loan->loan_number }}</td>
                        <td>{{ $loan->member?->member_number }} - {{ $loan->member?->full_name }}</td>
                        <td>{{ number_format($loan->principal_amount, 2) }}</td>
                        <td>{{ number_format($loan->total_repayable, 2) }}</td>
                        <td>{{ number_format($loan->monthly_installment_amount, 2) }}</td>
                        <td>{{ number_format($loan->outstanding_balance, 2) }}</td>
                        <td>{{ str($loan->status)->replace('_', ' ')->title() }}</td>
                        <td><a class="btn btn-secondary" href="{{ route('credit-union.loans.show', $loan) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8">No loans raised yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $loans->links() }}</div>
    </div>
</div>
