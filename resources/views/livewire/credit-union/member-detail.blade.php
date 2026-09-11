<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>{{ $member->full_name }}</h2>
            <p>
                {{ $member->member_number }} &middot; {{ str($member->member_type)->title() }} member &middot;
                {{ str($member->status)->title() }}
            </p>
        </div>
        <div class="ph-right">
            <a class="btn btn-secondary" href="{{ route('credit-union.members') }}">Back to Members</a>
            <a class="btn btn-primary" href="{{ route('credit-union.members.statement.pdf', $member) }}">Download Statement</a>
        </div>
    </div>

    <div class="stats" style="margin-top:14px">
        <div class="stat">
            <div class="stat-lbl">Shares Balance</div>
            <div class="stat-val">{{ number_format($balances['shares'], 2) }}</div>
            <div class="stat-sub">GHS</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Savings Balance</div>
            <div class="stat-val">{{ number_format($balances['savings'], 2) }}</div>
            <div class="stat-sub">GHS</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Total Holdings</div>
            <div class="stat-val">{{ number_format($balances['total'], 2) }}</div>
            <div class="stat-sub">GHS</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Membership Form Fee</div>
            <div class="stat-val">{{ number_format($member->membership_form_fee_amount, 2) }}</div>
            <div class="stat-sub">
                {{ optional($member->membership_form_fee_paid_at)->format('d M Y') ?? 'Not recorded as paid' }}
            </div>
        </div>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Member Details</span>
        </div>
        <table>
            <tbody>
                <tr><th>Staff ID</th><td>{{ $member->staff_id ?? '-' }}</td></tr>
                <tr><th>Phone</th><td>{{ $member->phone ?? '-' }}</td></tr>
                <tr><th>Address</th><td>{{ $member->address ?? '-' }}</td></tr>
                <tr><th>Legacy Account No.</th><td>{{ $member->legacy_account_number ?? '-' }}</td></tr>
                <tr><th>Registration Path</th><td>{{ str($member->application_source)->replace('_', ' ')->title() }}</td></tr>
                <tr><th>Registered On</th><td>{{ optional($member->registered_at)->format('d M Y') ?? '-' }}</td></tr>
                <tr>
                    <th>Initial Share</th>
                    <td>
                        {{ number_format($member->initial_share_amount, 2) }}
                        ({{ $member->initial_share_paid_at ? 'posted '.$member->initial_share_paid_at->format('d M Y') : 'not yet posted' }})
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Post Ledger Entry</span>
        </div>
        <div style="padding:14px">
            @if (! $member->isActive())
                <p class="form-error">Ledger entries can only be posted for active members.</p>
            @else
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Account</label>
                        <select class="form-input" wire:model="form.account_type">
                            @foreach ($accountTypes as $type)
                                <option value="{{ $type }}">{{ str($type)->title() }}</option>
                            @endforeach
                        </select>
                        @error('form.account_type') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Entry Type</label>
                        <select class="form-input" wire:model="form.entry_type">
                            @foreach ($entryTypes as $type)
                                <option value="{{ $type }}">{{ str($type)->title() }}</option>
                            @endforeach
                        </select>
                        @error('form.entry_type') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Amount (GHS)</label>
                        <input type="number" step="0.01" class="form-input" wire:model.defer="form.amount">
                        @error('form.amount') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Source</label>
                        <select class="form-input" wire:model="form.source">
                            @foreach ($sources as $source)
                                <option value="{{ $source }}">{{ str($source)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </select>
                        @if ($member->isAssociate())
                            <span class="form-hint">Associate members pay in cash or by cheque - they are never on GWL payroll.</span>
                        @endif
                        @error('form.source') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Transaction Date</label>
                        <input type="date" class="form-input" wire:model.defer="form.transaction_date">
                        @error('form.transaction_date') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Reference No.</label>
                        <input class="form-input" wire:model.defer="form.reference_no" placeholder="Receipt or cheque number">
                        @error('form.reference_no') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Remarks</label>
                        <textarea rows="2" class="form-input" wire:model.defer="form.remarks"></textarea>
                        @error('form.remarks') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="postEntry" wire:loading.attr="disabled">Post Entry</button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Ledger</span>
            <div class="ph-right">
                <select class="form-input" wire:model.live="accountFilter">
                    <option value="">All accounts</option>
                    @foreach ($accountTypes as $type)
                        <option value="{{ $type }}">{{ str($type)->title() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Account</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th>Amount</th>
                    <th>Balance</th>
                    <th>Reference</th>
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td>{{ $entry->transaction_date->format('d M Y') }}</td>
                        <td>{{ str($entry->account_type)->title() }}</td>
                        <td>{{ str($entry->entry_type)->title() }}</td>
                        <td>{{ str($entry->source)->replace('_', ' ')->title() }}</td>
                        <td>{{ number_format($entry->signedAmount(), 2) }}</td>
                        <td>{{ number_format($entry->balance_after, 2) }}</td>
                        <td>{{ $entry->reference_no ?? '-' }}</td>
                        <td>{{ $entry->recorder?->full_name ?? 'System' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8">No ledger entries yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $entries->links() }}</div>
    </div>
</div>
