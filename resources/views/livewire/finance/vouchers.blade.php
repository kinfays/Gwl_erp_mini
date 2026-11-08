<div>
    <x-ui.page-header title="Payment vouchers" description="Vouchers prepared in your location. Send a draft to Audit; Audit ticks it or queries it, and you acknowledge it once Audit has.">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button variant="primary" icon="plus" :href="route('finance.pvs.create')">New voucher</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card class="dash-row">
        <div class="ui-form-grid">
            <x-ui.input label="Search" wire:model.live.debounce.400ms="search" placeholder="PV number or payee" />
            <x-ui.select label="Status" wire:model.live="status">
                <option value="">All</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Category" wire:model.live="categoryId">
                <option value="">All</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->effective_name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="PV date from" type="date" wire:model.live="from" />
            <x-ui.input label="PV date to" type="date" wire:model.live="to" />
        </div>
        <div class="ui-form-actions"><x-ui.button size="sm" variant="ghost" wire:click="clearFilters">Clear filters</x-ui.button></div>
    </x-ui.card>

    @include('livewire.finance.partials.pv-table')
</div>
