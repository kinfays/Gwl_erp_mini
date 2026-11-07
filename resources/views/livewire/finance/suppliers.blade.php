<div>
    <x-ui.page-header title="Suppliers" description="One list for the whole company. The figures beside a supplier are for the vouchers of {{ $seesAllLocations ? 'every location' : 'your location' }} only.">
        <x-slot:actions>
            @if ($canImport)
                <x-ui.button icon="upload" :href="route('finance.suppliers.import')">Import from Excel</x-ui.button>
            @endif
            @if ($canManage)
                <x-ui.button variant="primary" icon="plus" wire:click="startAdd">Add a supplier</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($canManage)
        <x-ui.alert tone="info" role="status" class="dash-row">You can read the list{{ $module === 'audit' ? '. Suppliers are added and changed by Finance.' : '. Adding or changing a supplier needs the "manage suppliers" permission.' }}</x-ui.alert>
    @endunless

    @error('supplier.*') <x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert> @enderror

    @if ($showForm)
        <x-ui.card :title="$editingId ? 'Edit supplier' : 'Add a supplier'" :description="$editingId ? 'Vouchers already saved keep the name they were saved with.' : 'Only the name is required. Leave TIN and phone empty if you do not have them.'" class="dash-row">
            @include('livewire.finance.partials.supplier-fields', ['prefix' => 'form', 'matches' => $matches, 'ackModel' => 'differentSupplier'])
            @if ($errors->has('form.name') && $matches)
                <p class="cell-muted">If the supplier is already listed, cancel and use the one on the list.</p>
            @endif
            <div class="ui-form-actions">
                <x-ui.button variant="primary" icon="check" wire:click="save" loading="save">{{ $editingId ? 'Save changes' : 'Add supplier' }}</x-ui.button>
                <x-ui.button wire:click="cancel">Cancel</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card class="dash-row">
        <div class="ui-form-grid">
            <x-ui.input label="Search" wire:model.live.debounce.400ms="search" placeholder="Name, TIN, phone or code" />
            <x-ui.select label="Show" wire:model.live="status">
                <option value="active">Active suppliers</option>
                <option value="inactive">Switched off</option>
                <option value="all">All</option>
            </x-ui.select>
        </div>
    </x-ui.card>

    <x-ui.card :padded="false" class="dash-row">
        <x-ui.table label="Suppliers">
            <x-slot:head>
                <tr>
                    <th>Supplier</th>
                    <th>Code</th>
                    <th>TIN</th>
                    <th>Phone</th>
                    <th class="num">Vouchers</th>
                    <th class="num">Total (GH¢)</th>
                    <th>Last used</th>
                    <th>Status</th>
                    <th><span class="sr-only-text">Actions</span></th>
                </tr>
            </x-slot:head>
            @forelse ($rows as $supplier)
                @php($figure = $figures[$supplier->id] ?? null)
                <tr wire:key="sup-{{ $supplier->id }}" @class(['is-voided' => ! $supplier->is_active])>
                    <td>{{ $supplier->name }}</td>
                    <td class="mono">{{ $supplier->code ?? '' }}</td>
                    <td class="mono">{{ $supplier->tin ?? '' }}</td>
                    <td class="mono">{{ $supplier->phone ?? '' }}</td>
                    <td class="num">{{ $figure ? number_format($figure['count']) : '0' }}</td>
                    <td class="num">{{ $figure ? \App\Services\GWater\Money::format($figure['total']) : '0.00' }}</td>
                    <td class="nowrap">{{ $figure && $figure['last_date'] ? \Illuminate\Support\Carbon::parse($figure['last_date'])->format('d M Y') : '' }}</td>
                    <td>{{ $supplier->is_active ? 'Active' : 'Switched off' }}</td>
                    <td class="nowrap">
                        @if ($figure)
                            <x-ui.button size="sm" variant="ghost" wire:click="view({{ $supplier->id }})">{{ $viewing?->id === $supplier->id ? 'Hide vouchers' : 'Vouchers' }}</x-ui.button>
                        @endif
                        @if ($canManage)
                            <x-ui.button size="sm" variant="ghost" wire:click="startEdit({{ $supplier->id }})">Edit</x-ui.button>
                        @endif
                        @if ($canDeactivate)
                            <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $supplier->id }}, {{ $supplier->is_active ? 'false' : 'true' }})" wire:confirm="{{ $supplier->is_active ? 'Switch this supplier off? It can no longer be chosen for a new voucher; old vouchers keep it.' : 'Switch this supplier on again?' }}">{{ $supplier->is_active ? 'Switch off' : 'Switch on' }}</x-ui.button>
                        @endif
                    </td>
                </tr>
                @if ($viewing?->id === $supplier->id)
                    <tr wire:key="sup-{{ $supplier->id }}-vouchers">
                        <td colspan="9">
                            <strong>Latest vouchers paid to {{ $supplier->name }}</strong>
                            <ul>
                                @foreach ($recent as $pv)
                                    <li wire:key="sup-pv-{{ $pv->id }}">
                                        <a href="{{ route($showRoute, $pv->id) }}">{{ $pv->pv_no }}</a>
                                        <span class="cell-muted">{{ $pv->pv_date->format('d M Y') }}, GH¢ {{ \App\Services\GWater\Money::format($pv->amount) }}, {{ $pv->statusLabel() }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </td>
                    </tr>
                @endif
            @empty
                <x-ui.empty-row :colspan="9" icon="building-2" title="No suppliers match." />
            @endforelse
            <x-slot:footer><div>{{ $rows->links() }}</div></x-slot:footer>
        </x-ui.table>
    </x-ui.card>
</div>
