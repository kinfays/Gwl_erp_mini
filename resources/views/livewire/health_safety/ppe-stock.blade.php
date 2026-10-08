<div>
    <x-ui.page-header title="PPE stock" description="What each store holds, per type and size. Every change is a new line in the ledger below; nothing is edited or deleted.">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button :href="route('health_safety.export', ['report' => 'ppe-stock'] + $exportFilters)" icon="file-spreadsheet">Excel</x-ui.button>
            @endif
            @if ($canManage)
                <x-ui.button :href="route('health_safety.ppe.issues', ['issue' => 1])" icon="user-plus">Issue to staff</x-ui.button>
                <x-ui.button variant="primary" icon="plus" wire:click="openPanel('receive')">Receive stock</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($stores->isEmpty())
        <x-ui.alert tone="info" class="dash-row">
            There is no PPE store yet. Mark a site as a PPE store under <a href="{{ route('health_safety.sites') }}">Sites</a>, then receive stock into it.
        </x-ui.alert>
    @endif

    @if ($panel !== '')
        <x-ui.card :title="['receive' => 'Receive stock', 'adjust' => 'Adjust the count', 'write_off' => 'Write off', 'transfer' => 'Transfer between stores'][$panel]" class="dash-row">
            <form wire:submit="save" class="ui-stack" novalidate>
                <div class="ui-form-grid">
                    <x-ui.select :label="$panel === 'transfer' ? 'From store' : 'Store'" wire:model="panelStoreId" error="store_id" required>
                        <option value="">Select store</option>
                        @foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach
                    </x-ui.select>
                    @if ($panel === 'transfer')
                        <x-ui.select label="To store" wire:model="toStoreId" error="to_store" required>
                            <option value="">Select store</option>
                            @foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach
                        </x-ui.select>
                    @endif
                    <x-ui.select label="PPE type" wire:model.live="panelTypeId" error="ppe_type_id" required>
                        <option value="">Select type</option>
                        @foreach ($types as $type)@if ($type->is_active)<option value="{{ $type->id }}">{{ $type->name }}</option>@endif @endforeach
                    </x-ui.select>
                    @if ($selectedType?->has_sizes)
                        <x-ui.select label="Size" wire:model="panelSize" error="size" required>
                            <option value="">Select size</option>
                            @foreach ($selectedType->sizeList() as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach
                        </x-ui.select>
                    @endif
                    <x-ui.input type="number" inputmode="numeric" :label="$panel === 'adjust' ? 'Change (use a minus to take away)' : 'Quantity'" wire:model="quantity" error="quantity" required />
                    @if ($panel === 'receive')
                        <x-ui.input label="Delivery note / reference (optional)" wire:model="reference" />
                    @endif
                    <div class="span-2">
                        <x-ui.textarea :label="in_array($panel, ['adjust', 'write_off'], true) ? 'Reason' : 'Note (optional)'" wire:model="reason" rows="2" error="reason" :required="in_array($panel, ['adjust', 'write_off'], true)" />
                    </div>
                </div>
                <div class="ui-form-actions">
                    <x-ui.button wire:click="closePanel">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading="save">Save</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :padded="false" class="dash-row">
        <div class="ui-toolbar" role="search" aria-label="Filter stock">
            <select class="form-input" wire:model.live="storeId" aria-label="Store">
                <option value="">All stores</option>
                @foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach
            </select>
            <select class="form-input" wire:model.live="typeId" aria-label="PPE type">
                <option value="">All types</option>
                @foreach ($types as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach
            </select>
            <x-ui.checkbox label="Low stock only ({{ $lowCount }})" wire:model.live="low" />
        </div>
    </x-ui.card>

    @forelse ($matrix as $storeId => $rows)
        <x-ui.card :title="$rows->first()['store']->name" :padded="false" class="dash-row" wire:key="hs-ppe-store-{{ $storeId }}">
            <x-ui.table :label="'Stock at '.$rows->first()['store']->name">
                <x-slot:head>
                    <tr><th>PPE type</th><th>By size</th><th class="num">Total</th><th class="num">Reorder at</th><th>Status</th>@if ($canManage)<th class="actions"><span class="sr-only-text">Actions</span></th>@endif</tr>
                </x-slot:head>
                @foreach ($rows as $row)
                    <tr wire:key="hs-ppe-row-{{ $storeId }}-{{ $row['type']->id }}">
                        <td>{{ $row['type']->name }}@unless ($row['type']->is_active) <x-ui.badge>Deactivated</x-ui.badge>@endunless</td>
                        <td>
                            @if ($row['type']->has_sizes || count($row['sizes']) > 1)
                                @foreach ($row['sizes'] as $size => $count)<span class="ui-tags"><x-ui.badge>{{ $size === '' ? '–' : $size }}: {{ $count }}</x-ui.badge></span> @endforeach
                            @else
                                <span class="cell-muted">no sizes</span>
                            @endif
                        </td>
                        <td class="num">{{ $row['total'] }}</td>
                        <td class="num">{{ $row['level'] ?? '—' }}</td>
                        <td>@if ($row['low'])<x-ui.badge tone="danger">Low</x-ui.badge>@elseif ($row['level'] !== null)<x-ui.badge tone="success">OK</x-ui.badge>@else<span class="cell-muted">No level set</span>@endif</td>
                        @if ($canManage)
                            <td class="actions">
                                <x-ui.button size="sm" wire:click="openPanel('receive', {{ $storeId }}, {{ $row['type']->id }})">Receive</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="openPanel('adjust', {{ $storeId }}, {{ $row['type']->id }})">Adjust</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="openPanel('write_off', {{ $storeId }}, {{ $row['type']->id }})">Write off</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="openPanel('transfer', {{ $storeId }}, {{ $row['type']->id }})">Transfer</x-ui.button>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @empty
        @unless ($stores->isEmpty())
            <x-ui.card class="dash-row"><x-ui.empty-state icon="boxes" title="Nothing to show." description="No PPE types match, or none is low. Clear the filters, or add PPE types first." /></x-ui.card>
        @endunless
    @endforelse

    <x-ui.card title="Ledger" description="Newest first." :padded="false" class="dash-row">
        <x-ui.table label="Stock movements">
            <x-slot:head><tr><th>Date</th><th>Store</th><th>PPE</th><th>What</th><th class="num">Quantity</th><th>Who / why</th></tr></x-slot:head>
            @forelse ($movements as $movement)
                <tr wire:key="hs-mv-{{ $movement->id }}">
                    <td class="nowrap">{{ $movement->occurred_on->format('d M Y') }}</td>
                    <td>{{ $movement->site?->name }}</td>
                    <td>{{ $movement->type?->name }}@if ($movement->size) <span class="cell-muted">({{ $movement->size }})</span>@endif</td>
                    <td>{{ $movementTypes[$movement->movement_type] ?? $movement->movement_type }}@if ($movement->reference) <span class="ui-person-sub mono">{{ $movement->reference }}</span>@endif</td>
                    <td class="num">{{ $movement->quantity > 0 ? '+' : '' }}{{ $movement->quantity }}</td>
                    <td>
                        {{ $movement->creator?->full_name }}
                        @if ($movement->issue?->employee) <span class="ui-person-sub">{{ $movement->issue->employee->full_name }}</span>@endif
                        @if ($movement->notes) <span class="ui-person-sub">{{ $movement->notes }}</span>@endif
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="6" icon="history" title="No movements yet." />
            @endforelse

            @if ($movements->hasPages())
                <x-slot:footer><div class="pager-end">{{ $movements->links() }}</div></x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
