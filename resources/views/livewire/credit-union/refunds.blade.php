<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Refunds</h2>
            <p>Corrections for wrongly-deducted amounts, credited straight back to the member's ledger.</p>
        </div>
        <div class="ph-right">
            <button type="button" class="btn btn-primary" wire:click="openForm">Record Refund</button>
        </div>
    </div>

    @if ($showForm)
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">Record Refund</span>
                <div class="ph-right">
                    <button type="button" class="btn btn-secondary" wire:click="closeForm">Cancel</button>
                </div>
            </div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Member</label>
                        <select class="form-input" wire:model="form.member_id">
                            <option value="">Select member</option>
                            @foreach ($members as $member)
                                <option value="{{ $member->id }}">{{ $member->member_number }} - {{ $member->full_name }}</option>
                            @endforeach
                        </select>
                        @error('form.member_id') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Credit To</label>
                        <select class="form-input" wire:model="form.account_type">
                            @foreach ($accountTypes as $accountType)
                                <option value="{{ $accountType }}">{{ str($accountType)->title() }}</option>
                            @endforeach
                        </select>
                        <span class="form-hint">Which account the wrongly-deducted amount goes back into.</span>
                        @error('form.account_type') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Amount (GHS)</label>
                        <input type="number" step="0.01" class="form-input" wire:model.defer="form.amount">
                        @error('form.amount') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Refunded On</label>
                        <input type="date" class="form-input" wire:model.defer="form.refunded_at">
                        @error('form.refunded_at') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Reason</label>
                        <input class="form-input" wire:model.defer="form.reason" placeholder="e.g. July deduction taken twice">
                        @error('form.reason') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Record Refund</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Refund Register</span>
            <div class="ph-right">
                <input class="form-input" wire:model.live="search" placeholder="Member or reason">
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Member</th>
                    <th>Account</th>
                    <th>Amount</th>
                    <th>Reason</th>
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($refunds as $refund)
                    <tr>
                        <td>{{ $refund->refunded_at->format('d M Y') }}</td>
                        <td>{{ $refund->member?->member_number }} - {{ $refund->member?->full_name }}</td>
                        <td>{{ str($refund->account_type)->title() }}</td>
                        <td>{{ number_format($refund->amount, 2) }}</td>
                        <td>{{ $refund->reason }}</td>
                        <td>{{ $refund->recorder?->full_name ?? 'System' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">No refunds recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $refunds->links() }}</div>
    </div>
</div>
