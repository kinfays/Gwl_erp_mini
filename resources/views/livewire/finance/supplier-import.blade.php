<div>
    <x-ui.page-header title="Import suppliers" description="Add many suppliers at once from an Excel workbook. Every row is checked exactly as if you had typed it; a supplier already on the list is skipped, never changed.">
        <x-slot:actions>
            <x-ui.button icon="arrow-left" :href="route('finance.suppliers')">Suppliers</x-ui.button>
            <x-ui.button :href="route('finance.suppliers.import.template')" icon="download">Download the template</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="1. Choose the file" :description="'An .xlsx workbook made from the template, up to '.number_format($maxRows).' rows. Nothing is saved until you confirm.'" class="dash-row">
        <x-ui.field label="Workbook" for="f-fin-supplier-import" error="file">
            <input id="f-fin-supplier-import" type="file" class="form-input" wire:model="file" accept=".xlsx">
        </x-ui.field>
        <div class="ui-form-actions">
            <x-ui.button variant="primary" icon="check" wire:click="previewFile" loading="previewFile">Check the file</x-ui.button>
            @if ($file || $preview || $outcome)
                <x-ui.button wire:click="clear">Start again</x-ui.button>
            @endif
        </div>
    </x-ui.card>

    @php($result = $outcome ?: $preview)

    @if ($result)
        <x-ui.card :title="$outcome ? ($outcome['committed'] ? 'Imported' : 'Not imported') : '2. What the check found'" class="dash-row">
            <div class="ui-stat-grid">
                <x-ui.stat-tile label="Rows in the file" :value="number_format($result['total_rows'])" icon="table" tone="muted" />
                <x-ui.stat-tile label="New suppliers" :value="number_format($result['valid_count'])" icon="circle-check" tone="success" />
                <x-ui.stat-tile label="Already on the list" :value="number_format($result['skipped_count'])" icon="info" tone="muted" />
                <x-ui.stat-tile label="Look-alikes" :value="number_format($result['warning_count'])" icon="triangle-alert" :tone="$result['warning_count'] ? 'warning' : 'muted'" />
                <x-ui.stat-tile label="Problems" :value="number_format($result['error_count'])" icon="circle-alert" :tone="$result['error_count'] ? 'danger' : 'muted'" />
                @if ($outcome)
                    <x-ui.stat-tile label="Imported" :value="number_format($result['imported'])" icon="check" :tone="$result['committed'] ? 'success' : 'muted'" />
                @endif
            </div>

            @if ($result['errors'])
                <h3 class="ui-panel-title" style="margin-top:16px">Rows that cannot be imported</h3>
                <ul class="import-issue-list">
                    @foreach ($result['errors'] as $error)
                        <li><x-ui.icon name="circle-alert" /><span><strong>{{ $error['row'] !== '' ? 'Row '.$error['row'] : 'File' }}{{ $error['name'] !== '' ? ' ('.$error['name'].')' : '' }}:</strong> {{ $error['message'] }}</span></li>
                    @endforeach
                    @if ($result['more_errors'])
                        <li><span>... and {{ $result['more_errors'] }} more.</span></li>
                    @endif
                </ul>
            @endif

            @if ($result['warnings'])
                <h3 class="ui-panel-title" style="margin-top:16px">Names that look like a supplier already listed</h3>
                <ul class="import-issue-list">
                    @foreach ($result['warnings'] as $warning)
                        <li><x-ui.icon name="triangle-alert" /><span><strong>Row {{ $warning['row'] }} ({{ $warning['name'] }}):</strong> {{ $warning['message'] }}</span></li>
                    @endforeach
                    @if ($result['more_warnings'])
                        <li><span>... and {{ $result['more_warnings'] }} more.</span></li>
                    @endif
                </ul>
            @endif

            @if ($result['skipped'])
                <h3 class="ui-panel-title" style="margin-top:16px">Skipped: already on the list</h3>
                <ul class="import-issue-list">
                    @foreach ($result['skipped'] as $skipped)
                        <li><x-ui.icon name="info" /><span><strong>Row {{ $skipped['row'] }} ({{ $skipped['name'] }}):</strong> {{ $skipped['message'] }}</span></li>
                    @endforeach
                    @if ($result['more_skipped'])
                        <li><span>... and {{ $result['more_skipped'] }} more.</span></li>
                    @endif
                </ul>
            @endif

            @if ($preview && ! $preview['blocked'])
                <div class="ui-form-actions">
                    @if ($preview['error_count'] === 0)
                        <x-ui.button variant="primary" icon="check" wire:click="runImport" wire:confirm="Add {{ $preview['valid_count'] }} new suppliers to the company-wide list? A supplier is never deleted afterwards, only switched off." loading="runImport">Import {{ $preview['valid_count'] }} new suppliers</x-ui.button>
                    @else
                        <p class="cell-muted">Nothing is imported while there are problems, unless you choose to import only the rows that are fine.</p>
                        @if ($preview['valid_count'] > 0)
                            <x-ui.button variant="primary" wire:click="runImportValidOnly" wire:confirm="Import the {{ $preview['valid_count'] }} valid rows and leave the {{ $preview['error_count'] }} problem rows out?" loading="runImportValidOnly">Import the {{ $preview['valid_count'] }} valid rows only</x-ui.button>
                        @endif
                    @endif
                </div>
            @endif
        </x-ui.card>
    @endif

    <x-ui.card title="Columns" description="Do not rename the sheet or the headings. Only the name is required; leave the others empty if you do not have them." class="dash-row">
        <p><strong>Suppliers</strong>: <span class="mono">{{ implode(', ', $headings) }}</span></p>
    </x-ui.card>
</div>
