<div>
    <x-ui.page-header title="Payment vouchers" description="Vouchers prepared in your location. Send a draft to Audit; Audit ticks it or queries it, and you acknowledge it once Audit has.">
        <x-slot:actions>
            <x-ui.button icon="layers" :href="route('finance.batches')">Batches</x-ui.button>
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

    @if ($selectable)
        <p class="cell-muted dash-row" id="batch-help">Tick drafts to send them to Audit together in a batch. Only a draft that is in no batch can be ticked; a voucher that cannot says why when you point at its box.</p>

        @if ($selectedCount > 0)
            <x-ui.alert tone="info" role="status" class="dash-row">
                {{ $selectedCount }} {{ \Illuminate\Support\Str::plural('voucher', $selectedCount) }} selected.
                <x-slot:actions>
                    <x-ui.button size="sm" variant="primary" icon="layers" wire:click="openBatch">Create batch from selected</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" wire:click="clearSelection">Clear</x-ui.button>
                </x-slot:actions>
            </x-ui.alert>
        @endif
    @endif

    @include('livewire.finance.partials.print-bar')
    @include('livewire.finance.partials.pv-table')

    @if ($showBatch)
        <x-ui.modal title="Create a batch" description="A batch groups the selected drafts so they can be sent, assigned and printed together. Audit still decides each voucher on its own." close="$set('showBatch', false)">
            <p><strong>{{ $chosenCount }}</strong> {{ \Illuminate\Support\Str::plural('voucher', $chosenCount) }}, total <strong>GH¢ {{ $chosenTotal }}</strong>.</p>
            <x-ui.input label="Title (optional)" wire:model="batchTitle" maxlength="150" />
            <x-ui.textarea label="Remarks (optional)" wire:model="batchRemarks" rows="3" maxlength="1000" />
            @foreach (['batch.vouchers', 'batch.title', 'batch.remarks', 'batch.location'] as $key)
                @error($key) <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert> @enderror
            @endforeach
            <x-slot:footer>
                <x-ui.button wire:click="$set('showBatch', false)">Cancel</x-ui.button>
                <x-ui.button variant="primary" wire:click="createBatch" loading="createBatch">Create batch</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
