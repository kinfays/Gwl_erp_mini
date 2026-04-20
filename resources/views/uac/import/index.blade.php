<x-uac-layout>
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-8 max-w-3xl">
    <div class="mb-6">
        <h2 class="text-lg font-semibold text-slate-700">Bulk Import Workspace</h2>
        <p class="text-sm text-slate-600 mt-2">Prepare CSV or Excel uploads for users, role assignments, and access structures. This Phase 2 screen provides the guided import entry point.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="rounded-2xl border border-slate-100 p-5 bg-slate-50">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-2">Step 1</p>
            <h3 class="text-sm font-semibold text-slate-900">Download Template</h3>
            <p class="text-sm text-slate-600 mt-2">Use the approved sheet structure for consistent imports.</p>
        </div>
        <div class="rounded-2xl border border-slate-100 p-5 bg-slate-50">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-2">Step 2</p>
            <h3 class="text-sm font-semibold text-slate-900">Validate Mapping</h3>
            <p class="text-sm text-slate-600 mt-2">Check headers, required fields, and role/module references.</p>
        </div>
        <div class="rounded-2xl border border-slate-100 p-5 bg-slate-50">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-2">Step 3</p>
            <h3 class="text-sm font-semibold text-slate-900">Run Secure Import</h3>
            <p class="text-sm text-slate-600 mt-2">Track imported rows and audit every access-related change.</p>
        </div>
    </div>

    <div class="border-2 border-dashed border-slate-200 rounded-2xl p-10 text-center bg-slate-50/70">
        <div class="w-14 h-14 rounded-2xl bg-[#185FA5]/10 flex items-center justify-center mx-auto mb-4">
            <svg class="w-7 h-7 text-[#185FA5]" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        </div>
        <h3 class="text-lg font-semibold text-slate-900">Import tooling placeholder</h3>
        <p class="text-sm text-slate-600 mt-2 max-w-xl mx-auto">The visual shell is ready for the next phase where upload validation, previews, and import execution will be connected.</p>
        <div class="mt-6 flex items-center justify-center gap-3">
            <button class="bg-[#185FA5] hover:bg-[#185FA5]/90 text-white font-medium px-4 py-2 rounded-lg shadow-sm transition-all duration-150">Download Template</button>
            <button class="bg-white hover:bg-slate-50 text-slate-700 font-medium px-4 py-2 rounded-lg border border-slate-200 transition-all duration-150">View Guidelines</button>
        </div>
    </div>
</div>
</x-uac-layout>
