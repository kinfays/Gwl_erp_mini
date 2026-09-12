<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Interest Distribution</h2>
            <p>Share the year's loan interest income back to members, pro-rata by their shares and savings.</p>
        </div>
        <div class="ph-right">
            <button type="button" class="btn btn-primary" wire:click="openForm">Compute Distribution</button>
        </div>
    </div>

    @if ($showForm)
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">Compute Distribution</span>
                <div class="ph-right">
                    <button type="button" class="btn btn-secondary" wire:click="closeForm">Cancel</button>
                </div>
            </div>
            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Period Label</label>
                        <input class="form-input" wire:model.defer="form.period_label" placeholder="e.g. 2025/2026">
                        @error('form.period_label') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Period End Date</label>
                        <input type="date" class="form-input" wire:model.defer="form.period_end_date">
                        <span class="form-hint">Member balances are snapshotted as at this date.</span>
                        @error('form.period_end_date') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Period Start Date</label>
                        <input type="date" class="form-input" wire:model.defer="form.period_start_date">
                        <span class="form-hint">Optional - defaults to one year before the end date. Loans disbursed in this window make up the pool.</span>
                        @error('form.period_start_date') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="compute" wire:loading.attr="disabled">Compute</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Distribution Runs</span>
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
                    <th>Year End</th>
                    <th>Interest Pool</th>
                    <th>Members</th>
                    <th>Credited To</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($distributions as $distribution)
                    <tr>
                        <td>{{ $distribution->period_label }}</td>
                        <td>{{ $distribution->period_end_date->format('d M Y') }}</td>
                        <td>{{ number_format($distribution->total_interest_pool, 2) }}</td>
                        <td>{{ $distribution->lines_count }}</td>
                        <td>{{ str($distribution->credit_account_type)->title() }}</td>
                        <td>{{ str($distribution->status)->title() }}</td>
                        <td><a class="btn btn-secondary" href="{{ route('credit-union.interest-distributions.show', $distribution) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7">No distribution runs yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $distributions->links() }}</div>
    </div>
</div>
