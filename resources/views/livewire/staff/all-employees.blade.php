<div>
    <x-ui.page-header title="All Employees" :description="$employees->total().' employees in scope'">
        <x-slot:actions>
            @if ($canManage)
                <a href="{{ route('staff.import') }}" class="btn btn-secondary">
                    <x-ui.icon name="upload" />
                    Import Excel
                </a>
            @endif
            <a href="{{ $exportUrl }}" class="btn btn-secondary">
                <x-ui.icon name="download" />
                Export
            </a>
            @if ($canManage)
                <a href="{{ route('staff.create') }}" class="btn btn-primary">
                    <x-ui.icon name="user-plus" />
                    Add Employee
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    @if ($errors->any())
        <x-ui.alert tone="danger" role="alert">{{ $errors->first() }}</x-ui.alert>
    @endif

    <x-ui.card :padded="false">
        <div class="ui-toolbar" role="search" aria-label="Filter employees">
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" wire:model.live="search" placeholder="Search name, staff ID, email..." aria-label="Search employees" class="form-input ui-input has-icon">
            </div>

            <select wire:model.live="department_id" class="form-input" aria-label="Department">
                <option value="">All departments</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->department_name }}</option>
                @endforeach
            </select>

            <select wire:model.live="category" class="form-input" aria-label="Category">
                <option value="">All categories</option>
                @foreach ($categories as $item)
                    <option value="{{ $item }}">{{ $item }}</option>
                @endforeach
            </select>

            <select wire:model.live="grade" class="form-input" aria-label="Grade">
                <option value="">All grades</option>
                <option value="none">No grade set</option>
                @foreach ($gradeGroups as $group => $grades)
                    <optgroup label="{{ $group }}">
                        @foreach ($grades as $gradeName)
                            <option value="{{ $gradeName }}">{{ $gradeName }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>

            <select wire:model.live="location_type" class="form-input" aria-label="Location">
                <option value="">All locations</option>
                <option value="HeadOffice">Head Office</option>
                <option value="Region">Region</option>
                <option value="District">District</option>
            </select>

            <select wire:model.live="status" class="form-input" aria-label="Status">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Deactivated</option>
                <option value="on_leave">On Leave</option>
            </select>

            <select wire:model.live="perPage" class="form-input" aria-label="Rows per page">
                @foreach ($perPageOptions as $option)
                    <option value="{{ $option }}">{{ $option }} per page</option>
                @endforeach
            </select>
        </div>

        <div class="ui-loading-host">
            <x-ui.table label="Employees" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Name</th>
                        <th>Staff ID</th>
                        <th>Department</th>
                        <th>Category / Grade</th>
                        <th>Location</th>
                        <th class="num">Annual Leave Balance</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($employees as $employee)
                    @php
                        $statusLabel = ! $employee->is_active
                            ? 'Deactivated'
                            : ((int) $employee->on_leave_count > 0 ? 'On Leave' : 'Active');
                        $annualBalance = $employee->leaveBalances->first()?->remaining_days;
                        if ($annualBalance === null && $employee->is_active) {
                            $annualBalance = $employee->annual_leave_days;
                        }
                    @endphp
                    <tr wire:key="employee-{{ $employee->id }}">
                        <td>
                            <span class="ui-person">
                                <x-ui.avatar :name="$employee->full_name" />
                                <span>
                                    <span class="ui-person-name">{{ $employee->full_name }}</span>
                                    <span class="ui-person-sub">{{ $employee->email }}</span>
                                </span>
                            </span>
                        </td>
                        <td class="mono nowrap">#{{ $employee->staff_id }}</td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $employee->department?->department_name ?? '-' }}</span>
                                <span class="ui-person-sub">{{ $employee->jobTitle?->job_title_name ?? '-' }}</span>
                            </span>
                        </td>
                        <td class="nowrap">
                            <span class="ui-cell-stack">
                                <span><x-ui.badge>{{ $employee->category }}</x-ui.badge></span>
                                <span @class(['ui-person-sub', 'cell-muted' => ! $employee->grade])>{{ $employee->grade ?? 'No grade set' }}</span>
                            </span>
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $employee->region?->region_name ?? '-' }}</span>
                                <span class="ui-person-sub">{{ $employee->district?->district_name ?? '-' }}</span>
                            </span>
                        </td>
                        <td @class(['num', 'nowrap', 'cell-muted' => $annualBalance === null])>
                            {{ $annualBalance !== null ? $annualBalance . ' days' : '-' }}
                        </td>
                        <td>
                            <x-ui.status-pill domain="account" :status="$statusLabel" />
                            @if (! $employee->is_active && $employee->deactivation_reason_label)
                                <span class="ui-person-sub cell-note">Reason: {{ $employee->deactivation_reason_label }}</span>
                            @endif
                        </td>
                        <td class="actions">
                            <div class="row-actions">
                                @if ($employee->user)
                                    <button
                                        type="button"
                                        x-data
                                        x-on:click.prevent="$dispatch('open-user-drawer', { id: {{ $employee->user->id }} })"
                                        class="btn btn-ghost btn-sm btn-icon"
                                        title="View user details"
                                        aria-label="View user details for {{ $employee->full_name }}"
                                    >
                                        <x-ui.icon name="eye" />
                                    </button>
                                @endif
                                @if ($canManage)
                                    <a href="{{ route('staff.edit', $employee) }}" class="btn btn-ghost btn-sm btn-icon" title="Edit employee" aria-label="Edit {{ $employee->full_name }}">
                                        <x-ui.icon name="pencil" />
                                    </a>

                                    <form method="POST" action="{{ route('staff.toggle-status', $employee) }}" x-data>
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="deactivation_reason" x-ref="deactivationReason">
                                        <button
                                            type="submit"
                                            @class(['btn', 'btn-sm', 'btn-danger' => $employee->is_active])
                                            x-on:click.prevent="$dispatch('confirm-action', {
                                                title: @js($employee->is_active ? 'Deactivate employee?' : 'Activate employee?'),
                                                message: @js(($employee->is_active ? 'This will deactivate ' : 'This will activate ') . $employee->full_name . '.'),
                                                confirmLabel: @js($employee->is_active ? 'Deactivate' : 'Activate'),
                                                variant: @js($employee->is_active ? 'danger' : 'primary'),
                                                input: @js($employee->is_active ? [
                                                    'label' => 'Reason for deactivation',
                                                    'placeholder' => 'Select reason',
                                                    'required' => true,
                                                    'error' => 'Select why this employee is being deactivated.',
                                                    'options' => collect($deactivationReasons)->map(fn ($label, $value) => [
                                                        'value' => $value,
                                                        'label' => $label,
                                                    ])->values()->all(),
                                                ] : null),
                                                action: (reason) => {
                                                    if ($refs.deactivationReason) {
                                                        $refs.deactivationReason.value = reason || '';
                                                    }
                                                    $root.submit();
                                                }
                                            })"
                                        >
                                            {{ $employee->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                @else
                                    <span class="ui-hint">View only</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="8" icon="users" title="No employees found." description="Try a different search or clear the filters." />
                @endforelse

                <x-slot:footer>
                    <p class="pager-summary">
                        Showing {{ $employees->firstItem() ?? 0 }} - {{ $employees->lastItem() ?? 0 }} of {{ $employees->total() }} employees
                    </p>

                    @if ($employees->hasPages())
                        <div>{{ $employees->links() }}</div>
                    @endif
                </x-slot:footer>
            </x-ui.table>

            <div wire:loading.delay class="table-skeleton">
                <span class="skeleton-line"></span>
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
            </div>
        </div>
    </x-ui.card>
</div>
