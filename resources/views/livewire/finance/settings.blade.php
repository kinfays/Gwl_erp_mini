<div>
    <x-ui.page-header title="Categories and types" description="One list for the whole company. A category or type is never deleted: switch it off and it can no longer be picked, while old vouchers keep it.">
    </x-ui.page-header>

    @error('catalogue.name') <x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert> @enderror
    @error('catalogue.category_id') <x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert> @enderror

    @unless ($canManage)
        <x-ui.alert tone="info" role="status" class="dash-row">You can read the list. Changing it needs the "manage categories" permission.</x-ui.alert>
    @endunless

    @if ($canManage)
        <x-ui.card title="Add a category" class="dash-row">
            <div class="ui-form-grid">
                <x-ui.input label="Name" wire:model="newCategory" maxlength="120" error="catalogue.name" />
            </div>
            <div class="ui-form-actions"><x-ui.button variant="primary" icon="plus" wire:click="addCategory">Add category</x-ui.button></div>
        </x-ui.card>
    @endif

    @foreach ($categories as $category)
        <x-ui.card wire:key="cat-{{ $category->id }}" class="dash-row">
            <x-slot:actions>
                @unless ($category->is_active)<span class="ui-badge ui-badge-neutral">Not in use</span>@endunless
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
                <h2 class="ui-card-title">{{ $category->name }}</h2>
                @if ($canManage)
                    <div class="ui-form-actions">
                        <x-ui.button size="sm" variant="ghost" wire:click="startRenameCategory({{ $category->id }}, @js($category->name))">Rename</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" wire:click="toggleCategory({{ $category->id }}, {{ $category->is_active ? 'false' : 'true' }})">{{ $category->is_active ? 'Switch off' : 'Switch on' }}</x-ui.button>
                    </div>
                @endif
            @endif

            <ul>
                @foreach ($category->types as $type)
                    <li wire:key="type-{{ $type->id }}">
                        @if ($renamingType === $type->id)
                            <x-ui.input label="Type name" wire:model="renameValue" maxlength="120" error="catalogue.name" />
                            <x-ui.button size="sm" variant="primary" wire:click="saveRename">Save</x-ui.button>
                            <x-ui.button size="sm" wire:click="cancelRename">Cancel</x-ui.button>
                        @else
                            {{ $type->name }}
                            @unless ($type->is_active)<span class="ui-badge ui-badge-neutral">Not in use</span>@endunless
                            @if ($canManage)
                                <x-ui.button size="sm" variant="ghost" wire:click="startRenameType({{ $type->id }}, @js($type->name))">Rename</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="toggleType({{ $type->id }}, {{ $type->is_active ? 'false' : 'true' }})">{{ $type->is_active ? 'Switch off' : 'Switch on' }}</x-ui.button>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($canManage)
                <div class="ui-form-grid">
                    <x-ui.input label="Add a type to {{ $category->name }}" wire:model="newType.{{ $category->id }}" maxlength="120" error="catalogue.name" />
                </div>
                <div class="ui-form-actions"><x-ui.button size="sm" icon="plus" wire:click="addType({{ $category->id }})">Add type</x-ui.button></div>
            @endif
        </x-ui.card>
    @endforeach
</div>
