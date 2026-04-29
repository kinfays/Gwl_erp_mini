<div>
    <div class="page-head">
        <div class="ph-left">
            <h2>Leave Reports</h2>
            <p>Download approved leave summaries for your visible scope.</p>
        </div>
    </div>

    <div class="pg">
        <div class="pg-head">
            <span class="pg-title">Monthly Leave Summary</span>

            <select wire:model="format" class="form-input" style="width:140px">
                <option value="xlsx">Excel (.xlsx)</option>
                <option value="csv">CSV</option>
            </select>
        </div>

        <div style="padding:14px">
            <button wire:click="export" class="btn btn-primary">
                Export Report
            </button>
        </div>
    </div>
</div>
