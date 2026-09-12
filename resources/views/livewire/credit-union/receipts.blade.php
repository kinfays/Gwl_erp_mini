<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Manual Receipts</h2>
            <p>Cash and cheque taken over the counter - the only contribution route for associate members.</p>
        </div>
        <div class="ph-right">
            <button type="button" class="btn btn-primary" wire:click="openForm">Record Receipt</button>
        </div>
    </div>

    @error('receipts') <p class="form-error" style="margin-top:10px">{{ $message }}</p> @enderror

    @if ($showForm)
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">Record Receipt</span>
                <div class="ph-right">
                    <button type="button" class="btn btn-secondary" wire:click="closeForm">Cancel</button>
                </div>
            </div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Member</label>
                        <select class="form-input" wire:model="form.member_id">
                            <option value="">No member (form fee only)</option>
                            @foreach ($members as $member)
                                <option value="{{ $member->id }}">{{ $member->member_number }} - {{ $member->full_name }}</option>
                            @endforeach
                        </select>
                        @error('form.member_id') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Purpose</label>
                        <select class="form-input" wire:model.live="form.purpose">
                            @foreach ($purposes as $purpose)
                                <option value="{{ $purpose }}">{{ str($purpose)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </select>
                        @if ($form['purpose'] === 'membership_form_fee')
                            <span class="form-hint">An admin charge - recorded here, never posted to the member ledger.</span>
                        @elseif ($form['purpose'] === 'loan_repayment')
                            <span class="form-hint">Applied against the member's outstanding loan.</span>
                        @endif
                        @error('form.purpose') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Method</label>
                        <select class="form-input" wire:model.live="form.method">
                            @foreach ($methods as $method)
                                <option value="{{ $method }}">{{ str($method)->title() }}</option>
                            @endforeach
                        </select>
                        @error('form.method') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Amount (GHS)</label>
                        <input type="number" step="0.01" class="form-input" wire:model.defer="form.amount">
                        @error('form.amount') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Received On</label>
                        <input type="date" class="form-input" wire:model.defer="form.received_date">
                        @error('form.received_date') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Cheque No.</label>
                        <input class="form-input" wire:model.defer="form.cheque_no" @disabled($form['method'] !== 'cheque')>
                        @error('form.cheque_no') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Payer Name</label>
                        <input class="form-input" wire:model.defer="form.payer_name" placeholder="Defaults to the member's name">
                        @error('form.payer_name') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Banked</label>
                        <select class="form-input" wire:model="form.banked">
                            <option value="1">Yes - banked</option>
                            <option value="0">No - held</option>
                        </select>
                        <span class="form-hint">Shares and savings reach the ledger once the money is banked.</span>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Remarks</label>
                        <textarea rows="2" class="form-input" wire:model.defer="form.remarks"></textarea>
                        @error('form.remarks') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Record Receipt</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Receipt Register</span>
            <div class="ph-right">
                <input class="form-input" wire:model.live="search" placeholder="Member, payer or cheque no.">
                <select class="form-input" wire:model.live="purposeFilter">
                    <option value="">All purposes</option>
                    @foreach ($purposes as $purpose)
                        <option value="{{ $purpose }}">{{ str($purpose)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
                <select class="form-input" wire:model.live="bankedFilter">
                    <option value="">All</option>
                    <option value="banked">Banked</option>
                    <option value="held">Held</option>
                </select>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Member</th>
                    <th>Purpose</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Reference</th>
                    <th>Banked</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($receipts as $receipt)
                    <tr>
                        <td>{{ $receipt->received_date->format('d M Y') }}</td>
                        <td>
                            @if ($receipt->member)
                                {{ $receipt->member->member_number }} - {{ $receipt->member->full_name }}
                            @else
                                {{ $receipt->payer_name ?? '-' }}
                            @endif
                        </td>
                        <td>
                            {{ str($receipt->purpose)->replace('_', ' ')->title() }}
                            @if ($receipt->isMembershipFormFee())
                                <span class="form-hint">not ledgered</span>
                            @endif
                        </td>
                        <td>{{ str($receipt->method)->title() }}</td>
                        <td>{{ number_format($receipt->amount, 2) }}</td>
                        <td>{{ $receipt->cheque_no ?? '-' }}</td>
                        <td>{{ $receipt->banked ? optional($receipt->banked_date)->format('d M Y') ?? 'Yes' : 'Held' }}</td>
                        <td>
                            @unless ($receipt->banked)
                                <button type="button" class="btn btn-secondary" wire:click="markBanked({{ $receipt->id }})">Mark Banked</button>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">No receipts recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $receipts->links() }}</div>
    </div>
</div>
