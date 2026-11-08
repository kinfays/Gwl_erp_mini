<div>
    <x-ui.page-header title="Categories and types" description="The list your location's vouchers use. Start from the standard list: rename or switch off any item for your location only, or add your own. Nothing is deleted: a switched-off item cannot be picked for a new voucher, while old vouchers keep it." />

    @error('catalogue.name') <x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert> @enderror
    @error('catalogue.category_id') <x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert> @enderror
    @error('catalogue.location') <x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert> @enderror

    @if ($isSuperAdmin)
        <x-ui.card class="dash-row">
            <x-ui.checkbox label="Change the standard list itself (every location sees it)" wire:model.live="defaults" />
        </x-ui.card>
    @endif

    @unless ($editingDefaults)
        @include('livewire.finance.partials.location-picker')
    @endunless

    @if ($editingDefaults || $location)
        <x-ui.card title="Add a category" :description="$editingDefaults ? 'Added to the standard list.' : 'Added for your location only.'" class="dash-row">
            <div class="ui-form-grid">
                <x-ui.input label="Name" wire:model="newCategory" maxlength="120" error="catalogue.name" />
            </div>
            <div class="ui-form-actions"><x-ui.button variant="primary" icon="plus" wire:click="addCategory">Add category</x-ui.button></div>
        </x-ui.card>

        @foreach ($categories as $category)
            <x-ui.card wire:key="cat-{{ $category->id }}" class="dash-row">
                <x-slot:actions>
                    @if ($category->is_renamed)<span class="ui-badge ui-badge-neutral">Renamed here (standard name: {{ $category->name }})</span>@endif
                    @unless ($category->is_default)<span class="ui-badge ui-badge-neutral">Added for this location</span>@endunless
                    @unless ($category->effective_active)<span class="ui-badge ui-badge-neutral">Not in use</span>@endunless
                </x-slot:actions>

                @if ($renamingCategory === $category->id)
                    <div class="ui-form-grid">
                        <x-ui.input label="Category name" wire:model="renameValue" maxlength="120" error="catalogue.name" />
                    </div>
                    <div class="ui-form-actions">
                        <x-ui.button size="sm" variant="primary" wire:click="saveRename">Save</x-ui.button>
                        <x-ui.button size="sm" wire:click="cancelRename">Cancel</x-ui.button>
                    </div>
                @else
                    <h2 class="ui-card-title">{{ $category->effective_name }}</h2>
                    <div class="ui-form-actions">
                        <x-ui.button size="sm" variant="ghost" wire:click="startRenameCategory({{ $category->id }})">Rename</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" wire:click="toggleCategory({{ $category->id }}, {{ $category->own_active ? 'false' : 'true' }})">{{ $category->own_active ? 'Switch off' : 'Switch on' }}</x-ui.button>
                    </div>
                @endif

                <ul>
                    @foreach ($category->types as $type)
                        <li wire:key="type-{{ $type->id }}">
                            @if ($renamingType === $type->id)
                                <x-ui.input label="Type name" wire:model="renameValue" maxlength="120" error="catalogue.name" />
                                <x-ui.button size="sm" variant="primary" wire:click="saveRename">Save</x-ui.button>
                                <x-ui.button size="sm" wire:click="cancelRename">Cancel</x-ui.button>
                            @else
                                {{ $type->effective_name }}
                                @if ($type->is_renamed)<span class="ui-badge ui-badge-neutral">Renamed here (standard name: {{ $type->name }})</span>@endif
                                @unless ($type->is_default)<span class="ui-badge ui-badge-neutral">Added for this location</span>@endunless
                                @unless ($type->effective_active)<span class="ui-badge ui-badge-neutral">Not in use</span>@endunless
                                <x-ui.button size="sm" variant="ghost" wire:click="startRenameType({{ $type->id }})">Rename</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="toggleType({{ $type->id }}, {{ $type->own_active ? 'false' : 'true' }})">{{ $type->own_active ? 'Switch off' : 'Switch on' }}</x-ui.button>
                                @if ($type->is_default === $editingDefaults)
                                    <select class="form-input" wire:model="moveTo.{{ $type->id }}" aria-label="Move {{ $type->effective_name }} to another category">
                                        <option value="">Move to...</option>
                                        @foreach ($categories as $other)
                                            @if ($other->id !== $category->id)<option value="{{ $other->id }}">{{ $other->effective_name }}</option>@endif
                                        @endforeach
                                    </select>
                                    <x-ui.button size="sm" variant="ghost" wire:click="moveType({{ $type->id }})">Move</x-ui.button>
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ul>

                <div class="ui-form-grid">
                    <x-ui.input label="Add a type to {{ $category->effective_name }}" wire:model="newType.{{ $category->id }}" maxlength="120" error="catalogue.name" />
                </div>
                <div class="ui-form-actions"><x-ui.button size="sm" icon="plus" wire:click="addType({{ $category->id }})">Add type</x-ui.button></div>
            </x-ui.card>
        @endforeach
    @endif
</div>
