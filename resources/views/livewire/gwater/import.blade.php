<div>
    <x-ui.page-header title="Import past records" description="Bring in your earlier ledger lines once: opening balance, purchases, sales, damages, expenses and payments. Every row is checked and posted exactly as if you had typed it.">
        <x-slot:actions>
            <x-ui.button :href="route('gwater.import.template')" icon="download">Download the template</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.gwater.partials.location-picker')

    @if ($location)
        <x-ui.card title="1. Choose the file" :description="'An .xlsx workbook made from the template, up to '.number_format($maxRows).' rows in all. Nothing is overwritten: a row that already exists is refused.'" class="dash-row">
            <x-ui.field label="Workbook" for="f-gw-import" error="file">
                <input id="f-gw-import" type="file" class="form-input" wire:model="file" accept=".xlsx">
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
                    <x-ui.stat-tile label="Ready" :value="number_format($result['valid_count'])" icon="circle-check" tone="success" />
                    <x-ui.stat-tile label="Problems" :value="number_format($result['error_count'])" icon="triangle-alert" :tone="$result['error_count'] ? 'danger' : 'muted'" />
                    @if ($outcome)
                        <x-ui.stat-tile label="Imported" :value="number_format($result['imported'])" icon="check" :tone="$result['committed'] ? 'success' : 'muted'" />
                    @endif
                </div>

                @if ($result['sheets'])
                    <x-ui.table label="Rows per sheet" :sticky="false" dense>
                        <x-slot:head><tr><th>Sheet</th><th class="num">Rows</th><th class="num">Ready</th><th class="num">Problems</th></tr></x-slot:head>
                        @foreach ($result['sheets'] as $sheet)
                            <tr><td>{{ $sheet['title'] }}</td><td class="num">{{ $sheet['rows'] }}</td><td class="num">{{ $sheet['valid'] }}</td><td class="num">{{ $sheet['errors'] }}</td></tr>
                        @endforeach
                    </x-ui.table>
                @endif

                @if ($result['errors'])
                    <h3 class="ui-panel-title" style="margin-top:16px">Rows that cannot be imported</h3>
                    <ul class="import-issue-list">
                        @foreach ($result['errors'] as $error)
                            <li><x-ui.icon name="circle-alert" /><span><strong>{{ $error['sheet'] }}{{ $error['row'] !== '' ? ', row '.$error['row'] : '' }}:</strong> {{ $error['message'] }}</span></li>
                        @endforeach
                        @if ($result['more_errors'])
                            <li><span>... and {{ $result['more_errors'] }} more.</span></li>
                        @endif
                    </ul>
                @endif

                @if ($preview && ! $preview['blocked'])
                    <div class="ui-form-actions">
                        @if ($preview['error_count'] === 0)
                            <x-ui.button variant="primary" icon="check" wire:click="runImport" wire:confirm="Import all {{ $preview['valid_count'] }} rows? They are posted to your books and can only be undone by voiding each one." loading="runImport">Import all {{ $preview['valid_count'] }} rows</x-ui.button>
                        @else
                            <p class="cell-muted">Nothing is imported while there are problems, unless you choose to import only the rows that are fine.</p>
                            @if ($preview['valid_count'] > 0)
                                <x-ui.button variant="primary" wire:click="runImportValidOnly" wire:confirm="Import the {{ $preview['valid_count'] }} valid rows and leave the {{ $preview['error_count'] }} problem rows out?" loading="runImport">Import the {{ $preview['valid_count'] }} valid rows only</x-ui.button>
                            @endif
                        @endif
                    </div>
                @endif
            </x-ui.card>
        @endif

        <x-ui.card title="Columns" description="Do not rename the sheets or the headings. Dates are dd/MM/yyyy; amounts are in cedis." class="dash-row">
            @foreach ($sheets as $key => $sheet)
                <p><strong>{{ $sheet['title'] }}</strong>: <span class="mono">{{ implode(', ', $headings[$key]) }}</span></p>
            @endforeach
        </x-ui.card>
    @endif
</div>
