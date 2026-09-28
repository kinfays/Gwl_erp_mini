<div>
    <x-ui.page-header title="Leave Reports" description="Download approved leave summaries for your visible scope." />

    <x-ui.card title="Monthly Leave Summary">
        <div class="report-export">
            <x-ui.select label="Format" wire:model="format" id="leave-report-format" class="report-format">
                <option value="xlsx">Excel (.xlsx)</option>
                <option value="csv">CSV</option>
            </x-ui.select>

            <x-ui.button variant="primary" icon="download" wire:click="export" loading="export">Export Report</x-ui.button>
        </div>
    </x-ui.card>
</div>
