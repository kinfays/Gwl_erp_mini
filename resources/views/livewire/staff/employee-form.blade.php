<div class="two">
    <div>
        <div class="page-head" style="border:0;padding:0 0 12px;background:transparent">
            <div class="ph-left">
                <h2>{{ $employee ? 'Edit Employee' : 'Add Employee' }}</h2>
                <p>{{ $employee ? 'Update staff profile and derived leave details.' : 'Create a new employee profile and auto-link the user account.' }}</p>
            </div>

            <div class="ph-right">
                <a href="{{ route('staff.index') }}" class="btn">Back to Employees</a>
                <button wire:click="save" type="button" class="btn btn-primary">{{ $employee ? 'Save Changes' : 'Save Employee' }}</button>
            </div>
        </div>

        @if ($errors->any())
            <div class="erp-card" style="margin-bottom:14px;background:#fef2f2;border-color:#fecaca;color:#991b1b;">
                <div style="font-weight:600;margin-bottom:6px">Please fix the following errors:</div>
                <ul style="padding-left:16px;font-size:12px">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Employee Details</span>
            </div>

            <div style="padding:14px">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Staff ID</label>
                        <input type="text" wire:model.defer="staff_id" class="form-input">
                    </div>

                    <div class="form-field">
                        <label class="form-label">Full Name</label>
                        <input type="text" wire:model.defer="full_name" class="form-input">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Gender</label>
                        <select wire:model.live="gender" class="form-input">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" wire:model.live="date_of_birth" class="form-input">
                    </div>
                </div>

                <div class="day-counter">
                    <div>
                        <div class="day-label" style="margin-bottom:2px">Age</div>
                        <div style="font-size:10px;color:#185FA5">Auto-calculated from date of birth</div>
                    </div>
                    <div style="text-align:right">
                        <div class="day-num">{{ $age ?? '-' }}</div>
                        <div style="font-size:10px;color:#185FA5">years</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Date Joined</label>
                        <input type="date" wire:model.defer="date_joined" class="form-input">
                    </div>

                    <div class="form-field">
                        <label class="form-label">Category</label>
                        <select wire:model.defer="category" class="form-input">
                            <option value="Senior Staff">Senior Staff</option>
                            <option value="Junior Staff">Junior Staff</option>
                            <option value="Management">Management</option>
                            <option value="Senior Management">Senior Management</option>
                            <option value="Charwoman">Charwoman</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Search Job Title</label>
                        <input type="text" wire:model.live.debounce.300ms="jobTitleSearch" class="form-input" placeholder="Search job titles...">
                    </div>

                    <div class="form-field">
                        <label class="form-label">Job Title</label>
                        <select wire:model.defer="job_title_id" class="form-input">
                            <option value="">Select job title</option>
                            @foreach ($jobTitles as $jobTitle)
                                <option value="{{ $jobTitle->id }}">{{ $jobTitle->job_title_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Department</label>
                        <select wire:model.defer="department_id" class="form-input">
                            <option value="">Select department</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-field">
                        <label class="form-label">Unit</label>
                        <input type="text" wire:model.defer="unit" class="form-input" placeholder="Optional">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Search District</label>
                        <input type="text" wire:model.live.debounce.300ms="districtSearch" class="form-input" placeholder="Search districts...">
                    </div>

                    <div class="form-field">
                        <label class="form-label">District</label>
                        <select wire:model.live="district_id" class="form-input">
                            <option value="">Select district</option>
                            @foreach ($districts as $district)
                                <option value="{{ $district->id }}">{{ $district->district_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Region</label>
                        <input type="text" value="{{ $selectedRegionName ?? 'Auto-filled from district' }}" class="form-input" readonly>
                    </div>

                    <div class="form-field">
                        <label class="form-label">Present Appointment</label>
                        <input type="text" wire:model.defer="present_appointment" class="form-input" placeholder="Optional">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label">Email</label>
                        <input type="email" wire:model.defer="email" class="form-input">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Derived Leave Details</span>
            </div>

            <div style="padding:14px">
                <div class="day-counter">
                    <div>
                        <div class="day-label">Annual Leave Entitlement</div>
                        <div style="font-size:10px;color:#185FA5">Standard yearly allocation</div>
                    </div>
                    <div class="day-num">31</div>
                </div>

                <div class="day-counter" style="background:#f7fafc">
                    <div>
                        <div class="day-label" style="color:var(--color-text-primary)">Casual Leave</div>
                        <div style="font-size:10px;color:var(--color-text-secondary)">Standard yearly allocation</div>
                    </div>
                    <div class="day-num" style="color:var(--color-text-primary)">5</div>
                </div>

                <div class="day-counter" style="background:#eef6ff">
                    <div>
                        <div class="day-label">Parental Days</div>
                        <div style="font-size:10px;color:#185FA5">Updates live when gender changes</div>
                    </div>
                    <div class="day-num">{{ $gender === 'Female' ? 93 : 7 }}</div>
                </div>
            </div>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Leave Balance Summary</span>
            </div>

            <div>
                @forelse ($leaveBalances as $balance)
                    @php
                        $percentage = $balance->entitle_days > 0
                            ? round(($balance->remaining_days / $balance->entitle_days) * 100)
                            : 0;
                    @endphp
                    <div class="bal-row">
                        <div class="bal-label">{{ $balance->leave_type }}</div>
                        <div class="bal-track">
                            <div class="bal-fill" style="width:{{ $percentage }}%;background:#185FA5"></div>
                        </div>
                        <div class="bal-num">{{ $balance->remaining_days }}</div>
                    </div>
                @empty
                    <div style="padding:14px;font-size:11px;color:var(--color-text-secondary)">
                        No existing leave balance records for this employee yet.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
