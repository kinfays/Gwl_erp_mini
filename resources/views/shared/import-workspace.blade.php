@php
    $routePrefix = $context;
    $preview = session('import_preview.' . $context);
    $types = $availableImportTypes ?? app(\App\Services\Import\DataImportService::class)->availableTypes($showUsersType ?? true);
    $currentStep = $preview && ! empty($preview['valid_rows']) && empty($preview['blocked']) ? 3 : 2;
    $steps = [
        1 => ['Template', 'Download the approved sheet structure.'],
        2 => ['Preview', 'Validate the uploaded rows before writing to the database.'],
        3 => ['Run Import', 'Persist only the validated rows and audit the result.'],
    ];
@endphp

<div x-data="{
    selectedType: '{{ $defaultType ?? ($types[0]['type'] ?? 'employees') }}',
    fileName: '',
    isDragging: false,
    chooseFile() {
        this.$refs.fileInput.click();
    },
    handleDrop(event) {
        this.isDragging = false;

        const files = event.dataTransfer?.files;
        if (! files || ! files.length) {
            return;
        }

        try {
            const transfer = new DataTransfer();
            transfer.items.add(files[0]);
            this.$refs.fileInput.files = transfer.files;
        } catch (error) {
            this.$refs.fileInput.files = files;
        }

        this.fileName = files[0].name;
    },
}">
    <x-ui.page-header :title="$title" :description="$description" />

    {{-- The UAC layout already shows the success flash. --}}
    @if (session('success') && $context !== 'uac')
        <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
    @endif

    @if ($errors->any())
        <x-ui.alert tone="danger" role="alert">
            {{ $errors->first() }}
            <x-slot:actions>
                <form action="{{ route($routePrefix . '.import.clear') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Clear Upload Error</button>
                </form>
            </x-slot:actions>
        </x-ui.alert>
    @endif

    <ol class="ui-steps" aria-label="Import steps">
        @foreach ($steps as $number => [$stepTitle, $stepDescription])
            <li @if ($number === $currentStep) aria-current="step" @endif>
                <span class="ui-step-num" aria-hidden="true">{{ $number }}</span>
                <span>
                    <span class="ui-step-title"><span class="sr-only-text">Step {{ $number }}: </span>{{ $stepTitle }}</span>
                    <span class="ui-step-desc">{{ $stepDescription }}</span>
                </span>
            </li>
        @endforeach
    </ol>

    <div class="ui-grid ui-grid-main dash-row">
        <x-ui.card title="Upload Workspace" description="Pick the data type, fill in its template, then preview the file.">
            <div class="ui-stack">
                <div class="import-type-row">
                    <div class="ui-field toolbar-grow">
                        <label for="import-type-{{ $context }}" class="ui-label">Import Type</label>
                        <select id="import-type-{{ $context }}" x-model="selectedType" name="type" class="form-input ui-input">
                            @foreach ($types as $type)
                                <option value="{{ $type['type'] }}">{{ $type['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <a :href="`{{ url($routePrefix . '/import/template') }}/${selectedType}`" class="btn btn-secondary" x-on:click.stop>
                        <x-ui.icon name="download" />
                        Download Template
                    </a>
                </div>

                <div
                    class="import-zone"
                    :class="{ 'is-dragging': isDragging }"
                    role="button"
                    tabindex="0"
                    x-on:click="if (! $event.target.closest('a, button')) chooseFile()"
                    x-on:keydown.enter.prevent="chooseFile()"
                    x-on:keydown.space.prevent="chooseFile()"
                    x-on:dragover.prevent="isDragging = true"
                    x-on:dragleave.prevent="isDragging = false"
                    x-on:drop.prevent="handleDrop($event)"
                >
                    <span class="ui-empty-icon import-zone-icon" aria-hidden="true"><x-ui.icon name="upload" /></span>
                    <p class="import-zone-title">Drop Excel or CSV here, or choose a file</p>
                    <p class="import-zone-hint" x-text="fileName || 'Accepts .xlsx and .csv'">Accepts .xlsx and .csv</p>
                </div>

                <form action="{{ route($routePrefix . '.import.preview') }}" method="POST" enctype="multipart/form-data" class="ui-stack">
                    @csrf
                    <input type="hidden" name="type" x-bind:value="selectedType">

                    <div class="ui-field">
                        <label for="import-file-{{ $context }}" class="ui-label">Upload File</label>
                        <input id="import-file-{{ $context }}" x-ref="fileInput" x-on:change="fileName = $event.target.files[0]?.name || ''" type="file" name="file" class="form-input" accept=".xlsx,.csv,.txt">
                    </div>

                    <div>
                        <button type="submit" class="btn btn-primary">
                            <x-ui.icon name="list-checks" />
                            Preview Import
                        </button>
                    </div>
                </form>
            </div>
        </x-ui.card>

        <x-ui.card title="Supported Types" description="Each type has its own template columns." :padded="false">
            <div class="supported-types-list">
                @foreach ($types as $type)
                    <div class="supported-type-card">
                        <div class="supported-type-title">{{ $type['label'] }}</div>
                        <div class="supported-type-desc">{{ $type['description'] }}</div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>

    @if ($preview)
        @php
            $previewRows = $preview['preview_rows'] ?? [];
            $errorCount = (int) ($preview['error_count'] ?? 0);
        @endphp

        <x-ui.card
            title="Preview Results"
            :description="count($previewRows) ? 'The first '.count($previewRows).' of '.($preview['total_rows'] ?? count($previewRows)).' rows, as read from the file.' : null"
            :padded="false"
        >
            <x-slot:actions>
                @if (! empty($preview['errors']))
                    <form action="{{ route($routePrefix . '.import.clear') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-secondary">Clear Upload Error</button>
                    </form>
                @endif

                @if (! empty($preview['valid_rows']))
                    <form action="{{ route($routePrefix . '.import.run') }}" method="POST" x-data>
                        @csrf
                        <button
                            type="submit"
                            class="btn btn-primary"
                            @if (! empty($preview['blocked'])) disabled @endif
                            x-on:click.prevent="$dispatch('confirm-action', {
                                title: 'Run bulk import?',
                                message: 'Validated rows will be written to the database and audited.',
                                confirmLabel: 'Run Import',
                                variant: 'primary',
                                action: () => $root.submit()
                            })"
                        >
                            Run Import
                        </button>
                    </form>
                @endif
            </x-slot:actions>

            <div class="import-summary">
                <x-ui.status-pill tone="success" :label="$preview['valid_count'].' rows validated'" />
                <x-ui.status-pill :tone="$errorCount > 0 ? 'danger' : 'muted'" :label="$errorCount.' issues found'" />
                @if (! empty($preview['grade_missing_count']))
                    <x-ui.status-pill tone="warning" :label="$preview['grade_missing_count'].' grade missing'" />
                @endif
                <span class="ui-hint">{{ $preview['failure_percent'] ?? 0 }}% failure rate; max {{ $preview['max_failure_percent'] ?? config('gwl.max_import_failure_percent', 20) }}%</span>
            </div>

            @if (! empty($preview['blocked']))
                <div class="import-section">
                    <x-ui.alert tone="danger">Import is blocked because the validation failure rate is above the configured limit.</x-ui.alert>
                </div>
            @endif

            @if (! empty($previewRows))
                <x-ui.table label="Preview rows" :sticky="false">
                    <x-slot:head>
                        <tr>
                            @foreach (array_keys($previewRows[0]) as $heading)
                                <th>{{ Str::of($heading)->replace('_', ' ')->title() }}</th>
                            @endforeach
                        </tr>
                    </x-slot:head>

                    @foreach ($previewRows as $row)
                        <tr>
                            @foreach ($row as $value)
                                <td>{{ is_array($value) ? implode(', ', $value) : ($value !== '' && $value !== null ? $value : '-') }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif

            @if (! empty($preview['warnings']))
                <section class="import-issues" aria-labelledby="import-warnings-{{ $context }}">
                    <h3 id="import-warnings-{{ $context }}" class="ui-panel-title">Grade missing ({{ count($preview['warnings']) }})</h3>
                    <p class="ui-hint">These rows are valid and will be imported without a grade. Set their grade later from the staff list (filter: No grade set) or import the file again with the grade filled in.</p>
                    <ul class="import-issue-list">
                        @foreach ($preview['warnings'] as $warning)
                            <li>
                                <x-ui.icon name="triangle-alert" />
                                <span><strong>Row {{ $warning['row'] }}:</strong> {{ $warning['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (! empty($preview['errors']))
                <section class="import-issues" aria-labelledby="import-issues-{{ $context }}">
                    <h3 id="import-issues-{{ $context }}" class="ui-panel-title">Validation Issues</h3>
                    <ul class="import-issue-list">
                        @foreach ($preview['errors'] as $error)
                            <li>
                                <x-ui.icon name="circle-alert" />
                                <span><strong>{{ is_numeric($error['row']) ? 'Row '.$error['row'] : $error['row'] }}:</strong> {{ $error['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </x-ui.card>
    @endif
</div>
