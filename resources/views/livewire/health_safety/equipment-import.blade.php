<div>
    <x-ui.page-header title="Import equipment" description="Bring an existing register in from Excel. Rows are added; nothing already in the system is changed.">
        <x-slot:actions>
            <x-ui.button :href="route('health_safety.equipment-import.template', 'extinguishers')" icon="download">Extinguisher template</x-ui.button>
            <x-ui.button :href="route('health_safety.equipment-import.template', 'kits')" icon="download">Kit template</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="1. Choose the file" class="dash-row">
        <div class="ui-stack">
            <fieldset class="chip-group">
                <legend class="ui-label">What does the file hold?</legend>
                <div class="chip-options">
                    <label class="chip-option"><input type="radio" name="kind" value="extinguishers" wire:model.live="kind"><span>Fire extinguishers</span></label>
                    <label class="chip-option"><input type="radio" name="kind" value="kits" wire:model.live="kind"><span>First aid kits</span></label>
                </div>
            </fieldset>

            <x-ui.field label="Excel file (.xlsx or .csv)" for="hs-import-file" error="file" hint="Columns: {{ implode(', ', $headings) }}. Dates as dd/MM/yyyy or real Excel dates. Sites must already exist under Sites. At most {{ $maxRows }} rows.">
                <input id="hs-import-file" type="file" class="form-input" wire:model="file" accept=".xlsx,.csv,.txt">
            </x-ui.field>

            <div class="ui-form-actions">
                <x-ui.button wire:click="previewFile" loading="previewFile,file" icon="eye">Preview</x-ui.button>
                @if ($preview !== [] && ! $preview['blocked'] && $preview['valid_count'] > 0)
                    <x-ui.button variant="primary" wire:click="runImport" loading="runImport" icon="upload">Import {{ $preview['valid_count'] }} rows</x-ui.button>
                @endif
                @if ($preview !== [])<x-ui.button variant="ghost" wire:click="clear">Clear</x-ui.button>@endif
            </div>
        </div>
    </x-ui.card>

    @if ($preview !== [])
        <x-ui.card title="2. Check the result" class="dash-row">
            <div class="ui-stat-grid">
                <x-ui.stat-tile label="Rows read" :value="$preview['total_rows']" icon="file-spreadsheet" tone="primary" />
                <x-ui.stat-tile label="Ready to import" :value="$preview['valid_count']" icon="circle-check" tone="success" />
                <x-ui.stat-tile label="Rows with errors" :value="$preview['error_rows']" icon="circle-alert" :tone="$preview['error_rows'] > 0 ? 'danger' : 'muted'" :meta="$preview['failure_percent'].'% (limit '.$preview['max_failure_percent'].'%)'" />
                <x-ui.stat-tile label="Skipped, already exist" :value="$preview['skipped_count']" icon="archive" tone="muted" />
            </div>

            @if ($preview['blocked'])
                <x-ui.alert tone="danger" class="dash-row" role="alert">
                    @if ($preview['total_rows'] === 0 || $preview['valid_count'] === 0 && $preview['error_rows'] === 0)
                        This file cannot be imported. See the errors below.
                    @else
                        Import blocked: {{ $preview['failure_percent'] }}% of rows have errors, more than the {{ $preview['max_failure_percent'] }}% allowed. Fix the file and upload it again.
                    @endif
                </x-ui.alert>
            @endif

            @if ($preview['unmatched_sites'] !== [])
                <x-ui.alert tone="warning" title="Sites not found" class="dash-row">
                    Add these under <a href="{{ route('health_safety.sites') }}">Sites</a>, then upload the file again: {{ implode(', ', $preview['unmatched_sites']) }}.
                </x-ui.alert>
            @endif

            @if ($preview['errors'] !== [])
                <h3 class="ui-label" style="margin-top:14px">Errors ({{ count($preview['errors']) }})</h3>
                <ul class="ui-stack">
                    @foreach (array_slice($preview['errors'], 0, 50) as $error)
                        <li wire:key="hs-imp-err-{{ $loop->index }}"><strong>Row {{ $error['row'] }}:</strong> {{ $error['message'] }}</li>
                    @endforeach
                    @if (count($preview['errors']) > 50)<li class="cell-muted">and {{ count($preview['errors']) - 50 }} more.</li>@endif
                </ul>
            @endif

            @if ($preview['warnings'] !== [])
                <h3 class="ui-label" style="margin-top:14px">Warnings ({{ count($preview['warnings']) }})</h3>
                <ul class="ui-stack">
                    @foreach (array_slice($preview['warnings'], 0, 50) as $warning)
                        <li wire:key="hs-imp-warn-{{ $loop->index }}"><strong>Row {{ $warning['row'] }}:</strong> {{ $warning['message'] }}</li>
                    @endforeach
                    @if (count($preview['warnings']) > 50)<li class="cell-muted">and {{ count($preview['warnings']) - 50 }} more.</li>@endif
                </ul>
            @endif
        </x-ui.card>
    @endif
</div>
