
<x-uac-layout>
    <livewire:uac.role-access-manager />
</x-uac-layout>


{{--<x-uac-layout>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-700">Role matrix</h2>
            <p class="text-sm text-slate-600">Review system roles, linked permissions, and enabled modules.</p>
        </div>
        <button class="bg-[#185FA5] hover:bg-[#185FA5]/90 text-white font-medium px-4 py-2 rounded-lg shadow-sm transition-all duration-150">Add New</button>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
    @foreach ($roles as $role)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">{{ $role->display_name }}</h3>
                    <p class="text-sm text-slate-600 mt-1">{{ $role->description ?: 'System access role' }}</p>
                </div>
                <span class="{{ $role->is_system ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200' }} px-2.5 py-0.5 rounded-full text-xs font-medium">{{ $role->is_system ? 'System' : 'Custom' }}</span>
            </div>

            <div class="mb-5">
                <p class="block text-sm font-medium text-slate-700 mb-2">Enabled modules</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($role->moduleAccesses->where('can_access', true) as $moduleAccess)
                        <span class="bg-blue-50 text-blue-700 border border-blue-200 px-2.5 py-0.5 rounded-full text-xs font-medium">{{ strtoupper($moduleAccess->module) }}</span>
                    @endforeach
                </div>
            </div>

            <div>
                <p class="block text-sm font-medium text-slate-700 mb-2">Permissions</p>
                <div class="flex flex-wrap gap-2">
                    @forelse ($role->permissions as $permission)
                        <span class="bg-slate-100 text-slate-600 border border-slate-200 px-2.5 py-0.5 rounded-full text-xs font-medium">{{ $permission->display_name }}</span>
                    @empty
                        <span class="text-sm text-slate-500">No permissions assigned.</span>
                    @endforelse
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
    <h2 class="text-lg font-semibold text-slate-700 mb-6">Permissions by module</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
        @foreach ($permissions as $module => $items)
            <div class="rounded-2xl border border-slate-100 p-5 bg-slate-50/70">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-2">{{ $module }}</p>
                <h3 class="text-base font-semibold text-slate-900 mb-4">{{ strtoupper($module) }} Permissions</h3>
                <div class="space-y-2">
                    @foreach ($items as $permission)
                        <div class="bg-white rounded-xl border border-slate-100 px-3 py-2 text-sm text-slate-700">{{ $permission->display_name }}</div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>

</x-uac-layout> --}}
