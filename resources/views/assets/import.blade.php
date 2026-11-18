<x-erp-layout module="assets" :title="'Upload '.$profile->label">
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
            $base = $profile->routeBase;
            $tabs = [
                'assets' => ['Assets', 'assets.import'],
                'phones' => ['Phones', 'assets.import.phones'],
                'network' => ['Network', 'assets.import.network'],
            ];
            $descriptions = [
                'assets' => 'Add many computers, laptops, printers and other assets at once from an Excel sheet.',
                'phones' => 'Add many phones, POS terminals and SIM cards at once from an Excel sheet.',
                'network' => 'Add many routers, switches, access points and other network devices at once from an Excel sheet.',
            ];
            $checks = [
                'assets' => [
                    ['Names and serial numbers', 'Each must be unique, in the system and inside the file.'],
                    ['Manufacturer and model', 'They must already exist. Add a missing one under Settings first.'],
                    ['Your region only', 'The location must be in your own region; a row for another region is an error.'],
                ],
                'phones' => [
                    ['Serial number or IMEI', 'A phone needs at least one. Neither may repeat in the file or in the system.'],
                    ['Manufacturer and model', 'They must already exist (required for a phone). Add a missing one under Settings first.'],
                    ['Holder and location', 'The holder is found by staff ID and never created. A blank location is the holder\'s district; one you type wins and must be in your region.'],
                ],
                'network' => [
                    ['Names, serial numbers and MAC addresses', 'Each must be unique, in the system and inside the file.'],
                    ['IP and MAC format', 'The IP address must be valid (and inside the location\'s ranges, where set); the MAC needs 12 hexadecimal digits.'],
                    ['Your region only', 'The location must be in your own region. Passwords are not imported.'],
                ],
            ];
        @endphp

        <x-ui.page-header :title="'Upload '.$profile->label" :description="$descriptions[$profile->type].' The upload only creates records; it never changes or deletes one.'">
            <x-slot:actions>
                <a href="{{ route($profile->listRoute) }}" class="btn btn-secondary">
                    <x-ui.icon name="arrow-left" />
                    Back to {{ $profile->label }}
                </a>
            </x-slot:actions>
        </x-ui.page-header>

        <nav class="ui-toolbar" aria-label="Upload type">
            @foreach ($tabs as $tabType => [$tabLabel, $tabRoute])
                <a href="{{ route($tabRoute) }}" class="btn {{ $tabType === $profile->type ? 'btn-primary' : 'btn-secondary' }}" @if ($tabType === $profile->type) aria-current="page" @endif>{{ $tabLabel }}</a>
            @endforeach
        </nav>

        @if (session('success'))
            <x-ui.alert tone="success" role="status">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert tone="danger" role="alert">
                {{ $errors->first() }}
            </x-ui.alert>
        @endif

        @if ($result && ($result['type'] ?? null) === $profile->type)
            <x-ui.card title="Import result" description="What the last import did.">
                <div class="import-summary">
                    <x-ui.status-pill tone="success" :label="$result['created'].' created'" />
                    <x-ui.status-pill :tone="$result['skipped'] > 0 ? 'warning' : 'muted'" :label="$result['skipped'].' skipped (rows with errors)'" />
                    <x-ui.status-pill :tone="$result['failed'] > 0 ? 'danger' : 'muted'" :label="$result['failed'].' failed when saving'" />
                    @if ($result['assigned'] > 0)
                        <x-ui.status-pill tone="muted" :label="$result['assigned'].' assigned to a person'" />
                    @endif
                </div>
                <div class="import-section">
                    <a href="{{ route($profile->listRoute) }}" class="btn btn-primary">
                        <x-ui.icon name="list-checks" />
                        View {{ $profile->label }}
                    </a>
                </div>
            </x-ui.card>
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
            <x-ui.card :title="'Upload '.$profile->label" description="Download the template, fill it in, then preview the file.">
                <div class="ui-stack">
                    <div>
                        <a href="{{ route($base.'.template') }}" class="btn btn-secondary">
                            <x-ui.icon name="download" />
                            Download Template
                        </a>
                        <p class="ui-hint">The template has the exact columns, an example row (marked EXAMPLE, it is ignored) and a sheet of allowed values.</p>
                    </div>

                    <form action="{{ route($base.'.preview') }}" method="POST" enctype="multipart/form-data" class="ui-stack">
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

            <x-ui.card title="What is checked" description="The same rules as the matching Add form." :padded="false">
                <div class="supported-types-list">
                    @foreach ($checks[$profile->type] as [$checkTitle, $checkText])
                        <div class="supported-type-card">
                            <div class="supported-type-title">{{ $checkTitle }}</div>
                            <div class="supported-type-desc">{{ $checkText }}</div>
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
                :description="count($previewRows) ? 'The first '.count($previewRows).' of '.$preview['total_rows'].' rows, as read from the file.' : null"
                :padded="false"
            >
                <x-slot:actions>
                    @if ($errorCount > 0 || ! empty($preview['warning_count']))
                        <a href="{{ route($base.'.errors') }}" class="btn btn-secondary">
                            <x-ui.icon name="download" />
                            Download issue list
                        </a>
                    @endif
                    <form action="{{ route($base.'.clear') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-secondary">Clear Upload</button>
                    </form>
                </x-slot:actions>

                <div class="import-summary">
                    <x-ui.status-pill tone="muted" :label="$preview['total_rows'].' rows read'" />
                    <x-ui.status-pill tone="success" :label="$preview['valid_count'].' valid'" />
                    <x-ui.status-pill :tone="($preview['invalid_rows'] ?? 0) > 0 ? 'danger' : 'muted'" :label="($preview['invalid_rows'] ?? 0).' with errors'" />
                    <x-ui.status-pill :tone="($preview['warning_rows'] ?? 0) > 0 ? 'warning' : 'muted'" :label="($preview['warning_rows'] ?? 0).' with warnings'" />
                    @if (! empty($preview['example_rows']))
                        <span class="ui-hint">{{ $preview['example_rows'] }} example row(s) ignored.</span>
                    @endif
                </div>

                @if ($preview['can_confirm'])
                    <form action="{{ route($base.'.run') }}" method="POST" class="import-section ui-stack" x-data>
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
                                    title: 'Run {{ strtolower($profile->label) }} import?',
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
                                @foreach ($row as $heading => $value)
                                    <td>
                                        @if ($heading === 'Result')
                                            <x-ui.status-pill :tone="['OK' => 'success', 'Warning' => 'warning', 'Error' => 'danger'][$value] ?? 'muted'" :label="$value" />
                                        @else
                                            {{ $value !== '' && $value !== null ? $value : '-' }}
                                        @endif
                                    </td>
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
                                    <span><strong>Row {{ $warning['row'] }}{{ ! empty($warning['column']) ? ' · '.$warning['column'] : '' }}:</strong> {{ $warning['message'] }}</span>
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
                                    <span><strong>{{ is_numeric($error['row']) ? 'Row '.$error['row'] : $error['row'] }}{{ ! empty($error['column']) ? ' · '.$error['column'] : '' }}:</strong> {{ $error['message'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($preview['error_count'] > count($preview['errors']))
                            <p class="ui-hint">Only the first {{ count($preview['errors']) }} issues are listed here. Download the issue list for all of them.</p>
                        @endif
                    </section>
                @endif
            </x-ui.card>
        @endif
    </div>
</x-erp-layout>
