<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>All Employees</h2>
            <p>{{ $employees->total() }} employees in scope</p>
        </div>

        <div class="ph-right">
            @if ($canManage)
                <a href="{{ route('staff.import') }}" class="btn">Import Excel</a>
            @endif
            <a href="{{ $exportUrl }}" class="btn">Export</a>
            @if ($canManage)
                <a href="{{ route('staff.create') }}" class="btn btn-primary">+ Add Employee</a>
            @endif
        </div>
    </div>

    @if (session('success'))
        <div class="erp-card" style="margin-bottom:14px;background:#eaf7ef;border-color:#b8e0c5;color:#21633c;">
            {{ session('success') }}
        </div>
    @endif

    <div class="pg">
        <div class="pg-head">
            <div style="display:flex;gap:8px;align-items:center;flex:1;flex-wrap:wrap">
                <input type="text" wire:model.live="search" placeholder="Search name, staff ID, email..." class="form-input" style="min-width:220px">

                <select wire:model.live="department_id" class="form-input" style="min-width:150px">
                    <option value="">All departments</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                    @endforeach
                </select>

                <select wire:model.live="category" class="form-input" style="min-width:150px">
                    <option value="">All categories</option>
                    @foreach ($categories as $item)
                        <option value="{{ $item }}">{{ $item }}</option>
                    @endforeach
                </select>

                <select wire:model.live="location_type" class="form-input" style="min-width:140px">
                    <option value="">All locations</option>
                    <option value="HeadOffice">Head Office</option>
                    <option value="Region">Region</option>
                    <option value="District">District</option>
                </select>

                <select wire:model.live="status" class="form-input" style="min-width:130px">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="on_leave">On Leave</option>
                </select>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Staff ID</th>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Category</th>
                    <th>Location</th>
                    <th>Annual Leave Balance</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($employees as $employee)
                    @php
                        $statusLabel = ! $employee->is_active
                            ? 'Inactive'
                            : ((int) $employee->on_leave_count > 0 ? 'On Leave' : 'Active');
                        $statusClass = match ($statusLabel) {
                            'Active' => 'p-g',
                            'On Leave' => 'p-a',
                            default => 'p-d',
                        };
                        $annualBalance = $employee->leaveBalances->first()?->remaining_days;
                        if ($annualBalance === null && $employee->is_active) {
                            $annualBalance = $employee->annual_leave_days;
                        }
                        $avatarClass = ['av-b', 'av-t', 'av-c', 'av-p'][($loop->index % 4)];
                    @endphp
                    <tr>
                        <td style="color:#185FA5;font-size:10px">#{{ $employee->staff_id }}</td>
                        <td>
                            <div class="if">
                                <div class="av-sm {{ $avatarClass }}">{{ $employee->initials }}</div>
                                <div>
                                    <div>{{ $employee->full_name }}</div>
                                    <div style="font-size:10px;color:var(--color-text-secondary)">{{ $employee->email
                                     }}</div>
                                </div>
                            </div>
                        </td>
                         <td>
                            <div class="if">
                                <div>
                                    <div>{{ $employee->department?->department_name ?? '-' }}</div>
                                    <div style="font-size:10px;color:var(--color-text-secondary)">{{ $employee->jobTitle?->job_title_name ?? '-' }}</div>
                                </div>
                            </div>
                        </td>
                        <td><span class="pill p-b">{{ $employee->category }}</span></td>
                         
                        <td>
                            <div class="if">
                                <div>
                                    <div> {{ $employee->region?->region_name ?? '-' }} </div>
                                    <div style="font-size:10px;color:var(--color-text-secondary)">{{ $employee->district?->district_name ?? '-' }}</div>
                                </div>
                            </div>
                        </td>
                        
                        <td style="font-size:11px;color:{{ $annualBalance !== null ? '#3B6D11' : 'var(--color-text-secondary)' }}">
                            {{ $annualBalance !== null ? $annualBalance . ' days' : '-' }}
                        </td>
                        <td><span class="pill {{ $statusClass }}">{{ $statusLabel }}</span></td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                @if ($canManage)
                                    <a href="{{ route('staff.edit', $employee) }}" class="actn">Edit</a>

                                    <form method="POST" action="{{ route('staff.toggle-status', $employee) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="actn {{ $employee->is_active ? 'actn-r' : 'actn-g' }}">
                                            {{ $employee->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                @else
                                    <span class="actn">View only</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align:center;color:var(--color-text-secondary);padding:20px">No employees found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-top:0.5px solid var(--color-border-tertiary);gap:12px;flex-wrap:wrap">
            <div style="font-size:11px;color:var(--color-text-secondary)">
                Showing {{ $employees->firstItem() ?? 0 }} - {{ $employees->lastItem() ?? 0 }} of {{ $employees->total() }} employees
            </div>
            <div>
                {{ $employees->links() }}
            </div>
        </div>
    </div>
</div>
