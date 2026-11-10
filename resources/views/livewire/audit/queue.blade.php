<div>
    <x-ui.page-header :title="$mine ? 'My work' : 'Reviewed vouchers'" :description="$mine ? 'The vouchers the Audit manager has given to you, and any you have already worked on. The ones waiting longest are first.' : 'Vouchers an Audit officer has already ticked, queried or withdrawn a tick on. Assigning is done from Assignments.'" />

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
        <div class="ui-form-actions"><x-ui.button size="sm" variant="ghost" wire:click="clearFilters">Reset</x-ui.button></div>
    </x-ui.card>

    @include('livewire.finance.partials.print-bar')
    @include('livewire.finance.partials.pv-table')
</div>
