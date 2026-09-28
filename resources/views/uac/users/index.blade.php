<x-uac-layout>
    <x-ui.page-header title="Users" description="Accounts, their roles and sign-in status.">
        <x-slot:actions>
            <button type="button" class="btn btn-primary" x-data x-on:click.prevent="$dispatch('create-user')">
                <x-ui.icon name="user-plus" />
                Add User
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

    <x-ui.card :padded="false">
        <form action="{{ route('uac.users') }}" method="GET" class="ui-toolbar">
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" name="search" value="{{ $search }}" placeholder="Search name, email, or staff ID..." aria-label="Search users" class="form-input ui-input has-icon">
            </div>

            <select name="role_id" class="form-input" aria-label="Role">
                <option value="">All Roles</option>
                @foreach ($roles as $role)
                    <option value="{{ $role->id }}" {{ $roleId == $role->id ? 'selected' : '' }}>
                        {{ $role->display_name }}
                    </option>
                @endforeach
            </select>

            <select name="status" class="form-input" aria-label="Status">
                <option value="">All Status</option>
                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>

            <select name="per_page" class="form-input" aria-label="Rows per page" onchange="this.form.submit()">
                @foreach ($perPageOptions as $option)
                    <option value="{{ $option }}" {{ (int) $perPage === $option ? 'selected' : '' }}>
                        {{ $option }} per page
                    </option>
                @endforeach
            </select>

            <button type="submit" class="btn">
                <x-ui.icon name="filter" />
                Filter
            </button>
        </form>

        <x-ui.table label="Users" pin-first>
            <x-slot:head>
                <tr>
                    <th>Full Name</th>
                    <th>Staff ID</th>
                    <th>Location</th>
                    <th>Roles</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th class="actions">Actions</th>
                </tr>
            </x-slot:head>

            @forelse ($users as $user)
                @php
                    $employee = $user->employee ?? $user->employeeByStaffId;
                    $visibleRoles = $user->visibleRoles();
                    $location = collect([$employee?->district?->district_name, $employee?->region?->region_name])->filter()->implode(' • ');
                @endphp
                <tr>
                    <td>
                        <span class="ui-person">
                            <x-ui.avatar :name="$user->full_name ?? $user->name" />
                            <span>
                                <span class="ui-person-name">{{ $user->full_name ?? $user->name }}</span>
                                <span class="ui-person-sub">{{ $user->email }}</span>
                            </span>
                        </span>
                    </td>
                    <td class="mono">{{ $user->staff_id ?: '—' }}</td>
                    <td class="cell-muted nowrap">{{ $location ?: 'Not assigned' }}</td>
                    <td>
                        <div class="ui-tags">
                            @forelse ($visibleRoles as $role)
                                <x-ui.badge tone="primary">{{ $role->display_name }}</x-ui.badge>
                            @empty
                                <x-ui.badge>No additional role</x-ui.badge>
                            @endforelse
                        </div>
                    </td>
                    <td><x-ui.status-pill domain="account" :status="$user->is_active ? 'Active' : 'Inactive'" /></td>
                    <td class="nowrap cell-muted">{{ $user->last_login_at?->format('d M Y, h:i A') ?: 'Never' }}</td>
                    <td class="actions">
                        <div class="row-actions">
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm btn-icon"
                                x-data
                                x-on:click.prevent="$dispatch('open-user-drawer', { id: {{ $user->id }} })"
                                title="View details"
                                aria-label="View details for {{ $user->full_name ?? $user->name }}"
                            >
                                <x-ui.icon name="eye" />
                            </button>

                            <button
                                type="button"
                                class="btn btn-ghost btn-sm btn-icon"
                                x-data
                                x-on:click.prevent="
                                    $dispatch('edit-user', {
                                        id: '{{ $user->id }}',
                                        name: @js($user->full_name ?? $user->name),
                                        email: @js($user->email),
                                        roles: @js($visibleRoles->pluck('id')->map(fn ($id) => (string) $id)->values())
                                    })
                                "
                                title="Edit user"
                                aria-label="Edit {{ $user->full_name ?? $user->name }}"
                            >
                                <x-ui.icon name="pencil" />
                            </button>

                            <form method="POST" action="{{ route('uac.users.toggle-status', $user) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" @class(['btn', 'btn-sm', 'btn-danger' => $user->is_active])>
                                    {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>

                            @if (empty($user->last_login_at))
                                <form method="POST" action="{{ route('uac.users.invite', $user) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-ghost">
                                        <x-ui.icon name="send" class="icon-sm" />
                                        Resend Invite
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="users" title="No users found." description="Try a different search or clear the filters." />
            @endforelse

            <x-slot:footer>
                <p class="pager-summary">
                    Showing {{ $users->firstItem() ?? 0 }} - {{ $users->lastItem() ?? 0 }} of {{ $users->total() }} users
                </p>

                @if ($users->hasPages())
                    <div>{{ $users->links() }}</div>
                @endif
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>

    {{-- Edit user --}}
    <div
        x-data="{
            open: false,
            userId: null,
            name: '',
            email: '',
            roles: [],
            roleOptions: @js($roles->map(fn ($role) => ['id' => (string) $role->id, 'name' => $role->display_name])->values()),
            removeRole(roleId) {
                this.roles = this.roles.filter((id) => id !== String(roleId));
            }
        }"
        x-on:edit-user.window="
            open = true;
            userId = $event.detail.id;
            name = $event.detail.name;
            email = $event.detail.email;
            roles = ($event.detail.roles || []).map((id) => String(id));
        "
        x-on:keydown.escape.window="open = false"
        x-show="open"
        x-cloak
        class="ui-modal-backdrop"
        x-on:click.self="open = false"
    >
        <div class="ui-modal ui-modal-md" role="dialog" aria-modal="true" aria-labelledby="edit-user-title" x-trap.inert.noscroll="open">
            <header class="ui-modal-head">
                <div class="ui-modal-titles">
                    <h2 id="edit-user-title" class="ui-modal-title">Edit User</h2>
                    <p class="ui-modal-desc">Change which roles this account has.</p>
                </div>
            </header>

            <form x-bind:action="`/uac/users/${userId}`" method="POST" class="ui-modal-form">
                @csrf
                @method('PATCH')

                <div class="ui-modal-body ui-stack">
                    <div class="ui-form-grid">
                        <x-ui.field label="Full Name" for="edit-user-name">
                            <input type="text" id="edit-user-name" name="full_name" x-model="name" required readonly aria-readonly="true" class="form-input ui-input">
                        </x-ui.field>

                        <x-ui.field label="Email" for="edit-user-email">
                            <input type="email" id="edit-user-email" name="email" x-model="email" required readonly aria-readonly="true" class="form-input ui-input">
                        </x-ui.field>
                    </div>

                    <fieldset class="role-picker">
                        <legend class="ui-label">Roles</legend>
                        <p class="ui-hint role-picker-count" x-text="`${roles.length} selected`" aria-live="polite"></p>

                        <div class="ui-tags" x-show="roles.length > 0">
                            <template x-for="role in roleOptions.filter((option) => roles.includes(String(option.id)))" :key="`edit-role-chip-${role.id}`">
                                <button type="button" class="ui-badge ui-badge-primary role-chip" x-on:click="removeRole(role.id)" x-bind:aria-label="`Remove ${role.name}`">
                                    <span x-text="role.name"></span>
                                    <x-ui.icon name="x" class="icon-sm" />
                                </button>
                            </template>
                        </div>

                        <div class="role-picker-list">
                            @foreach ($roles as $role)
                                <label class="role-picker-option">
                                    <input type="checkbox" name="roles[]" value="{{ $role->id }}" x-model="roles">
                                    <span>{{ $role->display_name }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="ui-hint">Uncheck all roles to remove all assigned roles.</p>
                    </fieldset>
                </div>

                <footer class="ui-modal-foot">
                    <button type="button" class="btn" x-on:click="open = false">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </footer>
            </form>

            <button type="button" class="icon-btn ui-modal-close" x-on:click="open = false" aria-label="Close dialog">
                <x-ui.icon name="x" />
            </button>
        </div>
    </div>

    {{-- Create user --}}
    <div
        x-data="{
            open: false,
            staffId: '',
            name: '',
            email: '',
            roles: [],
            roleOptions: @js($roles->map(fn ($role) => ['id' => (string) $role->id, 'name' => $role->display_name])->values()),
            removeRole(roleId) {
                this.roles = this.roles.filter((id) => id !== String(roleId));
            }
        }"
        x-on:create-user.window="
            open = true;
            staffId = '';
            name = '';
            email = '';
            roles = [];
        "
        x-on:keydown.escape.window="open = false"
        x-show="open"
        x-cloak
        class="ui-modal-backdrop"
        x-on:click.self="open = false"
    >
        <div class="ui-modal ui-modal-md" role="dialog" aria-modal="true" aria-labelledby="create-user-title" x-trap.inert.noscroll="open">
            <header class="ui-modal-head">
                <div class="ui-modal-titles">
                    <h2 id="create-user-title" class="ui-modal-title">Create New User</h2>
                    <p class="ui-modal-desc">Pick the employee, then choose their roles. They get an invite email to set a password.</p>
                </div>
            </header>

            <form action="{{ route('uac.users.store') }}" method="POST" class="ui-modal-form">
                @csrf

                <div class="ui-modal-body ui-stack">
                    <div x-data="employeePicker()" class="ui-stack">
                        <x-ui.field label="Employee (Search by Staff ID)" for="create-user-search">
                            <div class="erp-combobox">
                                <div class="ui-input-wrap">
                                    <x-ui.icon name="search" class="ui-input-icon" />
                                    <input
                                        type="text"
                                        id="create-user-search"
                                        x-model="query"
                                        x-on:input.debounce.300ms="search()"
                                        placeholder="Type staff ID or name..."
                                        autocomplete="off"
                                        role="combobox"
                                        aria-autocomplete="list"
                                        aria-controls="create-user-results"
                                        x-bind:aria-expanded="(results.length > 0).toString()"
                                        class="form-input ui-input has-icon"
                                    >
                                </div>

                                <div id="create-user-results" class="erp-combobox-menu" role="listbox" x-show="results.length > 0" x-cloak>
                                    <template x-for="emp in results" :key="emp.id">
                                        <button type="button" role="option" class="erp-combobox-option picker-option" x-on:click="select(emp)">
                                            <span x-text="emp.staff_id + ' — ' + emp.full_name"></span>
                                            <small x-text="emp.email"></small>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </x-ui.field>

                        {{-- Values submitted to backend --}}
                        <input type="hidden" name="employee_id" x-model="selectedEmployeeId">
                        <input type="hidden" name="staff_id" x-model="selectedStaffId">

                        <div class="ui-form-grid">
                            <x-ui.field label="Full Name" for="create-user-name">
                                <input type="text" id="create-user-name" x-model="selectedFullName" readonly class="form-input ui-input">
                            </x-ui.field>

                            <x-ui.field label="Email" for="create-user-email">
                                <input type="text" id="create-user-email" x-model="selectedEmail" readonly class="form-input ui-input">
                            </x-ui.field>
                        </div>
                    </div>

                    <fieldset class="role-picker">
                        <legend class="ui-label">Roles</legend>
                        <p class="ui-hint role-picker-count" x-text="`${roles.length} selected`" aria-live="polite"></p>

                        <div class="ui-tags" x-show="roles.length > 0">
                            <template x-for="role in roleOptions.filter((option) => roles.includes(String(option.id)))" :key="`create-role-chip-${role.id}`">
                                <button type="button" class="ui-badge ui-badge-primary role-chip" x-on:click="removeRole(role.id)" x-bind:aria-label="`Remove ${role.name}`">
                                    <span x-text="role.name"></span>
                                    <x-ui.icon name="x" class="icon-sm" />
                                </button>
                            </template>
                        </div>

                        <div class="role-picker-list">
                            @foreach ($roles as $role)
                                <label class="role-picker-option">
                                    <input type="checkbox" name="roles[]" value="{{ $role->id }}" x-model="roles">
                                    <span>{{ $role->display_name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>

                <footer class="ui-modal-foot">
                    <button type="button" class="btn" x-on:click="open = false">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </footer>
            </form>

            <button type="button" class="icon-btn ui-modal-close" x-on:click="open = false" aria-label="Close dialog">
                <x-ui.icon name="x" />
            </button>
        </div>
    </div>

    <script>
        function employeePicker() {
            return {
                query: '',
                results: [],
                selectedEmployeeId: '',
                selectedStaffId: '',
                selectedFullName: '',
                selectedEmail: '',

                async search() {
                    if (this.query.trim().length < 2) {
                        this.results = [];
                        return;
                    }

                    const url = `{{ route('uac.employees.search') }}?q=${encodeURIComponent(this.query)}`;
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    this.results = await res.json();
                },

                select(emp) {
                    this.selectedEmployeeId = emp.id;
                    this.selectedStaffId = emp.staff_id;
                    this.selectedFullName = emp.full_name;
                    this.selectedEmail = emp.email;
                    this.results = [];
                    this.query = emp.staff_id;
                }
            }
        }
    </script>

    <x-uac.user-drawer />
</x-uac-layout>
