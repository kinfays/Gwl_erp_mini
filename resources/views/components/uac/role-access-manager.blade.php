<div>
    {{-- MAIN GRID --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Left: Roles list --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4">
            <h2 class="text-sm font-semibold text-slate-700 mb-3">Roles</h2>

            <div class="space-y-2">
                @foreach ($roles as $role)
                    <button
                        wire:click="selectRole({{ $role['id'] }})"
                        class="w-full text-left px-3 py-2 rounded-lg border transition-all duration-150
                            {{ $selectedRoleId === $role['id'] ? 'bg-[#185FA5]/10 border-[#185FA5]/30 ring-1 ring-[#185FA5]/20' : 'bg-white border-slate-200 hover:bg-slate-50' }}"
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

            {{-- Role Header Card --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-3">
                            <h2 class="text-lg font-semibold text-slate-800">
                                {{ $selectedRole?->display_name ?? 'Select a role' }}
                            </h2>
                            @if ($selectedRoleId && $locked)
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                                    </svg>
                                    Locked
                                </span>
                            @endif
                        </div>
                        <p class="text-sm text-slate-600 mt-1">Configure permissions and enabled modules.</p>

                        @if ($selectedRole)
                            {{-- Real-time Summary Badges --}}
                            <div class="flex items-center gap-4 mt-4">
                                <div class="flex items-center gap-2 px-3 py-1.5 bg-[#185FA5]/5 border border-[#185FA5]/20 rounded-lg">
                                    <svg class="w-4 h-4 text-[#185FA5]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                    <span class="text-xs font-medium text-[#185FA5]">
                                        <strong>{{ count($selectedPermissionIds) }}</strong> permissions assigned
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-lg">
                                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                                    </svg>
                                    <span class="text-xs font-medium text-emerald-700">
                                        <strong>{{ collect($moduleAccess)->filter()->count() }}</strong> / {{ count($modules) }} modules enabled
                                    </span>
                                </div>
                            </div>
                        @endif

                        @if ($locked)
                            <div class="mt-3 flex items-center gap-2 text-sm text-amber-700 bg-amber-50 border border-amber-200 px-3 py-2 rounded-lg">
                                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                                This role is locked (system role). Permissions cannot be modified.
                            </div>
                        @endif

                        @if ($message)
                            <div class="mt-3 flex items-center gap-2 text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 px-3 py-2 rounded-lg">
                                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                {{ $message }}
                            </div>
                        @endif
                    </div>

                    <div class="flex gap-2 flex-wrap justify-end">
                        <button wire:click="$set('showCreateRole', true)" class="btn btn-primary">
                            + New Role
                        </button>

                        <button wire:click="openEditRole" @disabled(!$selectedRoleId) class="btn btn-secondary disabled:opacity-50 disabled:cursor-not-allowed">
                            Edit
                        </button>

                        <button wire:click="openDeleteRole" @disabled(!$selectedRoleId) class="btn btn-danger disabled:opacity-50 disabled:cursor-not-allowed">
                            Delete
                        </button>

                        <button wire:click="save" @disabled(! $canEdit) class="btn btn-primary disabled:opacity-50 disabled:cursor-not-allowed">
                            Save
                        </button>
                    </div>
                </div>
            </div>

            {{-- Module access toggles --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-slate-700">Module Access</h3>
                    <span class="text-xs text-slate-500">
                        {{ collect($moduleAccess)->filter()->count() }} of {{ count($modules) }} enabled
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach ($modules as $module)
                        @php
                            $isModuleEnabled = $moduleAccess[$module] ?? false;
                        @endphp
                        <label class="flex items-center justify-between rounded-xl border transition-all duration-150
                            {{ $isModuleEnabled ? 'border-[#185FA5]/30 bg-[#185FA5]/5' : 'border-slate-200 bg-white hover:bg-slate-50' }}">
                            <div>
                                <div class="text-sm font-medium text-slate-800">{{  strtoupper($module) }}</div>
                                <div class="text-xs text-slate-500">Allow role to access this module</div>
                            </div>

                            <input
                                type="checkbox"
                                wire:click="toggleModuleAccess('{{ $module }}')"
                                :checked="{{ $isModuleEnabled ? 'true' : 'false' }}"
                                @disabled(! $canEdit)
                                class="w-5 h-5 rounded border-slate-300 text-[#185FA5] focus:ring-[#185FA5]/30 disabled:opacity-50"
                            />
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Permissions matrix --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-slate-700">Permissions (Grouped by Module)</h3>
                    <span class="text-xs text-slate-500">
                        {{ count($selectedPermissionIds) }} selected
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach ($permissionsByModule as $module => $items)
                        @php
                            $moduleSelectedCount = collect($items)->whereIn('id', $selectedPermissionIds)->count();
                            $moduleTotalCount = count($items);
                        @endphp
                        <div class="rounded-2xl border border-slate-200 p-4 bg-slate-50/60">
                            <div class="flex items-center justify-between mb-3">
                                <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                                    {{ strtoupper($module) }}
                                </div>
                                <span class="text-xs px-2 py-0.5 rounded-full {{ $moduleSelectedCount > 0 ? 'bg-[#185FA5]/10 text-[#185FA5]' : 'bg-slate-100 text-slate-500' }}">
                                    {{ $moduleSelectedCount }}/{{ $moduleTotalCount }}
                                </span>
                            </div>

                            <div class="space-y-2">
                                @foreach ($items as $perm)
                                    @php
                                        $isPermSelected = in_array($perm['id'], $selectedPermissionIds);
                                    @endphp
                                    <label class="flex items-center gap-3 bg-white border rounded-xl px-3 py-2 transition-all duration-150
                                        {{ $isPermSelected ? 'border-[#185FA5]/30 bg-[#185FA5]/5' : 'border-slate-100 hover:bg-slate-50' }}">
                                        <input
                                            type="checkbox"
                                            value="{{ $perm['id'] }}"
                                            wire:click="togglePermission({{ $perm['id'] }})"
                                            :checked="{{ $isPermSelected ? 'true' : 'false' }}"
                                            @disabled(! $canEdit)
                                            class="rounded border-slate-300 text-[#185FA5] focus:ring-[#185FA5]/30 disabled:opacity-50"
                                        />
                                        <span class="text-sm text-slate-700 {{ $isPermSelected ? 'font-medium text-[#185FA5]' : '' }}">
                                            {{ $perm['display_name'] }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                @if (! $canEdit)
                    <div class="mt-4 flex items-center gap-2 text-xs text-slate-500">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM7 9a1 1 0 000 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/>
                        </svg>
                        Only Super Admin can modify permissions and module access.
                    </div>
                @endif
            </div>

        </div>
    </div>

    {{-- CREATE ROLE MODAL --}}
    @if ($showCreateRole)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('showCreateRole', false)">
            <div class="bg-white w-full max-w-lg rounded-2xl shadow-lg p-6 m-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-slate-800">Create New Role</h2>
                    <button wire:click="$set('showCreateRole', false)" class="text-slate-500 hover:text-slate-800 text-xl">&times;</button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Role Slug</label>
                        <input type="text" wire:model.defer="newRoleSlug"
                               placeholder="e.g. finance_manager"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-[#185FA5] focus:ring-1 focus:ring-[#185FA5]/30">
                        @error('newRoleSlug') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Display Name</label>
                        <input type="text" wire:model.defer="newRoleDisplayName"
                               placeholder="Finance Manager"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-[#185FA5] focus:ring-1 focus:ring-[#185FA5]/30">
                        @error('newRoleDisplayName') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Description (optional)</label>
                        <textarea wire:model.defer="newRoleDescription"
                                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-[#185FA5] focus:ring-1 focus:ring-[#185FA5]/30"
                                  rows="3"
                                  placeholder="What this role is for..."></textarea>
                        @error('newRoleDescription') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button wire:click="$set('showCreateRole', false)"
                                class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 text-sm font-medium">
                            Cancel
                        </button>

                        <button wire:click="createRole"
                                class="px-4 py-2 rounded-lg bg-[#185FA5] text-white hover:bg-[#185FA5]/90 text-sm font-medium">
                            Create Role
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showEditRole)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('showEditRole', false)">
            <div class="bg-white w-full max-w-lg rounded-2xl shadow-lg p-6 m-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-slate-800">Edit Role</h2>
                    <button wire:click="$set('showEditRole', false)" class="text-slate-500 hover:text-slate-800 text-xl">&times;</button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Display Name</label>
                        <input wire:model.defer="editRoleDisplayName"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-[#185FA5] focus:ring-1 focus:ring-[#185FA5]/30" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
                        <textarea wire:model.defer="editRoleDescription"
                                  class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-[#185FA5] focus:ring-1 focus:ring-[#185FA5]/30"
                                  rows="4"></textarea>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button wire:click="$set('showEditRole', false)"
                                class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 text-sm font-medium">
                            Cancel
                        </button>
                        <button wire:click="updateRole"
                                class="px-4 py-2 rounded-lg bg-[#185FA5] text-white hover:bg-[#185FA5]/90 text-sm font-medium">
                            Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showDeleteRole)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('showDeleteRole', false)">
            <div class="bg-white w-full max-w-md rounded-2xl shadow-lg p-6 m-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-slate-800">Delete Role</h2>
                    <button wire:click="$set('showDeleteRole', false)" class="text-slate-500 hover:text-slate-800 text-xl">&times;</button>
                </div>

                <div class="space-y-4">
                    <div class="flex items-start gap-3 p-3 bg-red-50 border border-red-200 rounded-lg">
                        <svg class="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <div>
                            <p class="text-sm text-red-800 font-medium">This action cannot be undone.</p>
                            <p class="text-xs text-red-600 mt-1">All users with this role will need to be reassigned.</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">
                            Type <strong class="text-red-600">DELETE</strong> to confirm
                        </label>
                        <input wire:model.defer="deleteConfirmText"
                               placeholder="Type DELETE to confirm"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-500/30" />
                        @error('deleteConfirmText') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button wire:click="$set('showDeleteRole', false)"
                                class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 text-sm font-medium">
                            Cancel
                        </button>
                        <button wire:click="deleteRole"
                                class="px-4 py-2 rounded-lg bg-red-600 text-white hover:bg-red-700 text-sm font-medium">
                            Delete Role
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
