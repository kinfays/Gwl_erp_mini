<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Loan {{ $loan->loan_number }}</h2>
            <p>
                {{ $loan->member?->member_number }} - {{ $loan->member?->full_name }} &middot;
                {{ str($loan->status)->replace('_', ' ')->title() }}
            </p>
        </div>
        <div class="ph-right">
            <a class="btn btn-secondary" href="{{ route('credit-union.loans') }}">Back to Loans</a>
            @if ($loan->isDecidable() && $canDecide)
                <button type="button" class="btn btn-primary" wire:click="approve">Approve</button>
                <button type="button" class="btn btn-danger" wire:click="startReject">Reject</button>
            @endif
        </div>
    </div>

    @error('loan') <p class="form-error" style="margin-top:10px">{{ $message }}</p> @enderror
    @error('guarantors') <p class="form-error" style="margin-top:10px">{{ $message }}</p> @enderror

    @if ($loan->isDecidable() && $isOwnApplication)
        <p class="form-error" style="margin-top:10px">
            You raised this application, so another committee member must decide it.
        </p>
    @endif

    <div class="stats" style="margin-top:14px">
        <div class="stat">
            <div class="stat-lbl">Principal</div>
            <div class="stat-val">{{ number_format($loan->principal_amount, 2) }}</div>
            <div class="stat-sub">GHS</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Interest @ {{ number_format($loan->interest_rate, 2) }}%</div>
            <div class="stat-val">{{ number_format($loan->interest_amount, 2) }}</div>
            <div class="stat-sub">Straight line, {{ $loan->term_months }} months</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Total Repayable</div>
            <div class="stat-val">{{ number_format($loan->total_repayable, 2) }}</div>
            <div class="stat-sub">{{ number_format($loan->monthly_installment_amount, 2) }} per month</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Outstanding</div>
            <div class="stat-val">{{ number_format($loan->outstanding_balance, 2) }}</div>
            <div class="stat-sub">{{ $loan->repayments->count() }} repayment(s)</div>
        </div>
    </div>

    @if ($showReject)
        <div class="pg">
            <div class="pg-head"><span class="pg-title">Reject Loan</span></div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Reason</label>
                        <textarea rows="2" class="form-input" wire:model.defer="rejectionReason"></textarea>
                        @error('rejectionReason') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-danger" wire:click="reject">Confirm Rejection</button>
                        <button type="button" class="btn btn-secondary" wire:click="cancelReject">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head"><span class="pg-title">Eligibility</span></div>
        <table>
            <tbody>
                <tr><th>Savings at application</th><td>{{ number_format($loan->savings_balance_at_application, 2) }}</td></tr>
                <tr>
                    <th>No-guarantor limit</th>
                    <td>
                        {{ number_format($loan->no_guarantor_limit, 2) }}
                        <span class="form-hint">savings x {{ config('gwl.credit_union_loan_multiple_without_guarantor') }}</span>
                    </td>
                </tr>
                <tr>
                    <th>Guarantor shortfall</th>
                    <td>
                        {{ number_format($loan->guarantor_shortfall, 2) }}
                        @if ($loan->requires_guarantor)
                            <span class="form-hint">
                                {{ number_format($acceptedTotal, 2) }} accepted;
                                {{ number_format($shortfallRemaining, 2) }} still to cover
                            </span>
                        @else
                            <span class="form-hint">Within the no-guarantor limit.</span>
                        @endif
                    </td>
                </tr>
                <tr><th>Purpose</th><td>{{ $loan->purpose ?? '-' }}</td></tr>
                <tr><th>Applied</th><td>{{ optional($loan->applied_at)->format('d M Y H:i') }} by {{ $loan->applicant?->full_name ?? '-' }}</td></tr>
                @if ($loan->approved_at)
                    <tr>
                        <th>{{ $loan->status === 'rejected' ? 'Rejected' : 'Approved' }}</th>
                        <td>
                            {{ $loan->approved_at->format('d M Y H:i') }} by {{ $loan->approver?->full_name ?? '-' }}
                            @if ($loan->rejection_reason)
                                <span class="form-hint">{{ $loan->rejection_reason }}</span>
                            @endif
                        </td>
                    </tr>
                @endif
                @if ($loan->disbursed_at)
                    <tr><th>Disbursed</th><td>{{ $loan->disbursed_at->format('d M Y') }} {{ $loan->disbursement_reference ? '('.$loan->disbursement_reference.')' : '' }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    @if ($loan->requires_guarantor)
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Guarantors</span>
            </div>
            @if ($loan->isDecidable())
                <div style="padding:14px">
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Proposed Guarantor</label>
                            <select class="form-input" wire:model="guarantorForm.member_id">
                                <option value="">Select member</option>
                                @foreach ($guarantorCandidates as $candidate)
                                    <option value="{{ $candidate->id }}">{{ $candidate->member_number }} - {{ $candidate->full_name }}</option>
                                @endforeach
                            </select>
                            @error('guarantorForm.member_id') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Amount Guaranteed (GHS)</label>
                            <input type="number" step="0.01" class="form-input" wire:model.defer="guarantorForm.guaranteed_amount">
                            <span class="form-hint">Several guarantors may split the shortfall between them.</span>
                            @error('guarantorForm.guaranteed_amount') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-field" style="justify-content:end">
                            <button type="button" class="btn btn-primary" wire:click="addGuarantor">Request Guarantor</button>
                        </div>
                    </div>
                </div>
            @endif
            <table>
                <thead>
                    <tr>
                        <th>Guarantor</th>
                        <th>Amount</th>
                        <th>Their Holdings</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($loan->guarantors as $guarantor)
                        <tr>
                            <td>{{ $guarantor->guarantor?->member_number }} - {{ $guarantor->guarantor?->full_name }}</td>
                            <td>{{ number_format($guarantor->guaranteed_amount, 2) }}</td>
                            <td>{{ number_format($guarantor->guarantor_asset_balance_at_guarantee, 2) }}</td>
                            <td>{{ str($guarantor->status)->title() }}</td>
                            <td>
                                @if ($guarantor->disqualified_reason)
                                    {{ str($guarantor->disqualified_reason)->replace('_', ' ')->title() }}
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                @if ($guarantor->isPending() && $loan->isDecidable())
                                    <button type="button" class="btn btn-primary" wire:click="acceptGuarantor({{ $guarantor->id }})">Accept</button>
                                    <button type="button" class="btn btn-secondary" wire:click="declineGuarantor({{ $guarantor->id }})">Decline</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No guarantors proposed yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($loan->status === 'approved')
        <div class="pg">
            <div class="pg-head"><span class="pg-title">Disbursement</span></div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Disbursed On</label>
                        <input type="date" class="form-input" wire:model.defer="disbursementForm.disbursed_at">
                        @error('disbursementForm.disbursed_at') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Reference</label>
                        <input class="form-input" wire:model.defer="disbursementForm.disbursement_reference" placeholder="Cheque or transfer reference">
                        @error('disbursementForm.disbursement_reference') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="disburse">Mark Disbursed</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head"><span class="pg-title">Repayments</span></div>
        @if ($loan->isRepayable())
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Amount (GHS)</label>
                        <input type="number" step="0.01" class="form-input" wire:model.defer="repaymentForm.amount">
                        @error('repaymentForm.amount') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Source</label>
                        <select class="form-input" wire:model="repaymentForm.source">
                            @foreach ($repaymentSources as $source)
                                <option value="{{ $source }}">{{ str($source)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </select>
                        @if ($loan->member?->isAssociate())
                            <span class="form-hint">Associate members repay in cash or by cheque - they are never on GWL payroll.</span>
                        @endif
                        @error('repaymentForm.source') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Repayment Date</label>
                        <input type="date" class="form-input" wire:model.defer="repaymentForm.repayment_date">
                        @error('repaymentForm.repayment_date') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Reference</label>
                        <input class="form-input" wire:model.defer="repaymentForm.reference_no">
                        @error('repaymentForm.reference_no') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="recordRepayment">Record Repayment</button>
                    </div>
                </div>
            </div>
        @endif
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Source</th>
                    <th>Reference</th>
                    <th>Balance After</th>
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($loan->repayments->sortByDesc('repayment_date') as $repayment)
                    <tr>
                        <td>{{ $repayment->repayment_date->format('d M Y') }}</td>
                        <td>{{ number_format($repayment->amount, 2) }}</td>
                        <td>{{ str($repayment->source)->replace('_', ' ')->title() }}</td>
                        <td>{{ $repayment->reference_no ?? '-' }}</td>
                        <td>{{ number_format($repayment->balance_after, 2) }}</td>
                        <td>{{ $repayment->recorder?->full_name ?? 'System' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No repayments recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
