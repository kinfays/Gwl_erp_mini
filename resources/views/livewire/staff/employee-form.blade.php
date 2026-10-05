<div>
    <x-ui.page-header
        :title="$employee ? 'Edit Employee' : 'Add Employee'"
        :description="$employee ? 'Update staff profile and derived leave details.' : 'Create a new employee profile and auto-link the user account.'"
    >
        <x-slot:actions>
            <a href="{{ route('staff.index') }}" class="btn btn-secondary">
                <x-ui.icon name="arrow-left" />
                Back to Employees
            </a>
            <button wire:click="save" type="button" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <x-ui.icon name="check" />
                {{ $employee ? 'Save Changes' : 'Save Employee' }}
            </button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert tone="danger" title="Please fix the following errors:" role="alert" class="form-summary">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Employee Details" description="Fields marked * are required.">
            <div class="ui-stack">
                <div class="ui-form-grid">
                    <x-ui.input label="Staff ID" wire:model.defer="staff_id" required class="mono" autocomplete="off" />
                    <x-ui.input label="Full Name" wire:model.defer="full_name" required autocomplete="off" />
                    <x-ui.select label="Title" wire:model.defer="title" hint="Printed before the name on leave approval letters.">
                        <option value="">No title</option>
                        @foreach ($titles as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select label="Gender" wire:model.live="gender" required>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </x-ui.select>
                    <x-ui.input label="Date of Birth" type="date" wire:model.live="date_of_birth" required />
                </div>

                <div class="derived-grid" aria-live="polite">
                    <div class="derived-tile">
                        <span class="derived-label">Age</span>
                        <span class="derived-value">{{ $age ?? '—' }}@if ($age !== null) <small>years</small>@endif</span>
                        <span class="derived-hint">Auto-calculated from date of birth</span>
                    </div>
                    <div class="derived-tile">
                        <span class="derived-label">Retirement Date</span>
                        <span class="derived-value">{{ $retirementDate ?? '—' }}</span>
                        <span class="derived-hint">Auto-calculated at age {{ $retirementAge }}</span>
                    </div>
                </div>

                <div class="ui-form-grid">
                    <x-ui.input label="Date Joined" type="date" wire:model.defer="date_joined" />
                    <x-ui.select
                        label="Grade"
                        wire:model.live="grade"
                        :required="$gradeRequired"
                        :hint="$gradeCategory ? 'Category: '.$gradeCategory : ($gradeRequired ? null : 'Not graded yet: HR can set it here.')"
                    >
                        <option value="">Select grade</option>
                        @foreach ($gradeGroups as $group => $grades)
                            <optgroup label="{{ $group }}">
                                @foreach ($grades as $gradeName)
                                    <option value="{{ $gradeName }}">{{ $gradeName }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </x-ui.select>

                    @if (! $gradeCategory)
                        {{-- Staff recorded before grades existed keep their category until they are graded. --}}
                        <x-ui.select label="Category" wire:model.defer="category" required hint="Set automatically once a grade is chosen.">
                            @foreach (\App\Enums\StaffGrade::allCategories() as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </x-ui.select>
                    @endif

                    <x-form.combobox
                        label="Job Title"
                        required
                        model="job_title_id"
                        :options="$jobTitleOptions"
                        placeholder="Type to search job titles"
                    />

                    <x-form.combobox
                        label="Department"
                        required
                        model="department_id"
                        :options="$departmentOptions"
                        placeholder="Type to search departments"
                    />

                    <x-ui.input label="Unit" wire:model.defer="unit" placeholder="Optional" />

                    <x-form.combobox
                        label="District"
                        required
                        model="district_id"
                        :options="$districtOptions"
                        placeholder="Type to search districts"
                    />

                    <x-ui.input label="Region" id="f-region" :value="$selectedRegionName ?? 'Auto-filled from district'" readonly :error="false" hint="Set by the district you choose." />
                    <x-ui.input label="Present Appointment" type="date" wire:model.defer="present_appointment" />

                    <x-ui.input label="Email" type="email" wire:model.defer="email" required autocomplete="off" />
                </div>
            </div>
        </x-ui.card>

        <div class="ui-stack">
            <x-ui.card title="Derived Leave Details" description="Standard allocations for this profile.">
                <dl class="balance-list">
                    <div>
                        <dt>Annual Leave Entitlement <span class="ui-hint">Standard yearly allocation</span></dt>
                        <dd>31</dd>
                    </div>
                    <div>
                        <dt>Casual Leave <span class="ui-hint">Standard yearly allocation</span></dt>
                        <dd>5</dd>
                    </div>
                    <div>
                        <dt>Parental Days <span class="ui-hint">Updates live when gender changes</span></dt>
                        <dd>{{ $gender === 'Female' ? 93 : 7 }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Leave Balance Summary" description="Days left this year, by leave type.">
                @forelse ($leaveBalances as $balance)
                    @php
                        $percentage = $balance->entitle_days > 0
                            ? round(($balance->remaining_days / $balance->entitle_days) * 100)
                            : 0;
                    @endphp
                    <div class="ui-meter balance-meter">
                        <div class="ui-meter-head">
                            <span class="ui-meter-label">{{ $balance->leave_type }}</span>
                            <span class="ui-meter-value"><b>{{ $balance->remaining_days }}</b> <small>of {{ $balance->entitle_days }} days left</small></span>
                        </div>
                        <div class="ui-meter-track" aria-hidden="true">
                            <span style="width: {{ min(100, max(0, $percentage)) }}%"></span>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="calendar-days" title="No balances yet" description="No existing leave balance records for this employee yet." />
                @endforelse
            </x-ui.card>
        </div>
    </div>
</div>
