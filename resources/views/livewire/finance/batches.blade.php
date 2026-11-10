<div>
    <x-ui.page-header title="Batches" description="Groups of vouchers sent to Audit together. A batch is only a container: Audit still ticks or queries each voucher on its own.">
        <x-slot:actions>
            <x-ui.button icon="arrow-left" :href="route('finance.home')">Vouchers</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="dash-row">
        <div class="ui-form-grid">
            <x-ui.input label="Search" wire:model.live.debounce.400ms="search" placeholder="Batch number or title" />
            <x-ui.select label="Batch" wire:model.live="status">
                <option value="">All</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="ui-form-actions"><x-ui.button size="sm" variant="ghost" wire:click="clearFilters">Clear filters</x-ui.button></div>
    </x-ui.card>

    <x-ui.card :padded="false" class="dash-row">
        <x-ui.table label="Batches">
            <x-slot:head>
                <tr>
                    <th>Batch no.</th>
                    <th>Title</th>
                    <th class="num">Vouchers</th>
                    <th class="num">Total (GH¢)</th>
                    <th>State</th>
                    <th>Assigned to</th>
                    <th>Sent</th>
                    @if ($isSuperAdmin)<th>Location</th>@endif
                    <th><span class="sr-only-text">Open</span></th>
                </tr>
            </x-slot:head>
            @forelse ($rows as $batch)
                @php($summary = $summaries[$batch->id])
                <tr wire:key="batch-{{ $batch->id }}" @class(['is-voided' => $batch->isCancelled()])>
                    <td class="mono nowrap"><a href="{{ route('finance.batches.show', $batch->id) }}">{{ $batch->batch_no }}</a></td>
                    <td>{{ $batch->title ?: '-' }}</td>
                    <td class="num">{{ $summary['count'] }}</td>
                    <td class="num">{{ $summary['total'] }}</td>
                    <td><x-finance.batch-state :state="$summary['state']" /></td>
                    <td>
                        @forelse ($summary['officers'] as $name)
                            <div>{{ $name }}</div>
                        @empty
                            <span class="cell-muted">{{ $batch->isSent() ? 'Nobody yet' : '-' }}</span>
                        @endforelse
                    </td>
                    <td class="nowrap">{{ $batch->sent_at?->format('d M Y') ?? '-' }}</td>
                    @if ($isSuperAdmin)<td class="cell-muted">{{ $batch->district?->district_name }}</td>@endif
                    <td class="nowrap"><x-ui.button size="sm" variant="ghost" :href="route('finance.batches.show', $batch->id)">Open</x-ui.button> <x-ui.button size="sm" variant="ghost" icon="printer" :href="route('finance.batches.print', $batch->id)" target="_blank" rel="noopener" aria-label="Print batch {{ $batch->batch_no }}"></x-ui.button></td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="$isSuperAdmin ? 9 : 8" icon="layers" title="No batches yet." description="Tick drafts on the voucher list and choose Create batch from selected." />
            @endforelse
            <x-slot:footer><div>{{ $rows->links() }}</div></x-slot:footer>
        </x-ui.table>
    </x-ui.card>
</div>
