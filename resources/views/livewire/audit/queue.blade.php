<div>
    <x-ui.page-header title="Audit review" description="Vouchers Finance has sent to Audit in your location. The ones waiting longest are first." />

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
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="PV date from" type="date" wire:model.live="from" />
            <x-ui.input label="PV date to" type="date" wire:model.live="to" />
        </div>
        <div class="ui-form-actions"><x-ui.button size="sm" variant="ghost" wire:click="clearFilters">Reset</x-ui.button></div>
    </x-ui.card>

    @include('livewire.finance.partials.pv-table')
</div>
