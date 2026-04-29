@php
    $routePrefix = $context;
    $preview = session('import_preview.' . $context);
    $types = $availableImportTypes ?? app(\App\Services\Import\DataImportService::class)->availableTypes($showUsersType ?? true);
@endphp

<div x-data="{ selectedType: '{{ $defaultType ?? ($types[0]['type'] ?? 'employees') }}' }">
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

    @if ($errors->has('import'))
        <div class="erp-card" style="margin-bottom:14px;background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            {{ $errors->first('import') }}
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

                <div class="import-zone">
                    <div style="font-size:24px;color:var(--color-text-tertiary);margin-bottom:6px">↑</div>
                    <div style="font-size:12px;color:var(--color-text-secondary)">Drop Excel or CSV here</div>
                    <div style="font-size:10px;color:var(--color-text-tertiary);margin-top:2px;margin-bottom:8px">Accepts .xlsx and .csv</div>
                    <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
                        <a :href="`{{ url($routePrefix . '/import/template') }}/${selectedType}`" class="btn">Download Template</a>
                    </div>
                </div>

                <form action="{{ route($routePrefix . '.import.preview') }}" method="POST" enctype="multipart/form-data" style="display:grid;gap:12px">
                    @csrf
                    <input type="hidden" name="type" x-bind:value="selectedType">

                    <div class="form-field">
                        <label class="form-label">Upload File</label>
                        <input type="file" name="file" class="form-input" accept=".xlsx,.csv,.txt">
                    </div>

                    <button type="submit" class="btn btn-primary" style="justify-content:center">Preview Import</button>
                </form>

                @if ($preview)
                    <div style="display:flex;gap:12px;font-size:10px;margin-top:12px;flex-wrap:wrap">
                        <span style="color:#3B6D11">✓ {{ $preview['valid_count'] }} rows validated</span>
                        <span style="color:#A32D2D">✗ {{ $preview['error_count'] }} issues found</span>
                    </div>
                @endif
            </div>
        </div>

        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Supported Types</span>
            </div>

            <div style="padding:14px;display:grid;gap:10px">
                @foreach ($types as $type)
                    <div class="erp-card" style="padding:12px">
                        <div style="font-size:12px;font-weight:600">{{ $type['label'] }}</div>
                        <div style="font-size:11px;color:var(--color-text-secondary);margin-top:4px">{{ $type['description'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @if ($preview)
        <div class="pg">
            <div class="pg-head">
                <span class="pg-title">Preview Results</span>

                @if (! empty($preview['valid_rows']))
                    <form action="{{ route($routePrefix . '.import.run') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-primary">Run Import</button>
                    </form>
                @endif
            </div>

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
