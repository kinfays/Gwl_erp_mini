<div>
    @php
        $moduleLabel = fn (string $module) => strlen($module) <= 3 ? strtoupper($module) : \Illuminate\Support\Str::headline($module);
    @endphp

    <x-ui.page-header title="Roles & Permissions" description="Pick a role, then choose which modules it can open and what it can do in them." />

    <div class="ui-grid ui-grid-sidebar">
        {{-- Roles list --}}
        <x-ui.card title="Roles" :padded="false">
            <x-slot:actions>
                <x-ui.badge>{{ count($roles) }}</x-ui.badge>
            </x-slot:actions>

            <div class="ui-select-list" role="list">
                @foreach ($roles as $role)
                    <button
                        type="button"
                        wire:click="selectRole({{ $role['id'] }})"
                        class="ui-select-item"
                        role="listitem"
                        aria-pressed="{{ $selectedRoleId === $role['id'] ? 'true' : 'false' }}"
                    >
                        <span class="ui-select-item-main">
                            <span class="ui-select-item-title">{{ $role['display_name'] }}</span>
                            <span class="ui-select-item-sub mono">{{ $role['name'] }}</span>
                        </span>
                        <x-ui.badge :tone="$role['is_system'] ? 'success' : 'neutral'">{{ $role['is_system'] ? 'System' : 'Custom' }}</x-ui.badge>
                    </button>
                @endforeach
            </div>
        </x-ui.card>

        <div class="ui-stack">
            {{-- Role header --}}
            <x-ui.card>
                <div class="uac-role-head">
                    <div class="uac-role-titles">
                        <div class="uac-role-name">
                            <h2>{{ $selectedRole?->display_name ?? 'Select a role' }}</h2>
                            @if ($selectedRoleId && $locked)
                                <x-ui.badge tone="warning">
                                    <x-ui.icon name="key-round" class="icon-sm" />
                                    Locked
                                </x-ui.badge>
                            @endif
                        </div>
                        <p class="ui-card-desc">Configure permissions and enabled modules.</p>

                        @if ($selectedRole)
                            <div class="ui-tags uac-role-summary">
                                <x-ui.badge tone="primary">
                                    <x-ui.icon name="shield-check" class="icon-sm" />
                                    <span><strong>{{ count($selectedPermissionIds) }}</strong> permissions assigned</span>
                                </x-ui.badge>
                                <x-ui.badge tone="success">
                                    <x-ui.icon name="layout-dashboard" class="icon-sm" />
                                    <span><strong>{{ collect($moduleAccess)->filter()->count() }}</strong> / {{ count($modules) }} modules enabled</span>
                                </x-ui.badge>
                            </div>
                        @endif
                    </div>

                    <div class="ui-card-actions">
                        @if ($canCreate)
                            <x-ui.button icon="plus" wire:click="$set('showCreateRole', true)">New Role</x-ui.button>
                        @endif
                        <x-ui.button icon="pencil" wire:click="openEditRole" :disabled="! $canEditDetails">Edit</x-ui.button>
                        <x-ui.button variant="danger" icon="trash-2" wire:click="openDeleteRole" :disabled="! $canDelete">Delete</x-ui.button>
                        <x-ui.button variant="primary" icon="check" wire:click="save" :disabled="! $canEdit" loading="save">Save</x-ui.button>
                    </div>
                </div>

                @if ($locked)
                    <x-ui.alert tone="warning" class="uac-role-alert">
                        {{ $lockReason }}
                    </x-ui.alert>
                @endif

                @error('selectedPermissionIds')
                    <x-ui.alert tone="danger" class="uac-role-alert" role="alert">{{ $message }}</x-ui.alert>
                @enderror

                @if ($message)
                    <x-ui.alert tone="success" class="uac-role-alert" role="status">
                        {{ $message }}
                    </x-ui.alert>
                @endif
            </x-ui.card>

            {{-- Who may hand this role out --}}
            @if ($selectedRole && $canSetIctAssignable)
                <x-ui.card title="ICT team" description="Whether the ICT team may assign this role, and only to users inside their own location.">
                    <x-ui.toggle
                        label="ICT team can assign this role"
                        description="Off: only a Global Admin or Super Admin can give or remove it"
                        id="ict-assignable"
                        wire:click="toggleIctAssignable"
                        x-bind:checked="{{ $ictAssignable ? 'true' : 'false' }}"
                        :disabled="! $canEdit"
                    />
                </x-ui.card>
            @endif

            {{-- Module access --}}
            <x-ui.card title="Module Access" description="Which modules people with this role can open.">
                <x-slot:actions>
                    <span class="ui-hint">{{ collect($moduleAccess)->filter()->count() }} of {{ count($modules) }} enabled</span>
                </x-slot:actions>

                <div class="ui-grid ui-grid-2">
                    @foreach ($modules as $module)
                        @php
                            $isModuleEnabled = $moduleAccess[$module] ?? false;
                        @endphp
                        <div @class(['uac-module-row', 'is-on' => $isModuleEnabled])>
                            <x-ui.toggle
                                :label="$moduleLabel($module)"
                                description="Allow role to access this module"
                                :id="'module-access-'.$module"
                                wire:click="toggleModuleAccess('{{ $module }}')"
                                x-bind:checked="{{ $isModuleEnabled ? 'true' : 'false' }}"
                                :disabled="! $canEdit"
                            />
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            {{-- Permissions matrix --}}
            <x-ui.card title="Permissions" description="Grouped by module.">
                <x-slot:actions>
                    <span class="ui-hint">{{ count($selectedPermissionIds) }} selected</span>
                </x-slot:actions>

                <div class="ui-grid ui-grid-2">
                    @foreach ($permissionsByModule as $module => $items)
                        @php
                            $moduleSelectedCount = collect($items)->whereIn('id', $selectedPermissionIds)->count();
                            $moduleTotalCount = count($items);
                        @endphp
                        <section class="ui-panel">
                            <div class="uac-perm-head">
                                <h3 class="ui-panel-title">{{ $moduleLabel($module) }}</h3>
                                <x-ui.badge :tone="$moduleSelectedCount > 0 ? 'primary' : 'neutral'">{{ $moduleSelectedCount }}/{{ $moduleTotalCount }}</x-ui.badge>
                            </div>

                            <div class="uac-perm-list">
                                @foreach ($items as $perm)
                                    @php
                                        $isPermSelected = in_array($perm['id'], $selectedPermissionIds);
                                    @endphp
                                    <x-ui.checkbox
                                        :label="$perm['display_name']"
                                        :id="'perm-'.$perm['id']"
                                        value="{{ $perm['id'] }}"
                                        wire:click="togglePermission({{ $perm['id'] }})"
                                        x-bind:checked="{{ $isPermSelected ? 'true' : 'false' }}"
                                        :disabled="! $canEdit"
                                    />
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>

                @if (! $canEdit)
                    <p class="ui-hint uac-perm-note">
                        <x-ui.icon name="info" class="icon-sm" />
                        Only authorized administrators can modify permissions and module access for this role.
                    </p>
                @endif
            </x-ui.card>
        </div>
    </div>

    {{-- CREATE ROLE MODAL --}}
    @if ($showCreateRole)
        <x-ui.modal title="Create New Role" close="$set('showCreateRole', false)">
            <div class="ui-stack">
                <x-ui.input label="Role Slug" wire:model.defer="newRoleSlug" placeholder="e.g. finance_manager" class="mono" />
                <x-ui.input label="Display Name" wire:model.defer="newRoleDisplayName" placeholder="Finance Manager" />
                <x-ui.textarea label="Description (optional)" wire:model.defer="newRoleDescription" rows="3" placeholder="What this role is for..." />
            </div>

            <x-slot:footer>
                <x-ui.button wire:click="$set('showCreateRole', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" wire:click="createRole" loading="createRole">Create Role</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($showEditRole)
        <x-ui.modal title="Edit Role" close="$set('showEditRole', false)">
            <div class="ui-stack">
                <x-ui.input label="Display Name" wire:model.defer="editRoleDisplayName" />
                <x-ui.textarea label="Description" wire:model.defer="editRoleDescription" rows="4" />
            </div>

            <x-slot:footer>
                <x-ui.button wire:click="$set('showEditRole', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" wire:click="updateRole" loading="updateRole">Save Changes</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($showDeleteRole)
        <x-ui.modal title="Delete Role" close="$set('showDeleteRole', false)" size="sm" icon="trash-2" tone="danger">
            <div class="ui-stack">
                <x-ui.alert tone="danger" title="This action cannot be undone.">
                    All users with this role will need to be reassigned.
                </x-ui.alert>

                <x-ui.field for="delete-confirm-text" error="deleteConfirmText">
                    <label class="ui-label" for="delete-confirm-text">Type <strong class="uac-danger-word">DELETE</strong> to confirm</label>
                    <input id="delete-confirm-text" wire:model.defer="deleteConfirmText" placeholder="Type DELETE to confirm" class="form-input ui-input mono" autocomplete="off">
                </x-ui.field>
            </div>

            <x-slot:footer>
                <x-ui.button wire:click="$set('showDeleteRole', false)">Cancel</x-ui.button>
                <x-ui.button variant="danger-solid" wire:click="deleteRole" loading="deleteRole">Delete Role</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
