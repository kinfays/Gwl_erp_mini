<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Credit Union Members</h2>
            <p>Register staff members from the directory, hand-enter associate members, and maintain member records.</p>
        </div>
        <div class="ph-right">
            <a class="btn btn-secondary" href="{{ route('credit-union.members.applications') }}">
                Applications @if ($pendingCount) ({{ $pendingCount }}) @endif
            </a>
            <button type="button" class="btn btn-secondary" wire:click="openAssociateForm">Add Associate</button>
            <button type="button" class="btn btn-primary" wire:click="openStaffForm">Register Staff Member</button>
        </div>
    </div>

    <div class="stats" style="margin-top:14px">
        <div class="stat">
            <div class="stat-lbl">All Members</div>
            <div class="stat-val">{{ number_format($totals['all']) }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Active Staff</div>
            <div class="stat-val">{{ number_format($totals['staff']) }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Active Associates</div>
            <div class="stat-val">{{ number_format($totals['associate']) }}</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Pending Applications</div>
            <div class="stat-val">{{ number_format($pendingCount) }}</div>
        </div>
    </div>

    @if ($showForm)
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">
                    @if ($formMode === 'staff') Register Staff Member
                    @elseif ($formMode === 'associate') Add Associate Member
                    @else Edit Member
                    @endif
                </span>
                <div class="ph-right">
                    <button type="button" class="btn btn-secondary" wire:click="closeForm">Cancel</button>
                </div>
            </div>
            <div style="padding:14px">
                @if ($formMode === 'staff')
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Search Employee Directory</label>
                            <input class="form-input" wire:model.live="employeeSearch" placeholder="Name or staff ID">
                        </div>
                        <div class="form-field">
                            <label class="form-label">Employee</label>
                            <select class="form-input" wire:model="form.employee_id">
                                <option value="">Select employee</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}">{{ $employee->staff_id }} - {{ $employee->full_name }}</option>
                                @endforeach
                            </select>
                            @error('form.employee_id') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <p style="margin-bottom:12px">The member number is taken from the employee's staff ID.</p>
                @else
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Full Name</label>
                            <input class="form-input" wire:model.defer="form.full_name">
                            @error('form.full_name') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Legacy Account Number</label>
                            <input class="form-input" wire:model.defer="form.legacy_account_number" placeholder="Old passbook number">
                            @error('form.legacy_account_number') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    @if ($formMode === 'associate')
                        <p style="margin-bottom:12px">Associate members are assigned the next available P-prefixed member number automatically.</p>
                    @endif
                @endif

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Phone</label>
                        <input class="form-input" wire:model.defer="form.phone">
                        @error('form.phone') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Address</label>
                        <input class="form-input" wire:model.defer="form.address">
                        @error('form.address') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                @if ($formMode !== 'edit')
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Registered On</label>
                            <input type="date" class="form-input" wire:model.defer="form.registered_at">
                            @error('form.registered_at') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Membership Form Fee (GHS)</label>
                            <input type="number" step="0.01" class="form-input" wire:model.defer="form.membership_form_fee_amount">
                            <span class="form-hint">Recorded on the member record only - it is an admin charge, not a member asset.</span>
                            @error('form.membership_form_fee_amount') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Form Fee Paid On</label>
                            <input type="date" class="form-input" wire:model.defer="form.membership_form_fee_paid_at">
                            @error('form.membership_form_fee_paid_at') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Initial Share (GHS)</label>
                            <input type="number" step="0.01" class="form-input" wire:model.defer="form.initial_share_amount">
                            <span class="form-hint">Posted automatically as a shares ledger entry once the member is active.</span>
                            @error('form.initial_share_amount') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @endif

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Monthly Savings Plan (GHS)</label>
                        <input type="number" step="0.01" class="form-input" wire:model.defer="form.default_monthly_savings_amount">
                        @error('form.default_monthly_savings_amount') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="form-field">
                        <label class="form-label">Monthly Shares Plan (GHS)</label>
                        <input type="number" step="0.01" class="form-input" wire:model.defer="form.default_monthly_shares_amount">
                        @error('form.default_monthly_shares_amount') <span class="form-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field" style="justify-content:end">
                        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                            {{ $formMode === 'edit' ? 'Save Changes' : 'Register Member' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Member Register</span>
            <div class="ph-right">
                <input class="form-input" wire:model.live="search" placeholder="Name, member no. or staff ID">
                <select class="form-input" wire:model.live="typeFilter">
                    <option value="">All types</option>
                    @foreach ($memberTypes as $type)
                        <option value="{{ $type }}">{{ str($type)->title() }}</option>
                    @endforeach
                </select>
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
                    <th>Member No.</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Form Fee</th>
                    <th>Registered</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($members as $member)
                    <tr>
                        <td>{{ $member->member_number }}</td>
                        <td>{{ $member->full_name }}</td>
                        <td>{{ str($member->member_type)->title() }}</td>
                        <td>{{ str($member->status)->title() }}</td>
                        <td>{{ number_format($member->membership_form_fee_amount, 2) }}</td>
                        <td>{{ optional($member->registered_at)->format('d M Y') ?? '-' }}</td>
                        <td>
                            <a class="btn btn-secondary" href="{{ route('credit-union.members.show', $member) }}">View</a>
                            <button type="button" class="btn btn-secondary" wire:click="openEdit({{ $member->id }})">Edit</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">No members registered yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:12px">{{ $members->links() }}</div>
    </div>
</div>
