@props([
    'triggerClass' => '',
    'urlBase' => url('/uac/users'),
])

<div
    x-data="userDrawer()"
    x-on:open-user-drawer.window="open($event.detail.id, $event.detail.url)"
>
    <x-ui.drawer show="isOpen" close="close()" title="User Profile" description="Read-only details">
        <template x-if="loading">
            <div class="ui-stack" role="status">
                <span class="sr-only-text">Loading profile...</span>
                <div class="ui-panel" aria-hidden="true">
                    <span class="skeleton-line short"></span>
                    <span class="skeleton-line"></span>
                    <span class="skeleton-line"></span>
                </div>
                <div class="ui-panel" aria-hidden="true">
                    <span class="skeleton-line short"></span>
                    <span class="skeleton-line"></span>
                </div>
            </div>
        </template>

        <template x-if="error">
            <x-ui.alert tone="danger" role="alert"><span x-text="error"></span></x-ui.alert>
        </template>

        <template x-if="data && !loading">
            <div class="ui-stack">
                <section class="ui-panel drawer-muted-surface">
                    <div class="user-drawer-head">
                        <span class="ui-avatar ui-avatar-lg ui-avatar-primary" aria-hidden="true" x-text="initials(data.user.full_name)"></span>
                        <div class="user-drawer-name">
                            <p class="ui-person-name" x-text="data.user.full_name ?? '-'"></p>
                            <p class="ui-person-sub" x-text="data.user.email ?? '-'"></p>
                        </div>
                        <span
                            class="ui-pill"
                            :class="data.user.is_active ? 'ui-pill-success' : 'ui-pill-muted'"
                            x-text="data.user.is_active ? 'Active' : 'Inactive'"
                        ></span>
                    </div>

                    <dl class="ui-dl">
                        <div>
                            <dt>Staff ID</dt>
                            <dd class="mono" x-text="data.user.staff_id ?? '-'"></dd>
                        </div>
                        <div>
                            <dt>Last login</dt>
                            <dd x-text="data.user.last_login_at ?? 'Never'"></dd>
                        </div>
                    </dl>

                    <div class="user-drawer-roles">
                        <p class="ui-panel-title">Roles</p>
                        <div class="ui-tags">
                            <template x-for="role in data.user.roles" :key="role.id">
                                <span class="ui-badge ui-badge-primary" x-text="role.display_name"></span>
                            </template>

                            <template x-if="!data.user.roles || data.user.roles.length === 0">
                                <span class="ui-hint">No additional roles</span>
                            </template>
                        </div>
                    </div>
                </section>

                <section class="ui-card drawer-surface user-drawer-section" x-show="data.employee">
                    <h3 class="ui-panel-title">Employee Profile</h3>

                    <dl class="ui-dl">
                        <template x-for="item in employeeFields()" :key="item.label">
                            <div>
                                <dt x-text="item.label"></dt>
                                <dd x-text="item.value"></dd>
                            </div>
                        </template>
                    </dl>
                </section>

                <section class="ui-card drawer-surface user-drawer-section" x-show="data.employee">
                    <h3 class="ui-panel-title">Leave Entitlements</h3>

                    <div class="ui-grid ui-grid-3">
                        <div class="ui-panel drawer-muted-surface">
                            <p class="ui-person-sub">Annual</p>
                            <p class="user-drawer-number" x-text="data.employee.annual_leave_days ?? '-'"></p>
                        </div>

                        <div class="ui-panel drawer-muted-surface">
                            <p class="ui-person-sub">Casual</p>
                            <p class="user-drawer-number" x-text="data.employee.casual_leave_days ?? '-'"></p>
                        </div>

                        <div class="ui-panel drawer-muted-surface">
                            <p class="ui-person-sub">Parental</p>
                            <p class="user-drawer-number" x-text="data.employee.parental_days ?? '-'"></p>
                        </div>
                    </div>
                </section>

                <template x-if="data && !data.employee">
                    <x-ui.alert tone="warning">No employee profile is linked to this user.</x-ui.alert>
                </template>
            </div>
        </template>
    </x-ui.drawer>
</div>

<script>
function userDrawer() {
    return {
        isOpen: false,
        loading: false,
        error: '',
        data: null,

        initials(name) {
            return String(name || '')
                .trim()
                .split(/\s+/)
                .filter(Boolean)
                .slice(0, 2)
                .map((part) => part.charAt(0).toUpperCase())
                .join('') || '?';
        },

        employeeFields() {
            if (!this.data?.employee) return [];

            const employee = this.data.employee;

            return [
                { label: 'Job Title', value: employee.job_title ?? '-' },
                { label: 'Department', value: employee.department ?? '-' },
                { label: 'Region', value: employee.region ?? '-' },
                { label: 'Location', value: employee.district ?? '-' },
                { label: 'Category', value: employee.category ?? '-' },
                { label: 'Grade', value: employee.grade ?? 'Not set' },
                { label: 'Employee Status', value: employee.status ?? '-' },
                { label: 'Deactivation Reason', value: employee.deactivation_reason_label ?? '-' },
                { label: 'Gender', value: employee.gender ?? '-' },
                { label: 'Location Type', value: employee.location_type ?? '-' },
                { label: 'Unit', value: employee.unit ?? '-' },
                { label: 'Appointment', value: employee.present_appointment ?? '-' },
                { label: 'DOB / Age', value: `${employee.date_of_birth ?? '-'}${employee.age ? ` (Age ${employee.age})` : ''}` },
                { label: 'Retirement Date', value: employee.retirement_date ?? '-' },
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
