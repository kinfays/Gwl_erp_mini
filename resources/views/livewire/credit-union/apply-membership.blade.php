<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Credit Union Membership</h2>
            <p>Apply to join the staff credit union. Applications are reviewed by the committee.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="pg" style="margin-top:14px">
            <div style="padding:14px">{{ session('status') }}</div>
        </div>
    @endif

    @if ($membership)
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">Your Membership</span>
            </div>
            <div style="padding:14px">
                <div class="stats">
                    <div class="stat">
                        <div class="stat-lbl">Member Number</div>
                        <div class="stat-val">{{ $membership->member_number }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-lbl">Status</div>
                        <div class="stat-val">{{ str($membership->status)->title() }}</div>
                        <div class="stat-sub">{{ str($membership->application_source)->replace('_', ' ')->title() }}</div>
                    </div>
                    <div class="stat">
                        <div class="stat-lbl">Registered</div>
                        <div class="stat-val">{{ optional($membership->registered_at)->format('d M Y') ?? '-' }}</div>
                    </div>
                </div>

                @if ($membership->isPending())
                    <p style="margin-top:12px">Your application is awaiting a decision from the credit union committee.</p>
                @elseif ($membership->isActive())
                    <p style="margin-top:12px">Your membership is active. Statements are available from the credit union office.</p>
                @else
                    <p style="margin-top:12px">{{ $membership->exit_reason ?: 'This membership is not currently active.' }}</p>
                @endif
            </div>
        </div>
    @else
        <div class="pg" style="margin-top:14px">
            <div class="pg-head">
                <span class="pg-title">Apply for Membership</span>
            </div>
            <div style="padding:14px">
                <p style="margin-bottom:12px">
                    A one-off membership form fee of GHS {{ number_format($formFee, 2) }} applies, plus a one-time
                    initial share purchase of GHS {{ number_format($initialShare, 2) }} credited to your shares
                    account once your application is approved.
                </p>

                @if (! $employee)
                    <p class="form-error">Your staff record could not be found. Contact HR before applying.</p>
                @else
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Staff ID</label>
                            <input class="form-input" value="{{ $employee->staff_id }}" disabled>
                        </div>
                        <div class="form-field">
                            <label class="form-label">Full Name</label>
                            <input class="form-input" value="{{ $employee->full_name }}" disabled>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label">Phone</label>
                            <input class="form-input" wire:model.defer="form.phone" placeholder="Contact number">
                            @error('form.phone') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="form-field">
                            <label class="form-label">Address</label>
                            <input class="form-input" wire:model.defer="form.address" placeholder="Residential address">
                            @error('form.address') <span class="form-error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-field" style="justify-content:end">
                            <button type="button" class="btn btn-primary" wire:click="submit" wire:loading.attr="disabled">
                                Submit Application
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
