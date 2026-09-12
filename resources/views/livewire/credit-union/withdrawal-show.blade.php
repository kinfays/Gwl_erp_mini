<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Withdrawal - {{ $withdrawal->member?->member_number }}</h2>
            <p>
                {{ $withdrawal->member?->full_name }} &middot;
                {{ str($withdrawal->status)->title() }}
                @if ($withdrawal->paid_at)
                    &middot; paid {{ $withdrawal->paid_at->format('d M Y') }}
                @endif
            </p>
        </div>
        <div class="ph-right">
            <a class="btn btn-secondary" href="{{ route('credit-union.withdrawals') }}">Back to Withdrawals</a>
            @if ($withdrawal->isPending() && $canDecide)
                <button type="button" class="btn btn-primary" wire:click="approve">Approve</button>
                <button type="button" class="btn btn-danger" wire:click="startReject">Reject</button>
            @endif
        </div>
    </div>

    @error('withdrawal') <p class="form-error" style="margin-top:10px">{{ $message }}</p> @enderror

    @if ($withdrawal->isPending() && $isOwnRequest)
        <p class="form-error" style="margin-top:10px">
            You raised this request, so another committee member must decide it.
        </p>
    @endif

    <div class="stats" style="margin-top:14px">
        <div class="stat">
            <div class="stat-lbl">Savings Requested</div>
            <div class="stat-val">{{ number_format($withdrawal->savings_amount, 2) }}</div>
            <div class="stat-sub">Balance {{ number_format($balances['savings'], 2) }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Shares Requested</div>
            <div class="stat-val">{{ number_format($withdrawal->shares_amount, 2) }}</div>
            <div class="stat-sub">Balance {{ number_format($balances['shares'], 2) }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Total</div>
            <div class="stat-val">{{ number_format($withdrawal->totalAmount(), 2) }}</div>
            <div class="stat-sub">GHS</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Status</div>
            <div class="stat-val">{{ str($withdrawal->status)->title() }}</div>
            <div class="stat-sub">
                {{ $withdrawal->payment_method ? str($withdrawal->payment_method)->replace('_', ' ')->title() : 'Not paid yet' }}
            </div>
        </div>
    </div>

    @if ($showReject)
        <div class="pg">
            <div class="pg-head"><span class="pg-title">Reject Withdrawal</span></div>
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
        <div class="pg-head"><span class="pg-title">Request Details</span></div>
        <table>
            <tbody>
                <tr><th>Reason</th><td>{{ $withdrawal->reason ?? '-' }}</td></tr>
                <tr>
                    <th>Requested</th>
                    <td>{{ optional($withdrawal->requested_at)->format('d M Y H:i') }} by {{ $withdrawal->requester?->full_name ?? '-' }}</td>
                </tr>
                @if ($withdrawal->decided_at)
                    <tr>
                        <th>{{ $withdrawal->status === 'rejected' ? 'Rejected' : 'Approved' }}</th>
                        <td>
                            {{ $withdrawal->decided_at->format('d M Y H:i') }} by {{ $withdrawal->decider?->full_name ?? '-' }}
                            @if ($withdrawal->rejection_reason)
                                <span class="form-hint">{{ $withdrawal->rejection_reason }}</span>
                            @endif
                        </td>
                    </tr>
                @endif
                @if ($withdrawal->paid_at)
                    <tr>
                        <th>Paid</th>
                        <td>
                            {{ $withdrawal->paid_at->format('d M Y H:i') }}
                            via {{ str($withdrawal->payment_method)->replace('_', ' ')->title() }}
                            {{ $withdrawal->payment_reference ? '('.$withdrawal->payment_reference.')' : '' }}
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    @if ($withdrawal->isApproved())
        <div class="pg">
            <div class="pg-head"><span class="pg-title">Pay Out</span></div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Payment Method</label>
                        <select class="form-input" wire:model="paymentForm.payment_method">
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method }}">{{ str($method)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </select>
                        @if ($withdrawal->member?->isAssociate())
                            <span class="form-hint">Associate members are paid in cash or by cheque.</span>
                        @endif
                        @error('paymentForm.payment_method') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Payment Reference</label>
                        <input class="form-input" wire:model.defer="paymentForm.payment_reference">
                        @error('paymentForm.payment_reference') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Paid On</label>
                        <input type="date" class="form-input" wire:model.defer="paymentForm.paid_at">
                        @error('paymentForm.paid_at') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="markPaid">Mark Paid &amp; Post to Ledger</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head"><span class="pg-title">Ledger Entries From This Payout</span></div>
        <table>
            <thead>
                <tr><th>Date</th><th>Account</th><th>Type</th><th>Source</th><th>Amount</th><th>Balance After</th></tr>
            </thead>
            <tbody>
                @forelse ($withdrawal->ledgerEntries as $entry)
                    <tr>
                        <td>{{ $entry->transaction_date->format('d M Y') }}</td>
                        <td>{{ str($entry->account_type)->title() }}</td>
                        <td>{{ str($entry->entry_type)->title() }}</td>
                        <td>{{ str($entry->source)->replace('_', ' ')->title() }}</td>
                        <td>{{ number_format($entry->signedAmount(), 2) }}</td>
                        <td>{{ number_format($entry->balance_after, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">Nothing posted yet - the ledger is debited when the payout is marked paid.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
