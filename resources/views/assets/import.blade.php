<x-erp-layout module="assets" title="Upload Assets">
    <div class="content">
        @php
            $steps = [
                1 => ['Template', 'Download the sheet and fill it in.'],
                2 => ['Preview', 'Check every row before anything is saved.'],
                3 => ['Run Import', 'Create the valid rows and audit the result.'],
            ];
            $currentStep = $preview && ($preview['can_confirm'] ?? false) ? 3 : 2;
            $maxMb = (int) config('gwl.assets_import_max_mb', 5);
            $maxRows = (int) config('gwl.assets_import_max_rows', 1000);
        @endphp

        <x-ui.page-header title="Upload Assets" description="Add many assets at once from an Excel sheet. The upload only creates assets; it never changes or deletes one.">
            <x-slot:actions>
                <a href="{{ route('assets.assets') }}" class="btn btn-secondary">
                    <x-ui.icon name="arrow-left" />
                    Back to Assets
                </a>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert tone="danger" role="alert">
                {{ $errors->first() }}
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
            <x-ui.card title="Upload Workspace" description="Download the template, fill it in, then preview the file.">
                <div class="ui-stack">
                    <div>
                        <a href="{{ route('assets.import.template') }}" class="btn btn-secondary">
                            <x-ui.icon name="download" />
                            Download Template
                        </a>
                    </div>

                    <form action="{{ route('assets.import.preview') }}" method="POST" enctype="multipart/form-data" class="ui-stack">
                        @csrf
                        <div class="ui-field">
                            <label for="asset-import-file" class="ui-label">Upload File</label>
                            <input id="asset-import-file" type="file" name="file" class="form-input" accept=".xlsx" required>
                            <p class="ui-hint">Excel (.xlsx) only, up to {{ $maxRows }} rows and {{ $maxMb }} MB.</p>
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

            <x-ui.card title="What is checked" description="The same rules as the Add Asset form." :padded="false">
                <div class="supported-types-list">
                    <div class="supported-type-card">
                        <div class="supported-type-title">Names and serial numbers</div>
                        <div class="supported-type-desc">Each must be unique, in the system and inside the file.</div>
                    </div>
                    <div class="supported-type-card">
                        <div class="supported-type-title">Manufacturer and model</div>
                        <div class="supported-type-desc">They must already exist. Add a missing one under Settings first.</div>
                    </div>
                    <div class="supported-type-card">
                        <div class="supported-type-title">Your region only</div>
                        <div class="supported-type-desc">The location must be in your own region; a row for another region is an error.</div>
                    </div>
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
                :description="count($previewRows) ? 'The first '.count($previewRows).' of '.$preview['total_rows'].' rows, as read from the file.' : null"
                :padded="false"
            >
                <x-slot:actions>
                    <form action="{{ route('assets.import.clear') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-secondary">Clear Upload</button>
                    </form>
                </x-slot:actions>

                <div class="import-summary">
                    <x-ui.status-pill tone="success" :label="$preview['valid_count'].' valid rows'" />
                    <x-ui.status-pill :tone="$errorCount > 0 ? 'danger' : 'muted'" :label="$errorCount.' issues found'" />
                    @if (! empty($preview['warning_count']))
                        <x-ui.status-pill tone="warning" :label="$preview['warning_count'].' warnings'" />
                    @endif
                </div>

                @if ($preview['can_confirm'])
                    <form action="{{ route('assets.import.run') }}" method="POST" class="import-section ui-stack" x-data>
                        @csrf
                        @if ($preview['invalid_rows'] > 0)
                            <label class="ui-check">
                                <input type="checkbox" name="valid_only" value="1">
                                <span>Import valid rows only ({{ $preview['invalid_rows'] }} row(s) with errors will be skipped). Without this, nothing is imported while any row has an error.</span>
                            </label>
                        @endif
                        <div>
                            <button
                                type="submit"
                                class="btn btn-primary"
                                x-on:click.prevent="$dispatch('confirm-action', {
                                    title: 'Run asset import?',
                                    message: 'The file is read again and the valid rows are created and audited.',
                                    confirmLabel: 'Run Import',
                                    variant: 'primary',
                                    action: () => $root.submit()
                                })"
                            >
                                Run Import
                            </button>
                        </div>
                    </form>
                @endif

                @if (! empty($previewRows))
                    <x-ui.table label="Preview rows" :sticky="false">
                        <x-slot:head>
                            <tr>
                                @foreach (array_keys($previewRows[0]) as $heading)
                                    <th>{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </x-slot:head>

                        @foreach ($previewRows as $row)
                            <tr>
                                @foreach ($row as $value)
                                    <td>{{ $value !== '' && $value !== null ? $value : '-' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif

                @if (! empty($preview['warnings']))
                    <section class="import-issues" aria-labelledby="asset-import-warnings">
                        <h3 id="asset-import-warnings" class="ui-panel-title">Warnings ({{ $preview['warning_count'] }})</h3>
                        <p class="ui-hint">These rows are valid and will be imported.</p>
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
                    <section class="import-issues" aria-labelledby="asset-import-issues">
                        <h3 id="asset-import-issues" class="ui-panel-title">Validation Issues ({{ $preview['error_count'] }})</h3>
                        <ul class="import-issue-list">
                            @foreach ($preview['errors'] as $error)
                                <li>
                                    <x-ui.icon name="circle-alert" />
                                    <span><strong>{{ is_numeric($error['row']) ? 'Row '.$error['row'] : $error['row'] }}:</strong> {{ $error['message'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($preview['error_count'] > count($preview['errors']))
                            <p class="ui-hint">Only the first {{ count($preview['errors']) }} issues are listed. Fix these and upload again.</p>
                        @endif
                    </section>
                @endif
            </x-ui.card>
        @endif
    </div>
</x-erp-layout>
