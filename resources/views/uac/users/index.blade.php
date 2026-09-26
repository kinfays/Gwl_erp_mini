<x-uac-layout>

<div class="bg-white rounded-lg shadow-sm">
    <div class="p-6 border-b border-gray-200 flex flex-wrap items-center justify-between gap-4">
        <form action="{{ route('uac.users') }}" method="GET" class="flex flex-wrap items-center gap-3">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search name, email, or staff ID..." 
                   class="border rounded-sm px-3 py-2 text-sm w-64">

            <select name="role_id" class="border rounded-sm px-3 py-2 text-sm">
                <option value="">All Roles</option>
                @foreach($roles as $role)
                    <option value="{{ $role->id }}" {{ $roleId == $role->id ? 'selected' : '' }}>
                        {{ $role->display_name }}
                    </option>
                @endforeach
            </select>

            <select name="status" class="border rounded-sm px-3 py-2 text-sm">
                <option value="">All Status</option>
                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>

            <select name="per_page" class="border rounded-sm px-3 py-2 text-sm" onchange="this.form.submit()">
                @foreach ($perPageOptions as $option)
                    <option value="{{ $option }}" {{ (int) $perPage === $option ? 'selected' : '' }}>
                        {{ $option }} per page
                    </option>
                @endforeach
            </select>

            <button type="submit" class="bg-slate-800 text-white px-4 py-2 rounded-sm text-sm hover:bg-slate-700">
                Filter
            </button>
        </form>

        <button
            x-data
            x-on:click.prevent="$dispatch('create-user')"
            class="bg-[#185FA5] text-white px-4 py-2 rounded-sm text-sm hover:bg-[#185FA5]/90"
        >
            + Add User
        </button>
    </div>

