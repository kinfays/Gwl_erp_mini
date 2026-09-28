<div>
    <x-ui.page-header title="Historical Visitor Log" description="Read-only visitor activity by date.">
        <x-slot:actions>
            <a href="{{ route('visitors.export.excel', ['start_date' => $exportStartDate, 'end_date' => $exportEndDate]) }}" class="btn btn-secondary">
                <x-ui.icon name="file-spreadsheet" />
                Export Excel
            </a>
            <a href="{{ route('visitors.export.pdf', ['start_date' => $exportStartDate, 'end_date' => $exportEndDate]) }}" class="btn btn-secondary">
                <x-ui.icon name="file-down" />
                Export PDF
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padded="false">
        <div class="ui-toolbar">
            <div class="date-range" role="group" aria-label="Date range">
                <input type="date" wire:model.live="startDate" class="form-input" aria-label="Start date">
                <span class="date-range-sep" aria-hidden="true">to</span>
                <input type="date" wire:model.live="endDate" class="form-input" aria-label="End date">
            </div>
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" wire:model.live="search" class="form-input ui-input has-icon" placeholder="Search visitor or staff" aria-label="Search visitors">
            </div>
            <select wire:model.live="status" class="form-input" aria-label="Status">
                <option value="">All statuses</option>
                <option value="inside">On site</option>
                <option value="out">Checked out</option>
            </select>
        </div>

        <div class="ui-loading-host">
            <x-ui.table label="Visitor history" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Visitor Name</th>
                        <th>Visiting</th>
                        <th>Purpose</th>
                        <th>Check-in Time</th>
                        <th>Check-out Time</th>
                        <th>Code</th>
                        <th>Status</th>
                        <th class="actions">Signature</th>
                    </tr>
                </x-slot:head>

                @forelse ($visitors as $visitor)
                    <tr wire:key="visitor-history-{{ $visitor->id }}">
                        <td>
                            <span class="ui-cell-stack">
                                <span class="ui-person-name">{{ $visitor->visitor_name }}</span>
                                <span class="ui-person-sub">{{ $visitor->phone ?: 'No phone' }}</span>
                            </span>
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ $visitor->staff?->full_name ?? '-' }}</span>
                                <span class="ui-person-sub">{{ $visitor->staff?->department?->department_name ?? '-' }}</span>
                            </span>
                        </td>
                        <td class="cell-wrap">{{ $visitor->purpose ?: '-' }}</td>
                        <td class="nowrap num">{{ $visitor->check_in_at?->format('d M Y h:i A') }}</td>
                        <td @class(['nowrap', 'num', 'cell-muted' => ! $visitor->check_out_at])>{{ $visitor->check_out_at?->format('d M Y h:i A') ?: '-' }}</td>
                        <td class="mono">{{ $visitor->checkout_code }}</td>
                        <td><x-ui.status-pill domain="presence" :status="$visitor->status" /></td>
                        <td class="actions">
                            <button type="button" wire:click="showSignature({{ $visitor->id }})" class="btn btn-ghost btn-sm btn-icon" title="View signature" aria-label="View signature for {{ $visitor->visitor_name }}">
                                <x-ui.icon name="signature" />
                            </button>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="8" icon="history" title="No visitors found for this date range." description="Widen the dates or clear the search." />
                @endforelse

                <x-slot:footer>
                    <p class="pager-summary">
                        Showing {{ $visitors->firstItem() ?? 0 }} - {{ $visitors->lastItem() ?? 0 }} of {{ $visitors->total() }} visitors
                    </p>
                    <div>{{ $visitors->links() }}</div>
                </x-slot:footer>
            </x-ui.table>

            <div wire:loading.delay class="table-skeleton">
                <span class="skeleton-line"></span>
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
            </div>
        </div>
    </x-ui.card>

    @if ($signatureVisitor)
        <x-ui.modal :title="$signatureVisitor->visitor_name.' signature'" close="closeSignature()" size="md" icon="signature">
            <img src="{{ $signatureVisitor->signature }}" alt="Signature of {{ $signatureVisitor->visitor_name }}" class="signature-image">

            <x-slot:footer>
                <button type="button" wire:click="closeSignature" class="btn btn-secondary">Close</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
