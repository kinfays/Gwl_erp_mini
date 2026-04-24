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
``