@if ($errors->any())
    <div
        x-data="{ show: true }"
        x-init="setTimeout(() => show = false, 4000)"
        x-show="show"
        x-transition
        class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3"
    >
        <p class="text-sm font-semibold text-red-800 mb-2">
            Please fix the following errors:
        </p>

        <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="bg-white rounded-2xl shadow-xs overflow-hidden border border-slate-100">
    <table class="min-w-full">
        <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Full Name</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Staff ID</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Location</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Roles</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Last Login</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                @php
                    $employee = $user->employee ?? $user->employeeByStaffId;
                    $visibleRoles = $user->visibleRoles();
                    $location = collect([$employee?->district?->district_name, $employee?->region?->region_name])->filter()->implode(' • ');
                @endphp
                <tr class="border-b border-slate-100 hover:bg-slate-50 transition-colors duration-100">
                    <td class="px-4 py-3.5 text-sm text-slate-700">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $user->full_name ?? $user->name }}</p>
                            <p class="text-slate-500">{{ $user->email }}</p>
                        </div>
                    </td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">{{ $user->staff_id ?: '—' }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">{{ $location ?: 'Not assigned' }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">
                        <div class="flex flex-wrap gap-2">
                            @forelse ($visibleRoles as $role)
                                <span class="bg-blue-50 text-blue-700 border border-blue-200 px-2.5 py-0.5 rounded-full text-xs font-medium">{{ $role->display_name }}</span>
                            @empty
                                <span class="bg-slate-100 text-slate-600 border border-slate-200 px-2.5 py-0.5 rounded-full text-xs font-medium">No additional role</span>
                            @endforelse
                        </div>
                    </td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">
                        <span class="{{ $user->is_active ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' }} px-2.5 py-0.5 rounded-full text-xs font-medium">{{ $user->is_active ? 'Active' : 'Inactive' }}</span>
                    </td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">{{ $user->last_login_at?->format('d M Y, h:i A') ?: 'Never' }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">
                        <div class="flex gap-2 items-center">
                          <button
                    type="button"
                            x-data
                                x-on:click.prevent="$dispatch('open-user-drawer', { id: {{ $user->id }} })"
                                class="p-2 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-700 transition-all duration-150"
                                    title="View details"
>
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7
                 -1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
    </svg>
</button>

                            <button
                                x-data
                                x-on:click.prevent="
                                    $dispatch('edit-user', {
                                        id: '{{ $user->id }}',
                                        name: '{{ $user->full_name ?? $user->name }}',
                                        email: '{{ $user->email }}',
                                        roles: @js($visibleRoles->pluck('id')->map(fn ($id) => (string) $id)->values())
                                    })
                                "
                                class="p-2 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-700 transition-all"
                                title="Edit user"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5
                                           m-1.414-9.414a2 2 0 112.828 2.828
                                           L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </button>
                            <form method="POST" action="{{ route('uac.users.toggle-status', $user) }}">
                                @csrf
                                @method('PATCH')
                                <button class="text-xs underline">
                                    {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>

                            @if (empty($user->last_login_at))
                                <form method="POST" action="{{ route('uac.users.invite', $user) }}">
                                    @csrf
                                    <button type="submit"
                                        class="text-xs px-2 py-1 rounded-sm bg-slate-100 hover:bg-slate-200 text-slate-700">
                                        Resend Invite
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">No users found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-6 flex flex-wrap items-center justify-between gap-4">
    <div class="text-sm text-slate-500">
        Showing {{ $users->firstItem() ?? 0 }} - {{ $users->lastItem() ?? 0 }} of {{ $users->total() }} users
    </div>

    @if ($users->hasPages())
        <div>
            {{ $users->links() }}
        </div>
    @endif
</div>

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
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
>
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-lg p-6">
        <h2 class="text-lg font-semibold text-slate-800 mb-4">
            Edit User
        </h2>

        <form
            x-bind:action="`/uac/users/${userId}`"
            method="POST"
            class="space-y-4"
        >
            @csrf
            @method('PATCH')

            {{-- Full Name --}}
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Full Name
                </label>
                <input
                    type="text"
                    name="full_name"
                    x-model="name"
                    required
                    readonly
                    aria-readonly="true"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-slate-100 text-slate-600 cursor-not-allowed"
                />
            </div>

            {{-- Email --}}
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Email
                </label>
                <input
                    type="email"
                    name="email"
                    x-model="email"
                    required
                    readonly
                    aria-readonly="true"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-slate-100 text-slate-600 cursor-not-allowed"
                />
            </div>

            {{-- Roles --}}
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-sm font-medium text-slate-700">
                        Roles
                    </label>
                    <span class="text-xs text-slate-500" x-text="`${roles.length} selected`"></span>
                </div>

                <div class="mb-2 flex flex-wrap gap-2" x-show="roles.length > 0">
                    <template x-for="role in roleOptions.filter((option) => roles.includes(String(option.id)))" :key="`edit-role-chip-${role.id}`">
                        <button
                            type="button"
                            x-on:click="removeRole(role.id)"
                            class="inline-flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700"
                        >
                            <span x-text="role.name"></span>
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </template>
                </div>

                <div class="max-h-40 overflow-auto rounded-lg border border-slate-300 px-3 py-2 space-y-2">
                    @foreach ($roles as $role)
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                name="roles[]"
                                value="{{ $role->id }}"
                                x-model="roles"
                                class="rounded-sm border-slate-300 text-[#185FA5] focus:ring-[#185FA5]/30"
                            />
                            <span>{{ $role->display_name }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-slate-500">Uncheck all roles to remove all assigned roles.</p>
            </div>

            {{-- Actions --}}
            <div class="flex justify-end gap-3 pt-4">
                <button
                    type="button"
                    x-on:click="open = false"
                    class="px-4 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-100"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="px-4 py-2 text-sm rounded-lg bg-[#185FA5] text-white hover:bg-[#185FA5]/90"
                >
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

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
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
>
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-lg p-6">
        <h2 class="text-lg font-semibold text-slate-800 mb-4">
            Create New User
        </h2>

        <form
            action="{{ route('uac.users.store') }}"
            method="POST"
            class="space-y-4"
        >
            @csrf

            {{-- Staff ID --}}
           <div x-data="employeePicker()">
    <label class="block text-sm font-medium text-slate-700 mb-1">Employee (Search by Staff ID)</label>

    <input type="text"
           x-model="query"
           x-on:input.debounce.300ms="search()"
           placeholder="Type staff ID or name..."
           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />

    <div x-show="results.length > 0" class="mt-2 border border-slate-200 rounded-lg bg-white max-h-48 overflow-auto">
        <template x-for="emp in results" :key="emp.id">
            <button type="button"
                    x-on:click="select(emp)"
                    class="w-full text-left px-3 py-2 hover:bg-slate-50">
                <div class="text-sm font-medium text-slate-800" x-text="emp.staff_id + ' — ' + emp.full_name"></div>
                <div class="text-xs text-slate-500" x-text="emp.email"></div>
            </button>
        </template>
    </div>

    {{-- Values submitted to backend --}}
    <input type="hidden" name="employee_id" x-model="selectedEmployeeId">
    <input type="hidden" name="staff_id" x-model="selectedStaffId">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Full Name</label>
            <input type="text"
                   x-model="selectedFullName"
                   readonly
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-slate-100" />
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
            <input type="text"
                   x-model="selectedEmail"
                   readonly
                   class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-slate-100" />
        </div>
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

            {{-- Roles --}}
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-sm font-medium text-slate-700">
                        Roles
                    </label>
                    <span class="text-xs text-slate-500" x-text="`${roles.length} selected`"></span>
                </div>

                <div class="mb-2 flex flex-wrap gap-2" x-show="roles.length > 0">
                    <template x-for="role in roleOptions.filter((option) => roles.includes(String(option.id)))" :key="`create-role-chip-${role.id}`">
                        <button
                            type="button"
                            x-on:click="removeRole(role.id)"
                            class="inline-flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700"
                        >
                            <span x-text="role.name"></span>
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </template>
                </div>

                <div class="max-h-40 overflow-auto rounded-lg border border-slate-300 px-3 py-2 space-y-2">
                    @foreach ($roles as $role)
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                name="roles[]"
                                value="{{ $role->id }}"
                                x-model="roles"
                                class="rounded-sm border-slate-300 text-[#185FA5] focus:ring-[#185FA5]/30"
                            />
                            <span>{{ $role->display_name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex justify-end gap-3 pt-4">
                <button
                    type="button"
                    x-on:click="open = false"
                    class="px-4 py-2 text-sm rounded-lg border border-slate-300 hover:bg-slate-100"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="px-4 py-2 text-sm rounded-lg bg-[#185FA5] text-white hover:bg-[#185FA5]/90"
                >
                    Create User
                </button>
            </div>
        </form>
    </div>
</div>
<x-uac.user-drawer />

</x-uac-layout>
