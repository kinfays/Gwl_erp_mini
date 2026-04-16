@extends('layouts.uac')

@section('content')
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <form method="GET" class="flex-1 max-w-xl">
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Search audit log</label>
            <div class="flex gap-3">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search by action, module, target, or IP address" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-[#185FA5]/20 focus:border-[#185FA5] transition-all duration-150">
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
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Action</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">User</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Module</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Target</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">IP Address</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr class="border-b border-slate-100 hover:bg-slate-50 transition-colors duration-100">
                    <td class="px-4 py-3.5 text-sm text-slate-700">
                        <span class="bg-slate-100 text-slate-600 border border-slate-200 px-2.5 py-0.5 rounded-full text-xs font-medium">{{ $log->action }}</span>
                    </td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">{{ $log->user?->full_name ?? 'System' }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">{{ strtoupper($log->module ?: 'general') }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">{{ $log->target_type ?: '—' }} @if($log->target_id)#{{ $log->target_id }}@endif</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">{{ $log->ip_address ?: '—' }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">{{ $log->created_at?->format('d M Y, h:i A') }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700">
                        <div class="flex gap-2 items-center">
                            <button class="p-2 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-700 transition-all duration-150">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">No audit records found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-6">
    {{ $logs->links() }}
</div>
@endsection
