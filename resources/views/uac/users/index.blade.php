<x-uac-layout>

<div class="bg-white rounded-lg shadow">
    <div class="p-6 border-b border-gray-200 flex flex-wrap items-center justify-between gap-4">
        <form action="{{ route('uac.users') }}" method="GET" class="flex flex-wrap items-center gap-3">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search name, email, or staff ID..." 
                   class="border rounded px-3 py-2 text-sm w-64">

            <select name="role_id" class="border rounded px-3 py-2 text-sm">
                <option value="">All Roles</option>
                @foreach($roles as $role)
                    <option value="{{ $role->id }}" {{ $roleId == $role->id ? 'selected' : '' }}>
                        {{ $role->display_name }}
                    </option>
                @endforeach
            </select>

            <select name="status" class="border rounded px-3 py-2 text-sm">
                <option value="">All Status</option>
                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>

            <button type="submit" class="bg-slate-800 text-white px-4 py-2 rounded text-sm hover:bg-slate-700">
                Filter
            </button>
        </form>

        <button
            x-data
            x-on:click.prevent="$dispatch('create-user')"
            class="bg-[#185FA5] text-white px-4 py-2 rounded text-sm hover:bg-[#185FA5]/90"
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

<div class="bg-white rounded-2xl shadow-sm overflow-hidden border border-slate-100">
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
                            @forelse ($user->roles as $role)
                                <span class="bg-blue-50 text-blue-700 border border-blue-200 px-2.5 py-0.5 rounded-full text-xs font-medium">{{ $role->display_name }}</span>
                            @empty
                                <span class="bg-slate-100 text-slate-600 border border-slate-200 px-2.5 py-0.5 rounded-full text-xs font-medium">No role</span>
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
                                        roles: {{ $user->roles->pluck('id') }}
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
                                        class="text-xs px-2 py-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700">
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

<div class="mt-6">
    {{ $users->links() }}
</div>

