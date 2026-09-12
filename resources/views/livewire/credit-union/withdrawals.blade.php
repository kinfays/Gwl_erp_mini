<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Withdrawals</h2>
            <p>Raise savings and shares withdrawal requests, and follow them through approval to payout.</p>
        </div>
        <div class="ph-right">
            <button type="button" class="btn btn-primary" wire:click="openForm">New Withdrawal Request</button>
        </div>
    </div>

    @if ($showForm)
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">New Withdrawal Request</span>
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
                        <label class="form-label">Reason</label>
                        <input class="form-input" wire:model.defer="form.reason">
                        @error('form.reason') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                @if ($selectedBalances)
                    <div class="stats" style="margin-bottom:12px">
                        <div class="stat">
                            <div class="stat-lbl">Savings Balance</div>
                            <div class="stat-val">{{ number_format($selectedBalances['savings'], 2) }}</div>
                            <div class="stat-sub">Maximum withdrawable from savings</div>
                        </div>
                        <div class="stat">
                            <div class="stat-lbl">Shares Balance</div>
                            <div class="stat-val">{{ number_format($selectedBalances['shares'], 2) }}</div>
                            <div class="stat-sub">Maximum withdrawable from shares</div>
                        </div>
                        <div class="stat">
                            <div class="stat-lbl">Total Holdings</div>
                            <div class="stat-val">{{ number_format($selectedBalances['total'], 2) }}</div>
                            <div class="stat-sub">GHS</div>
                        </div>
                    </div>
                @endif

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Savings Amount (GHS)</label>
                        <input type="number" step="0.01" class="form-input" wire:model.defer="form.savings_amount">
                        @error('form.savings_amount') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Shares Amount (GHS)</label>
                        <input type="number" step="0.01" class="form-input" wire:model.defer="form.shares_amount">
                        @error('form.shares_amount') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Raise Request</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Withdrawal Requests</span>
            <div class="ph-right">
                <input class="form-input" wire:model.live="search" placeholder="Member name or number">
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
                    <th>Member</th>
                    <th>Savings</th>
                    <th>Shares</th>
                    <th>Total</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($withdrawals as $withdrawal)
                    <tr>
                        <td>{{ $withdrawal->member?->member_number }} - {{ $withdrawal->member?->full_name }}</td>
                        <td>{{ number_format($withdrawal->savings_amount, 2) }}</td>
                        <td>{{ number_format($withdrawal->shares_amount, 2) }}</td>
                        <td>{{ number_format($withdrawal->totalAmount(), 2) }}</td>
                        <td>{{ optional($withdrawal->requested_at)->format('d M Y') ?? '-' }}</td>
                        <td>{{ str($withdrawal->status)->title() }}</td>
                        <td><a class="btn btn-secondary" href="{{ route('credit-union.withdrawals.show', $withdrawal) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7">No withdrawal requests yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $withdrawals->links() }}</div>
    </div>
</div>
