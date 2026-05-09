@php
    $routePrefix = $context;
    $preview = session('import_preview.' . $context);
    $types = $availableImportTypes ?? app(\App\Services\Import\DataImportService::class)->availableTypes($showUsersType ?? true);
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
    <div class="page-head" style="padding-left:0;padding-right:0;background:transparent;border:0">
        <div class="ph-left">
            <h2>{{ $title }}</h2>
            <p>{{ $description }}</p>
        </div>
    </div>

    @if (session('success'))
        <div class="erp-card" style="margin-bottom:14px;background:#eaf7ef;border-color:#b8e0c5;color:#21633c;">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="erp-card" style="margin-bottom:14px;background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
                <span>{{ $errors->first() }}</span>

                <form action="{{ route($routePrefix . '.import.clear') }}" method="POST">
                    @csrf
                    <button type="submit" class="actn actn-r">Clear Upload Error</button>
                </form>
            </div>
        </div>
    @endif

    <div class="stats" style="grid-template-columns:repeat(3,minmax(0,1fr))">
        <div class="stat">
            <div class="stat-lbl">Step 1</div>
            <div class="stat-val" style="font-size:16px">Template</div>
            <div class="stat-sub">Download the approved sheet structure.</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Step 2</div>
            <div class="stat-val" style="font-size:16px">Preview</div>
            <div class="stat-sub">Validate the uploaded rows before writing to the database.</div>
        </div>
        <div class="stat">
            <div class="stat-lbl">Step 3</div>
            <div class="stat-val" style="font-size:16px">Run Import</div>
            <div class="stat-sub">Persist only the validated rows and audit the result.</div>
        </div>
    </div>

    <div class="two">
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Upload Workspace</span>
            </div>

            <div style="padding:14px">
                <div class="form-field" style="margin-bottom:12px">
                    <label class="form-label">Import Type</label>
                    <select x-model="selectedType" name="type" class="form-input">
                        @foreach ($types as $type)
                            <option value="{{ $type['type'] }}">{{ $type['label'] }}</option>
                        @endforeach
                    </select>
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
                    <div style="font-size:24px;color:var(--color-text-tertiary);margin-bottom:6px">&uarr;</div>
                    <div style="font-size:12px;color:var(--color-text-secondary)">Drop Excel or CSV here</div>
                    <div style="font-size:10px;color:var(--color-text-tertiary);margin-top:2px;margin-bottom:8px" x-text="fileName || 'Accepts .xlsx and .csv'"></div>
                    <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
                        <a :href="`{{ url($routePrefix . '/import/template') }}/${selectedType}`" class="btn" x-on:click.stop>Download Template</a>
                    </div>
                </div>

                <form action="{{ route($routePrefix . '.import.preview') }}" method="POST" enctype="multipart/form-data" style="display:grid;gap:12px">
                    @csrf
                    <input type="hidden" name="type" x-bind:value="selectedType">

                    <div class="form-field">
                        <label class="form-label">Upload File</label>
                        <input x-ref="fileInput" x-on:change="fileName = $event.target.files[0]?.name || ''" type="file" name="file" class="form-input" accept=".xlsx,.csv,.txt">
                    </div>

                    <button type="submit" class="btn btn-primary" style="justify-content:center">Preview Import</button>
                </form>

                @if ($preview)
                    <div style="display:flex;gap:12px;font-size:10px;margin-top:12px;flex-wrap:wrap">
                        <span style="color:#3B6D11">OK {{ $preview['valid_count'] }} rows validated</span>
                        <span style="color:#A32D2D">! {{ $preview['error_count'] }} issues found</span>
                        <span style="color:var(--color-text-secondary)">{{ $preview['failure_percent'] ?? 0 }}% failure rate; max {{ $preview['max_failure_percent'] ?? config('gwl.max_import_failure_percent', 20) }}%</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Supported Types</span>
            </div>

            <div class="supported-types-list">
                @foreach ($types as $type)
                    <div class="supported-type-card">
                        <div class="supported-type-title">{{ $type['label'] }}</div>
                        <div class="supported-type-desc">{{ $type['description'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @if ($preview)
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Preview Results</span>

                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    @if (! empty($preview['errors']))
                        <form action="{{ route($routePrefix . '.import.clear') }}" method="POST">
                            @csrf
                            <button type="submit" class="actn actn-r">Clear Upload Error</button>
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
                </div>
            </div>

            @if (! empty($preview['blocked']))
                <div style="padding:12px 14px;border-top:0.5px solid var(--color-border-tertiary);background:#fcebeb;color:#a32d2d;font-size:11px">
                    Import is blocked because the validation failure rate is above the configured limit.
                </div>
            @endif

            @if (! empty($preview['preview_rows']))
                <table>
                    <thead>
                        <tr>
                            @foreach (array_keys($preview['preview_rows'][0]) as $heading)
                                <th>{{ Str::of($heading)->replace('_', ' ')->title() }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['preview_rows'] as $row)
                            <tr>
                                @foreach ($row as $value)
                                    <td>{{ is_array($value) ? implode(', ', $value) : ($value !== '' && $value !== null ? $value : '-') }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if (! empty($preview['errors']))
                <div style="padding:14px;border-top:0.5px solid var(--color-border-tertiary)">
                    <div class="pg-title" style="margin-bottom:8px">Validation Issues</div>
                    <div style="display:grid;gap:8px">
                        @foreach ($preview['errors'] as $error)
                            <div class="erp-card" style="padding:10px;background:#fef2f2;border-color:#fecaca;color:#991b1b">
                                <strong>Row {{ $error['row'] }}:</strong> {{ $error['message'] }}
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
