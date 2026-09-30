<div>
    <x-ui.page-header title="My register" description="The letters that came to your desk, in date order. Export it instead of keeping a separate sheet.">
        @unless ($missingEmployee || $error)
            <x-slot:actions>
                <a href="{{ route('letters.register.excel', $query) }}" class="btn btn-secondary">
                    <x-ui.icon name="file-spreadsheet" />
                    Export Excel
                </a>
                <a href="{{ route('letters.register.pdf', $query) }}" class="btn btn-secondary">
                    <x-ui.icon name="file-down" />
                    Export PDF
                </a>
            </x-slot:actions>
        @endunless
    </x-ui.page-header>

    @if ($missingEmployee)
        <x-ui.alert tone="danger">Your user account is not linked to an employee record, so there is no register to show.</x-ui.alert>
    @else
        <x-ui.card :padded="false">
            <div class="ui-toolbar">
                <div class="tabs" role="group" aria-label="Show letters">
                    @foreach (\App\Services\Letters\LetterRegisterService::SCOPES as $key => $label)
                        <button type="button" wire:click="setScope('{{ $key }}')" @class(['tab', 'active' => $scope === $key]) aria-pressed="{{ $scope === $key ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>

                <div class="toolbar-filters">
                    <x-ui.input label="Received from" type="date" wire:model.live="from" />
                    <x-ui.input label="Received to" type="date" wire:model.live="to" />
                </div>
            </div>

            @if ($error)
                <div class="reject-panel">
                    <x-ui.alert tone="warning" role="alert">{{ $error }}</x-ui.alert>
                </div>
            @else
                <x-ui.table label="Letter register" :sticky="false" dense>
                    <x-slot:head>
                        <tr>
                            @foreach ($register->columns() as $heading)
                                <th>{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </x-slot:head>

                    @forelse ($rows as $row)
                        <tr wire:key="register-{{ $row['no'] }}">
                            <td class="num">{{ $row['no'] }}</td>
                            <td class="nowrap">{{ $row['date_received'] }}</td>
                            <td class="mono nowrap">{{ $row['sn_number'] }}</td>
                            <td @class(['mono', 'cell-muted' => $row['ref_no'] === ''])>{{ $row['ref_no'] ?: '-' }}</td>
                            <td><x-ui.badge :tone="$row['type'] === 'Internal' ? 'primary' : 'lagoon'">{{ $row['type'] }}</x-ui.badge></td>
                            <td class="nowrap cell-muted">{{ $row['date_on_letter'] }}</td>
                            <td>{{ $row['sender'] }}</td>
                            <td>{{ $row['received_from'] }}</td>
                            <td>{{ $row['subject'] }}</td>
                            <td class="nowrap cell-muted">{{ $row['date_out'] ?: '-' }}</td>
                            <td>{{ $row['sent_to'] ?: '-' }}</td>
                            <td class="mono nowrap">{{ $row['transmittal_no'] ?: '-' }}</td>
                            <td class="nowrap">{{ $row['status'] }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($row['remarks'], 120) ?: '-' }}</td>
                            @if (array_key_exists('scanned', $row))
                                <td class="nowrap">{{ $row['scanned'] ?: '-' }}</td>
                            @endif
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="count($register->columns())" icon="inbox" title="No letters received in this period." description="Change the dates or the filter above." />
                    @endforelse

                    <x-slot:footer>
                        <p class="pager-summary">
                            Showing {{ $rows->firstItem() ?? 0 }} - {{ $rows->lastItem() ?? 0 }} of {{ $total }} {{ \Illuminate\Support\Str::plural('letter', $total) }}
                        </p>
                        <div>{{ $rows->links() }}</div>
                    </x-slot:footer>
                </x-ui.table>
            @endif
        </x-ui.card>
    @endif
</div>
