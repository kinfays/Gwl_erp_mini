@props([
    'triggerClass' => '',
    'urlBase' => url('/uac/users'),
])

<div
    x-data="userDrawer()"
    x-on:open-user-drawer.window="open($event.detail.id, $event.detail.url)"
    x-show="isOpen"
    x-cloak
    class="fixed inset-0 z-50"
>
    <div
        x-show="isOpen"
        x-transition.opacity
        x-on:click="close()"
        class="absolute inset-0 bg-black/40"
    ></div>

    <div
        x-show="isOpen"
        x-transition:enter="transform transition ease-out duration-300"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transform transition ease-in duration-200"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="absolute right-0 top-0 h-full w-full max-w-xl bg-white shadow-xl border-l drawer-surface dark:bg-slate-900 dark:border-slate-700"
    >
        <div class="p-6 flex items-start justify-between border-b dark:border-slate-700">
            <div>
                <h2 class="text-lg font-semibold text-slate-800 dark:text-slate-100">User Profile</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">Read-only details</p>
            </div>

            <button
                type="button"
                x-on:click="close()"
                class="text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100 text-2xl leading-none"
                title="Close"
            >
                &times;
            </button>
        </div>

        <div class="p-6 overflow-y-auto h-[calc(100%-72px)]">
            <template x-if="loading">
                <div class="text-sm text-slate-600 dark:text-slate-300">Loading profile...</div>
            </template>

            <template x-if="error">
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800 text-sm dark:bg-red-950/40 dark:border-red-800 dark:text-red-200">
                    <span x-text="error"></span>
                </div>
            </template>

            <template x-if="data && !loading">
                <div class="space-y-6">
                    <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 drawer-muted-surface dark:bg-slate-800 dark:border-slate-700">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm text-slate-500 dark:text-slate-400">Name</p>
                                <p class="text-base font-semibold text-slate-900 dark:text-slate-100" x-text="data.user.full_name ?? '-'"></p>

                                <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">Email</p>
                                <p class="text-sm text-slate-800 dark:text-slate-200" x-text="data.user.email ?? '-'"></p>

                                <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">Staff ID</p>
                                <p class="text-sm text-slate-800 dark:text-slate-200" x-text="data.user.staff_id ?? '-'"></p>
                            </div>

                            <div class="text-right">
                                <span
                                    class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium border"
                                    :class="data.user.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-200 dark:border-emerald-800' : 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-200 dark:border-red-800'"
                                    x-text="data.user.is_active ? 'Active' : 'Inactive'"
                                ></span>

                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-3">Last login</p>
                                <p class="text-xs text-slate-800 dark:text-slate-200" x-text="data.user.last_login_at ?? 'Never'"></p>
                            </div>
                        </div>

                        <div class="mt-4">
                            <p class="text-sm text-slate-500 dark:text-slate-400 mb-2">Roles</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="role in data.user.roles" :key="role.id">
                                    <span class="bg-blue-50 text-blue-700 border border-blue-200 px-2.5 py-0.5 rounded-full text-xs font-medium dark:bg-blue-950/40 dark:text-blue-200 dark:border-blue-800"
                                          x-text="role.display_name"></span>
                                </template>

                                <template x-if="!data.user.roles || data.user.roles.length === 0">
                                    <span class="text-sm text-slate-500 dark:text-slate-400">No additional roles</span>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-2xl p-4 drawer-surface dark:bg-slate-900 dark:border-slate-700" x-show="data.employee">
                        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-3">Employee Profile</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <template x-for="item in employeeFields()" :key="item.label">
                                <div>
                                    <p class="text-slate-500 dark:text-slate-400" x-text="item.label"></p>
                                    <p class="text-slate-900 dark:text-slate-100 font-medium" x-text="item.value"></p>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-2xl p-4 drawer-surface dark:bg-slate-900 dark:border-slate-700" x-show="data.employee">
                        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-3">Leave Entitlements</h3>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 drawer-muted-surface dark:bg-slate-800 dark:border-slate-700">
                                <p class="text-slate-500 dark:text-slate-400">Annual</p>
                                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100" x-text="data.employee.annual_leave_days ?? '-'"></p>
                            </div>

                            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 drawer-muted-surface dark:bg-slate-800 dark:border-slate-700">
                                <p class="text-slate-500 dark:text-slate-400">Casual</p>
                                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100" x-text="data.employee.casual_leave_days ?? '-'"></p>
                            </div>

                            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 drawer-muted-surface dark:bg-slate-800 dark:border-slate-700">
                                <p class="text-slate-500 dark:text-slate-400">Parental</p>
                                <p class="text-lg font-semibold text-slate-900 dark:text-slate-100" x-text="data.employee.parental_days ?? '-'"></p>
                            </div>
                        </div>
                    </div>

                    <template x-if="data && !data.employee">
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800 text-sm dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-200">
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

        employeeFields() {
            if (!this.data?.employee) return [];

            const employee = this.data.employee;

            return [
                { label: 'Job Title', value: employee.job_title ?? '-' },
                { label: 'Department', value: employee.department ?? '-' },
                { label: 'Region', value: employee.region ?? '-' },
                { label: 'Location', value: employee.district ?? '-' },
                { label: 'Category', value: employee.category ?? '-' },
                { label: 'Employee Status', value: employee.status ?? '-' },
                { label: 'Deactivation Reason', value: employee.deactivation_reason_label ?? '-' },
                { label: 'Gender', value: employee.gender ?? '-' },
                { label: 'Location Type', value: employee.location_type ?? '-' },
                { label: 'Unit', value: employee.unit ?? '-' },
                { label: 'Appointment', value: employee.present_appointment ?? '-' },
                { label: 'DOB / Age', value: `${employee.date_of_birth ?? '-'}${employee.age ? ` (Age ${employee.age})` : ''}` },
                { label: 'Date Joined', value: employee.date_joined ?? '-' },
            ];
        },

        open(id, url = null) {
            this.isOpen = true;
            this.loading = true;
            this.error = '';
            this.data = null;

            fetch(url || `{{ rtrim($urlBase, '/') }}/${id}`, {
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(async (res) => {
                const contentType = res.headers.get('content-type') || '';
                const bodyText = await res.text();

                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}: ${bodyText.substring(0, 300)}`);
                }

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
