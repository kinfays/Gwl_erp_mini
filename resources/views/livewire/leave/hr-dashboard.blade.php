<div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold">HR Dashboard</h2>
        <p class="text-sm text-slate-600">Leave module overview for your scope.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white border rounded-xl p-4">
            <p class="text-xs text-slate-500">Pending Requests</p>
            <p class="text-2xl font-bold">{{ $pendingCount }}</p>
        </div>

        <div class="bg-white border rounded-xl p-4">
            <p class="text-xs text-slate-500">Approved This Month</p>
            <p class="text-2xl font-bold">{{ $approvedThisMonth }}</p>
        </div>

        <div class="bg-white border rounded-xl p-4">
            <p class="text-xs text-slate-500">Denied This Month</p>
            <p class="text-2xl font-bold">{{ $deniedThisMonth }}</p>
        </div>
    </div>

    <div class="bg-white border rounded-xl p-4 text-slate-500">
        Pending approvals table + charts (ApexCharts) will be added next.
    </div>
</div>