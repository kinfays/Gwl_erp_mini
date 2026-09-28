<div wire:poll.30s>
    <x-ui.page-header title="Today's Visitor Log" :description="now()->format('l, d F Y')">
        <x-slot:actions>
            <a href="{{ route('visitors.export.excel', ['date' => today()->toDateString()]) }}" class="btn btn-secondary">
                <x-ui.icon name="file-spreadsheet" />
                Export Excel
            </a>
            <a href="{{ route('visitors.export.pdf', ['date' => today()->toDateString()]) }}" class="btn btn-secondary">
                <x-ui.icon name="file-down" />
                Export PDF
            </a>
            <a href="{{ route('visitors.kiosk') }}" target="_blank" rel="noopener" class="btn btn-primary">
                <x-ui.icon name="monitor" />
                Kiosk Screen
                <span class="sr-only-text">(opens in a new tab)</span>
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Total Today" :value="$stats['total']" icon="users" meta="Checked in today" />
        <x-ui.stat-tile label="Currently Inside" :value="$stats['inside']" icon="door-open" tone="lagoon" meta="On site now" />
        <x-ui.stat-tile label="Checked Out" :value="$stats['out']" icon="log-out" tone="muted" meta="Left the premises today" />
        <x-ui.stat-tile label="Auto-checkout Time" :value="$stats['autoTime']" icon="clock" tone="info" meta="Anyone still inside is checked out then" />
    </div>

    <x-ui.card title="Live visitor table" description="Refreshes every 30 seconds." :padded="false">
        <div class="ui-toolbar">
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
            <x-ui.table label="Today's visitors" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Visitor Name</th>
                        <th>Visiting</th>
                        <th>Purpose</th>
                        <th>Check-in Time</th>
                        <th>Check-out Time</th>
                        <th>Code</th>
                        <th>Status</th>
                        <th class="actions">Actions</th>
                    </tr>
                </x-slot:head>

                @forelse ($visitors as $visitor)
                    <tr wire:key="visitor-{{ $visitor->id }}">
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
                        <td class="nowrap num">{{ $visitor->check_in_at?->format('h:i A') }}</td>
                        <td @class(['nowrap', 'num', 'cell-muted' => ! $visitor->check_out_at])>{{ $visitor->check_out_at?->format('h:i A') ?: '-' }}</td>
                        <td>
                            @if ($visitor->check_out_at)
                                <span class="mono">{{ $visitor->checkout_code }}</span>
                            @else
                                <span class="cell-muted nowrap">Still Inside</span>
                            @endif
                        </td>
                        <td><x-ui.status-pill domain="presence" :status="$visitor->status" /></td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="showSignature({{ $visitor->id }})" class="btn btn-ghost btn-sm btn-icon" title="View signature" aria-label="View signature for {{ $visitor->visitor_name }}">
                                    <x-ui.icon name="signature" />
                                </button>
                                @if (! $visitor->check_out_at)
                                    <button
                                        type="button"
                                        class="btn btn-sm"
                                        x-data
                                        x-on:click.prevent="$dispatch('confirm-action', {
                                            title: 'Check out visitor?',
                                            message: @js('This will mark ' . $visitor->visitor_name . ' as checked out now.'),
                                            confirmLabel: 'Check Out',
                                            variant: 'primary',
                                            action: () => $wire.checkOut({{ $visitor->id }})
                                        })"
                                    >
                                        <x-ui.icon name="log-out" class="icon-sm" />
                                        Check Out
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="8" icon="door-open" title="No visitors logged today." description="Check-ins from the kiosk appear here as they happen." />
                @endforelse

                <x-slot:footer>
                    <p class="pager-summary">
                        Showing {{ $visitors->firstItem() ?? 0 }} - {{ $visitors->lastItem() ?? 0 }} of {{ $visitors->total() }} visitors
                    </p>
                    <div>{{ $visitors->links() }}</div>
                </x-slot:footer>
            </x-ui.table>

            <div wire:loading.delay wire:target="search,status,gotoPage,nextPage,previousPage,setPage" class="table-skeleton">
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
