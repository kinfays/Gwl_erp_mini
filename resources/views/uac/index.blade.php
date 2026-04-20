<x-uac-layout>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-6">
    <div class="bg-white rounded-2xl p-6 border border-slate-100 shadow-sm">
        <div class="w-10 h-10 rounded-xl bg-[#185FA5]/10 flex items-center justify-center mb-4">
            <svg class="w-5 h-5 text-[#185FA5]" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        </div>
        <p class="text-sm text-slate-500 font-medium">Total Users</p>
        <p class="text-3xl font-bold text-slate-900 mt-1">{{ $stats['users'] }}</p>
        <p class="text-xs font-medium text-emerald-600 mt-2">↑ Active directory visibility</p>
    </div>
    <div class="bg-white rounded-2xl p-6 border border-slate-100 shadow-sm">
        <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center mb-4">
            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        </div>
        <p class="text-sm text-slate-500 font-medium">Roles</p>
        <p class="text-3xl font-bold text-slate-900 mt-1">{{ $stats['roles'] }}</p>
        <p class="text-xs font-medium text-slate-500 mt-2">System and operational roles</p>
    </div>
    <div class="bg-white rounded-2xl p-6 border border-slate-100 shadow-sm">
        <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center mb-4">
            <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        </div>
        <p class="text-sm text-slate-500 font-medium">Permissions</p>
        <p class="text-3xl font-bold text-slate-900 mt-1">{{ $stats['permissions'] }}</p>
        <p class="text-xs font-medium text-slate-500 mt-2">Granular actions across modules</p>
    </div>
    <div class="bg-white rounded-2xl p-6 border border-slate-100 shadow-sm">
        <div class="w-10 h-10 rounded-xl bg-red-50 flex items-center justify-center mb-4">
            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
        </div>
        <p class="text-sm text-slate-500 font-medium">Audit Logs</p>
        <p class="text-3xl font-bold text-slate-900 mt-1">{{ $stats['audit_logs'] }}</p>
        <p class="text-xs font-medium text-slate-500 mt-2">Tracked security and user events</p>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="text-lg font-semibold text-slate-700">Recent Users</h2>
                <p class="text-sm text-slate-600">Latest accounts added to the platform.</p>
            </div>
            <a href="{{ route('uac.users') }}" class="bg-white hover:bg-slate-50 text-slate-700 font-medium px-4 py-2 rounded-lg border border-slate-200 transition-all duration-150">View all</a>
        </div>
        <div class="space-y-4">
            @forelse ($recentUsers as $user)
                <div class="flex items-center justify-between rounded-2xl border border-slate-100 p-4">
                    <div>
                        <p class="text-sm font-semibold text-slate-900">{{ $user->full_name ?? $user->name }}</p>
                        <p class="text-sm text-slate-600">{{ $user->email }}</p>
                    </div>
                    <span class="bg-slate-100 text-slate-600 border border-slate-200 px-2.5 py-0.5 rounded-full text-xs font-medium">{{ $user->roles->pluck('display_name')->join(', ') ?: 'No Role' }}</span>
                </div>
            @empty
                <p class="text-sm text-slate-600">No users available.</p>
            @endforelse
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="text-lg font-semibold text-slate-700">Recent Audit Activity</h2>
                <p class="text-sm text-slate-600">Latest tracked actions across protected modules.</p>
            </div>
            <a href="{{ route('uac.audit-log') }}" class="bg-white hover:bg-slate-50 text-slate-700 font-medium px-4 py-2 rounded-lg border border-slate-200 transition-all duration-150">Open log</a>
        </div>
        <div class="space-y-4">
            @forelse ($recentLogs as $log)
                <div class="rounded-2xl border border-slate-100 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">{{ $log->action }}</p>
                            <p class="text-sm text-slate-600 mt-1">{{ $log->user?->full_name ?? 'System' }} • {{ $log->module ?: 'general' }}</p>
                        </div>
                        <span class="bg-blue-50 text-blue-700 border border-blue-200 px-2.5 py-0.5 rounded-full text-xs font-medium">{{ $log->created_at?->diffForHumans() }}</span>
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-600">No audit logs found.</p>
            @endforelse
        </div>
    </div>
</div>
</x-uac-layout>
