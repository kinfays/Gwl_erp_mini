<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Interest Distribution {{ $distribution->period_label }}</h2>
            <p>
                Year end {{ $distribution->period_end_date->format('d M Y') }} &middot;
                {{ str($distribution->status)->title() }}
                @if ($distribution->posted_at)
                    &middot; posted {{ $distribution->posted_at->format('d M Y H:i') }}
                @endif
            </p>
        </div>
        <div class="ph-right">
            <a class="btn btn-secondary" href="{{ route('credit-union.interest-distributions') }}">Back to Runs</a>
            @if ($distribution->isComputed() && $canApprove)
                <button type="button" class="btn btn-primary" wire:click="approve">Approve</button>
            @endif
            @if ($distribution->isApproved() && $canApprove)
                <button type="button" class="btn btn-primary" wire:click="post">Post to Ledgers</button>
            @endif
        </div>
    </div>

    @error('distribution') <p class="form-error" style="margin-top:10px">{{ $message }}</p> @enderror

    <div class="stats" style="margin-top:14px">
        <div class="stat">
            <div class="stat-lbl">Interest Pool</div>
            <div class="stat-val">{{ number_format($distribution->total_interest_pool, 2) }}</div>
            <div class="stat-sub">
                Loans disbursed
                {{ optional($distribution->period_start_date)->format('d M Y') ?? '-' }}
                to {{ $distribution->period_end_date->format('d M Y') }}
            </div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Allocated To Members</div>
            <div class="stat-val">{{ number_format($lineTotal, 2) }}</div>
            <div class="stat-sub">
                {{ abs($lineTotal - (float) $distribution->total_interest_pool) < 0.005 ? 'Balances exactly' : 'Does not balance' }}
            </div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Member Lines</div>
            <div class="stat-val">{{ $lines->total() }}</div>
            <div class="stat-sub">Active members holding assets</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Credited To</div>
            <div class="stat-val">{{ str($distribution->credit_account_type)->title() }}</div>
            <div class="stat-sub">{{ $distribution->isPosted() ? 'Posted' : 'Not posted yet' }}</div>
        </div>
    </div>

    <div class="pg">
        <div class="pg-head"><span class="pg-title">Run Details</span></div>
        <table>
            <tbody>
                <tr>
                    <th>Computed</th>
                    <td>{{ optional($distribution->computed_at)->format('d M Y H:i') ?? '-' }} by {{ $distribution->computer?->full_name ?? '-' }}</td>
                </tr>
                <tr>
                    <th>Approved</th>
                    <td>
                        @if ($distribution->approved_at)
                            {{ $distribution->approved_at->format('d M Y H:i') }} by {{ $distribution->approver?->full_name ?? '-' }}
                        @else
                            Not approved yet
                        @endif
                    </td>
                </tr>
                <tr>
                    <th>Posted</th>
                    <td>
                        @if ($distribution->posted_at)
                            {{ $distribution->posted_at->format('d M Y H:i') }} by {{ $distribution->poster?->full_name ?? '-' }}
                        @else
                            Not posted yet
                        @endif
                    </td>
                </tr>
                <tr><th>Notes</th><td>{{ $distribution->notes ?? '-' }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="pg">
        <div class="pg-head"><span class="pg-title">Member Allocations</span></div>
        <table>
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Holdings at Year End</th>
                    <th>Share of Pool</th>
                    <th>Amount</th>
                    <th>Posted</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    <tr>
                        <td>{{ $line->member?->member_number }} - {{ $line->member?->full_name }}</td>
                        <td>{{ number_format($line->asset_balance_at_computation, 2) }}</td>
                        <td>{{ number_format($line->share_of_pool_percent, 4) }}%</td>
                        <td>{{ number_format($line->amount, 2) }}</td>
                        <td>
                            @if ($line->isPosted())
                                {{ optional($line->ledgerEntry)->transaction_date?->format('d M Y') ?? 'Yes' }}
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">No member allocations on this run.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $lines->links() }}</div>
    </div>
</div>