<div
    x-data="{
        open: false,
        userId: null,
        name: '',
        email: '',
        roles: []
    }"
    x-on:edit-user.window="
        open = true;
        userId = $event.detail.id;
        name = $event.detail.name;
        email = $event.detail.email;
        roles = $event.detail.roles;
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
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-[#185FA5]/20"
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
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-[#185FA5]/20"
                />
            </div>

            {{-- Roles --}}
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Roles
                </label>
                <select
                    name="roles[]"
                    multiple
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm h-32"
                >
                    @foreach ($roles as $role)
                        <option
                            value="{{ $role->id }}"
                            x-bind:selected="roles.includes({{ $role->id }})"
                        >
                            {{ $role->display_name }}
                        </option>
                    @endforeach
                </select>
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
        roles: []
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
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Staff ID
                </label>
                <input
                    type="text"
                    name="staff_id"
                    x-model="staffId"
                    required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-[#185FA5]/20"
                />
                <p class="text-xs text-slate-500 mt-1">
                    Must match an existing employee record.
                </p>
            </div>

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
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-[#185FA5]/20"
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
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-[#185FA5]/20"
                />
            </div>

            {{-- Roles --}}
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Roles
                </label>
                <select
                    name="roles[]"
                    multiple
                    required
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm h-32"
                >
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}">
                            {{ $role->display_name }}
                        </option>
                    @endforeach
                </select>
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
<div
    x-data="userDrawer()"
    x-on:open-user-drawer.window="open($event.detail.id)"
    x-show="isOpen"
    x-cloak
    class="fixed inset-0 z-50"
>
    {{-- Backdrop --}}
    <div
        x-show="isOpen"
        x-transition.opacity
        x-on:click="close()"
        class="absolute inset-0 bg-black/40"
    ></div>

    {{-- Drawer --}}
    <div
        x-show="isOpen"
        x-transition:enter="transform transition ease-out duration-300"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transform transition ease-in duration-200"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="absolute right-0 top-0 h-full w-full max-w-xl bg-white shadow-xl border-l"
    >
        <div class="p-6 flex items-start justify-between border-b">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">User Profile</h2>
                <p class="text-sm text-slate-500">Read-only details</p>
            </div>

            <button
                type="button"
                x-on:click="close()"
                class="text-slate-500 hover:text-slate-800 text-2xl leading-none"
                title="Close"
            >
                &times;
            </button>
        </div>

        <div class="p-6 overflow-y-auto h-[calc(100%-72px)]">
            {{-- Loading --}}
            <template x-if="loading">
                <div class="text-sm text-slate-600">Loading profile…</div>
            </template>

            {{-- Error --}}
            <template x-if="error">
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800 text-sm">
                    <span x-text="error"></span>
                </div>
            </template>

            {{-- Content --}}
            <template x-if="data && !loading">
                <div class="space-y-6">

                    {{-- User summary --}}
                    <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm text-slate-500">Name</p>
                                <p class="text-base font-semibold text-slate-900" x-text="data.user.full_name ?? '—'"></p>

                                <p class="text-sm text-slate-500 mt-2">Email</p>
                                <p class="text-sm text-slate-800" x-text="data.user.email ?? '—'"></p>

                                <p class="text-sm text-slate-500 mt-2">Staff ID</p>
                                <p class="text-sm text-slate-800" x-text="data.user.staff_id ?? '—'"></p>
                            </div>

                            <div class="text-right">
                                <span
                                    class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium border"
                                    :class="data.user.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-red-50 text-red-700 border-red-200'"
                                    x-text="data.user.is_active ? 'Active' : 'Inactive'"
                                ></span>

                                <p class="text-xs text-slate-500 mt-3">Last login</p>
                                <p class="text-xs text-slate-800" x-text="data.user.last_login_at ?? 'Never'"></p>
                            </div>
                        </div>

                        <div class="mt-4">
                            <p class="text-sm text-slate-500 mb-2">Roles</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="r in data.user.roles" :key="r.id">
                                    <span class="bg-blue-50 text-blue-700 border border-blue-200 px-2.5 py-0.5 rounded-full text-xs font-medium"
                                          x-text="r.display_name"></span>
                                </template>

                                <template x-if="!data.user.roles || data.user.roles.length === 0">
                                    <span class="text-sm text-slate-500">No roles</span>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Employee profile --}}
                    <div class="bg-white border border-slate-200 rounded-2xl p-4" x-show="data.employee">
                        <h3 class="text-sm font-semibold text-slate-700 mb-3">Employee Profile</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <p class="text-slate-500">Job Title</p>
                                <p class="text-slate-900 font-medium" x-text="data.employee.job_title ?? '—'"></p>
                            </div>

                            <div>
                                <p class="text-slate-500">Department</p>
                                <p class="text-slate-900 font-medium" x-text="data.employee.department ?? '—'"></p>
                            </div>

                            <div>
                                <p class="text-slate-500">Region</p>
                                <p class="text-slate-900 font-medium" x-text="data.employee.region ?? '—'"></p>
                            </div>

                            <div>
                                <p class="text-slate-500">District</p>
                                <p class="text-slate-900 font-medium" x-text="data.employee.district ?? '—'"></p>
                            </div>

                            <div>
                                <p class="text-slate-500">Category</p>
                                <p class="text-slate-900 font-medium" x-text="data.employee.category ?? '—'"></p>
                            </div>

                            <div>
                                <p class="text-slate-500">Gender</p>
                                <p class="text-slate-900 font-medium" x-text="data.employee.gender ?? '—'"></p>
                            </div>

                            <div>
                                <p class="text-slate-500">Location Type</p>
                                <p class="text-slate-900 font-medium" x-text="data.employee.location_type ?? '—'"></p>
                            </div>

                            <div>
                                <p class="text-slate-500">Unit</p>
                                <p class="text-slate-900 font-medium" x-text="data.employee.unit ?? '—'"></p>
                            </div>

                            <div>
                                <p class="text-slate-500">Appointment</p>
                                <p class="text-slate-900 font-medium" x-text="data.employee.present_appointment ?? '—'"></p>
                            </div>

                            <div>
                                <p class="text-slate-500">DOB / Age</p>
                                <p class="text-slate-900 font-medium">
                                    <span x-text="data.employee.date_of_birth ?? '—'"></span>
                                    <span class="text-slate-500" x-text="data.employee.age ? ` (Age ${data.employee.age})` : ''"></span>
                                </p>
                            </div>

                            <div>
                                <p class="text-slate-500">Date Joined</p>
                                <p class="text-slate-900 font-medium" x-text="data.employee.date_joined ?? '—'"></p>
                            </div>
                        </div>
                    </div>

                    {{-- Leave entitlements --}}
                    <div class="bg-white border border-slate-200 rounded-2xl p-4" x-show="data.employee">
                        <h3 class="text-sm font-semibold text-slate-700 mb-3">Leave Entitlements</h3>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                                <p class="text-slate-500">Annual</p>
                                <p class="text-lg font-semibold text-slate-900" x-text="data.employee.annual_leave_days ?? '—'"></p>
                            </div>

                            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                                <p class="text-slate-500">Casual</p>
                                <p class="text-lg font-semibold text-slate-900" x-text="data.employee.casual_leave_days ?? '—'"></p>
                            </div>

                            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                                <p class="text-slate-500">Parental</p>
                                <p class="text-lg font-semibold text-slate-900" x-text="data.employee.parental_days ?? '—'"></p>
                            </div>
                        </div>
                    </div>

                    {{-- No employee linked --}}
                    <template x-if="data && !data.employee">
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800 text-sm">
                            No employee profile is linked to this user.
                        </div>
                    </template>

                </div>
            </template>
        </div>
    </div>
</div>

<script>
function userDrawer() {
    return {
        isOpen: false,
        loading: false,
        error: '',
        data: null,

        open(id) {
            this.isOpen = true;
            this.loading = true;
            this.error = '';
            this.data = null;

            fetch(`{{ url('/uac/users') }}/${id}`, {
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(async (res) => {
                const contentType = res.headers.get('content-type') || '';
                const bodyText = await res.text();

                if (!res.ok) {
                    // show server message (403/500/etc.)
                    throw new Error(`HTTP ${res.status}: ${bodyText.substring(0, 300)}`);
                }

                // If server returned HTML (dashboard/login page), JSON parse would fail
                if (!contentType.includes('application/json')) {
                    throw new Error(`Expected JSON but got: ${contentType}. Body: ${bodyText.substring(0, 300)}`);
                }

                return JSON.parse(bodyText);
            })
            .then((json) => {
                this.data = json;
            })
            .catch((e) => {
                this.error = e.message;
                console.error(e);
            })
            .finally(() => {
                this.loading = false;
            });
        },

        close() {
            this.isOpen = false;
        }
    }
}
</script>

</x-uac-layout>
