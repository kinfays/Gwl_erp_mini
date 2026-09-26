<x-uac-layout>
<div x-data="auditLogViewer()" x-on:keydown.escape.window="close()">

<div class="bg-white rounded-2xl shadow-xs border border-slate-100 p-6 mb-6 dark:bg-slate-900 dark:border-slate-700">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <form method="GET" class="flex-1 max-w-xl">
            <label class="block text-sm font-medium text-slate-700 mb-1.5 dark:text-slate-200">Search audit log</label>
            <div class="flex gap-3">
                <input type="text" name="search" value="{{ $search }}" placeholder="Search by action, module, target, or IP address" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 placeholder-slate-400 focus:outline-hidden focus:ring-2 focus:ring-[#185FA5]/20 focus:border-[#185FA5] transition-all duration-150 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500">
                <button type="submit" class="bg-[#185FA5] hover:bg-[#185FA5]/90 text-white font-medium px-4 py-2 rounded-lg shadow-xs transition-all duration-150">Search</button>
            </div>
        </form>
    </div>
</div>

<div class="bg-white rounded-2xl shadow-xs overflow-hidden border border-slate-100 dark:bg-slate-900 dark:border-slate-700">
    <table class="min-w-full">
        <thead class="bg-slate-50 border-b border-slate-200 dark:bg-slate-800 dark:border-slate-700">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider dark:text-slate-300">Action</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider dark:text-slate-300">User</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider dark:text-slate-300">Module</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider dark:text-slate-300">Target</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider dark:text-slate-300">IP Address</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider dark:text-slate-300">Date</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider dark:text-slate-300">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                @php
                    $target = trim(($log->target_type ?: '-') . ($log->target_id ? ' #' . $log->target_id : ''));
                    $viewerPayload = [
                        'id' => $log->id,
                        'action' => $log->action,
                        'user' => $log->user?->hasRoles('super_admin') ? 'System' : ($log->user?->full_name ?? $log->user_name ?? 'System'),
                        'module' => strtoupper($log->module ?: 'general'),
                        'target' => $target,
                        'ip_address' => $log->ip_address ?: '-',
                        'date' => $log->created_at?->format('d M Y, h:i A') ?: '-',
                        'old_values' => $log->old_values,
                        'new_values' => $log->new_values,
                        'metadata' => $log->metadata,
                    ];
                @endphp
                <tr class="border-b border-slate-100 hover:bg-slate-50 transition-colors duration-100 dark:border-slate-700 dark:hover:bg-slate-800/70">
                    <td class="px-4 py-3.5 text-sm text-slate-700 dark:text-slate-200">
                        <span class="bg-slate-100 text-slate-600 border border-slate-200 px-2.5 py-0.5 rounded-full text-xs font-medium dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700">{{ $log->action }}</span>
                    </td>
                    <td class="px-4 py-3.5 text-sm text-slate-700 dark:text-slate-200">{{ $viewerPayload['user'] }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700 dark:text-slate-200">{{ $viewerPayload['module'] }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700 dark:text-slate-200">{{ $target }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700 dark:text-slate-200">{{ $viewerPayload['ip_address'] }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700 dark:text-slate-200">{{ $viewerPayload['date'] }}</td>
                    <td class="px-4 py-3.5 text-sm text-slate-700 dark:text-slate-200">
                        <div class="flex gap-2 items-center">
                            <button type="button" x-on:click="open(@js($viewerPayload))" class="p-2 rounded-lg border border-transparent hover:bg-slate-100 text-slate-500 hover:text-slate-700 transition-all duration-150 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white" title="View audit details" aria-label="View audit details">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500 dark:text-slate-400">No audit records found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-6">
    {{ $logs->links() }}
</div>

<div x-cloak x-show="isOpen" class="fixed inset-0 z-50 flex items-center justify-center px-4 py-6 sm:px-6" role="dialog" aria-modal="true" aria-labelledby="audit-log-title">
    <div x-show="isOpen" x-transition.opacity class="absolute inset-0 bg-slate-950/60" x-on:click="close()"></div>

    <div x-show="isOpen" x-transition class="relative w-full max-w-3xl max-h-[90vh] overflow-hidden rounded-2xl bg-white shadow-2xl border border-slate-200 dark:bg-slate-900 dark:border-slate-700">
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4 dark:border-slate-700">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-[#185FA5] dark:text-sky-300">Audit Log</p>
                <h2 id="audit-log-title" class="mt-1 text-lg font-semibold text-slate-900 dark:text-white" x-text="selected.action || 'Audit details'"></h2>
            </div>
            <button type="button" x-on:click="close()" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white" aria-label="Close audit details">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="overflow-y-auto px-6 py-5 max-h-[calc(90vh-88px)]">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/70">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">User</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-white" x-text="selected.user || '-'"></dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/70">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Module</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-white" x-text="selected.module || '-'"></dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/70">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Target</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-white" x-text="selected.target || '-'"></dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/70">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">IP Address</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-white" x-text="selected.ip_address || '-'"></dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 sm:col-span-2 dark:border-slate-700 dark:bg-slate-800/70">
                    <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Date</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900 dark:text-white" x-text="selected.date || '-'"></dd>
                </div>
            </dl>

            <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-950">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Old Values</h3>
                    <pre class="mt-3 max-h-64 overflow-auto rounded-lg bg-slate-950 p-3 text-xs leading-relaxed text-slate-100" x-text="pretty(selected.old_values)"></pre>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-950">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">New Values</h3>
                    <pre class="mt-3 max-h-64 overflow-auto rounded-lg bg-slate-950 p-3 text-xs leading-relaxed text-slate-100" x-text="pretty(selected.new_values)"></pre>
                </div>
            </div>

            <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-950">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Metadata</h3>
                <pre class="mt-3 max-h-64 overflow-auto rounded-lg bg-slate-950 p-3 text-xs leading-relaxed text-slate-100" x-text="pretty(selected.metadata)"></pre>
            </div>
        </div>
    </div>
</div>
</div>

<script>
    function auditLogViewer() {
        return {
            isOpen: false,
            selected: {},
            open(log) {
                this.selected = log || {};
                this.isOpen = true;
            },
            close() {
                this.isOpen = false;
            },
            pretty(value) {
                if (!this.hasData(value)) {
                    return 'None recorded';
                }

                return JSON.stringify(value, null, 2);
            },
            hasData(value) {
                if (value === null || value === undefined || value === '') {
                    return false;
                }

                if (Array.isArray(value)) {
                    return value.length > 0;
                }

                if (typeof value === 'object') {
                    return Object.keys(value).length > 0;
                }

                return true;
            },
        };
    }
</script>
</x-uac-layout>
