<div>
    {{-- MAIN GRID (existing content) --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Left: Roles list --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4">
            <h2 class="text-sm font-semibold text-slate-700 mb-3">Roles</h2>

            <div class="space-y-2">
                @foreach ($roles as $role)
                    <button
                        wire:click="selectRole({{ $role['id'] }})"
                        class="w-full text-left px-3 py-2 rounded-lg border
                            {{ $selectedRoleId === $role['id'] ? 'bg-[#185FA5]/10 border-[#185FA5]/30' : 'bg-white border-slate-200 hover:bg-slate-50' }}"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-slate-800">{{ $role['display_name'] }}</span>
                            <span class="text-xs px-2 py-0.5 rounded-full
                                {{ $role['is_system'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200' }}">
                                {{ $role['is_system'] ? 'System' : 'Custom' }}
                            </span>
                        </div>
                        <div class="text-xs text-slate-500 mt-1">{{ $role['name'] }}</div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Right: Permissions + Modules --}}
        <div class="lg:col-span-2 space-y-6">

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-800">
                            {{ $selectedRole?->display_name ?? 'Select a role' }}
                        </h2>
                        <p class="text-sm text-slate-600">Configure permissions and enabled modules.</p>

                        @if ($locked)
                            <p class="mt-2 text-sm text-amber-700 bg-amber-50 border border-amber-200 px-3 py-2 rounded-lg">
                                This role is locked (system role).
                            </p>
                        @endif

                        @if ($message)
                            <p class="mt-2 text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 px-3 py-2 rounded-lg">
                                {{ $message }}
                            </p>
                        @endif
                    </div>

                   <div class="flex gap-2">
    <button wire:click="$set('showCreateRole', true)" class="btn-secondary">
        + New Role
    </button>

    <button wire:click="openEditRole" class="btn-secondary">
        Edit
    </button>

    <button wire:click="openDeleteRole" class="btn-danger">
        Delete
    </button>

    <button wire:click="save" @disabled(! $canEdit) class="btn-primary">
        Save
    </button>
</div>
                </div>
            </div>

            {{-- Module access toggles --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h3 class="text-sm font-semibold text-slate-700 mb-4">Module Access</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach ($modules as $module)
                        <label class="flex items-center justify-between rounded-xl border border-slate-200 px-4 py-3">
                            <div>
                                <div class="text-sm font-medium text-slate-800">{{ strtoupper($module) }}</div>
                                <div class="text-xs text-slate-500">Allow role to access this module</div>
                            </div>

                            <input
                                type="checkbox"
                                wire:model="moduleAccess.{{ $module }}"
                                @disabled(! $canEdit)
                                class="w-5 h-5 rounded border-slate-300 text-[#185FA5] focus:ring-[#185FA5]/30"
                            />
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Permissions matrix --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h3 class="text-sm font-semibold text-slate-700 mb-4">Permissions (Grouped by Module)</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach ($permissionsByModule as $module => $items)
                        <div class="rounded-2xl border border-slate-200 p-4 bg-slate-50/60">
                            <div class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-3">
                                {{ strtoupper($module) }}
                            </div>

                            <div class="space-y-2">
                                @foreach ($items as $perm)
                                    <label class="flex items-center gap-3 bg-white border border-slate-100 rounded-xl px-3 py-2">
                                        <input
                                            type="checkbox"
                                            value="{{ $perm['id'] }}"
                                            wire:model="selectedPermissionIds"
                                            @disabled(! $canEdit)
                                            class="rounded border-slate-300 text-[#185FA5] focus:ring-[#185FA5]/30"
                                        />
                                        <span class="text-sm text-slate-700">{{ $perm['display_name'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                @if (! $canEdit)
                    <p class="mt-4 text-xs text-slate-500">
                        Only Super Admin can modify permissions and module access.
                    </p>
                @endif
            </div>

        </div>
    </div>

    {{-- CREATE ROLE MODAL (must be INSIDE the same root wrapper) --}}
    @if ($showCreateRole)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="bg-white w-full max-w-lg rounded-2xl shadow-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-slate-800">Create New Role</h2>
                    <button wire:click="$set('showCreateRole', false)" class="text-slate-500 hover:text-slate-800">&times;</button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Role Slug</label>
                        <input type="text" wire:model.defer="newRoleSlug"
                               placeholder="e.g. finance_manager"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('newRoleSlug') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Display Name</label>
                        <input type="text" wire:model.defer="newRoleDisplayName"
                               placeholder="Finance Manager"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('newRoleDisplayName') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Description (optional)</label>
                        <textarea wire:model.defer="newRoleDescription"
                                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                  rows="3"
                                  placeholder="What this role is for..."></textarea>
                        @error('newRoleDescription') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button wire:click="$set('showCreateRole', false)"
                                class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
                            Cancel
                        </button>

                        <button wire:click="createRole"
                                class="px-4 py-2 rounded-lg bg-[#185FA5] text-white hover:bg-[#185FA5]/90">
                            Create Role
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showEditRole)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="modal bg-white w-full max-w-lg rounded-2xl shadow-lg p-6">
                <h2 class="text-lg font-semibold text-slate-800 mb-4">Edit Role</h2>

                <div class="space-y-4">
                    <div>
                        <input wire:model.defer="editRoleDisplayName" placeholder="Display name"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <textarea wire:model.defer="editRoleDescription" placeholder="Description"
                                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" rows="4"></textarea>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button wire:click="updateRole"
                                class="px-4 py-2 rounded-lg bg-[#185FA5] text-white hover:bg-[#185FA5]/90">
                            Save
                        </button>
                        <button wire:click="$set('showEditRole', false)"
                                class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showDeleteRole)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="modal bg-white w-full max-w-md rounded-2xl shadow-lg p-6">
                <p class="text-sm text-slate-700 mb-4">Type <strong>DELETE</strong> to confirm.</p>

                <div class="space-y-4">
                    <input wire:model.defer="deleteConfirmText"
                           placeholder="Type DELETE to confirm"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />

                    <div class="flex justify-end gap-3">
                        <button wire:click="deleteRole"
                                class="px-4 py-2 rounded-lg bg-red-600 text-white hover:bg-red-700">
                            Delete
                        </button>
                        <button wire:click="$set('showDeleteRole', false)"
                                class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
