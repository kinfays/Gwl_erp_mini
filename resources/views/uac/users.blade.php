@extends('layouts.uac')

@section('content')
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <form method="GET" class="flex-1 max-w-xl">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Search users</label>
            <div class="flex gap-3">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search by name, email, or staff ID" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-[#185FA5]/20 focus:border-[#185FA5] transition-all duration-150">
                <button type="submit" class="bg-[#185FA5] hover:bg-[#185FA5]/90 text-white font-medium px-4 py-2 rounded-lg shadow-sm transition-all duration-150">Search</button>
            </div>
        </form>
        <button class="bg-[#185FA5] hover:bg-[#185FA5]/90 text-white font-medium px-4 py-2 rounded-lg shadow-sm transition-all duration-150">Add New</button>
    </div>
</div>

<div class="bg-white rounded-2xl shadow-sm overflow-hidden border border-slate-100">
    <table class="min-w-full">
        <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
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
                            <button class="p-2 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-700 transition-all duration-150">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                            <button class="p-2 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-700 transition-all duration-150">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
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
@endsection
