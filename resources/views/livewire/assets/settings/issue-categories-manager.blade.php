<div>
    <x-ui.page-header title="Issue Categories" description="The issue types and sub-options offered when reporting an ICT issue. Switch an option off to stop offering it; past reports keep their wording." />

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Categories" :description="$categories->count().' categories'" :padded="false">
            <x-ui.table label="Issue categories">
                <x-slot:head>
                    <tr>
                        <th>Category</th>
                        <th>Needs a linked device</th>
                        <th class="num">Options</th>
                        <th>Status</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @foreach ($categories as $category)
                    <tr wire:key="issue-category-{{ $category->id }}" @class(['is-selected' => $selectedCategoryId === $category->id])>
                        <td class="nowrap">
                            <button type="button" wire:click="selectCategory({{ $category->id }})" class="btn btn-ghost btn-sm" aria-label="Manage options of {{ $category->label }}">
                                <span class="ui-person-name">{{ $category->label }}</span>
                            </button>
                        </td>
                        <td>
                            @if ($category->requires_linked_asset)
                                Yes <span class="ui-person-sub">{{ $deviceLabels[$category->maps_to_device_category] ?? 'any device' }}</span>
                            @else
                                No
                            @endif
                        </td>
                        <td class="num">{{ $category->subtypes_count }}</td>
                        <td><x-ui.status-pill domain="account" :status="$category->is_active ? 'Active' : 'Inactive'" /></td>
                        <td class="actions">
                            <div class="row-actions">
                                <button type="button" wire:click="moveCategory({{ $category->id }}, 'up')" class="btn btn-ghost btn-sm btn-icon" @disabled($loop->first) title="Move up" aria-label="Move {{ $category->label }} up">
                                    <x-ui.icon name="arrow-up" />
                                </button>
                                <button type="button" wire:click="moveCategory({{ $category->id }}, 'down')" class="btn btn-ghost btn-sm btn-icon" @disabled($loop->last) title="Move down" aria-label="Move {{ $category->label }} down">
                                    <x-ui.icon name="arrow-down" />
                                </button>
                                <button type="button" wire:click="toggleCategory({{ $category->id }})" class="btn btn-ghost btn-sm">
                                    {{ $category->is_active ? 'Switch off' : 'Switch on' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card :title="$selected ? $selected->label.' options' : 'Options'" class="manager-form" :padded="false">
            @if ($selected)
                <x-ui.table label="Options of {{ $selected->label }}" :sticky="false">
                    <x-slot:head>
                        <tr>
                            <th>Option</th>
                            <th>Status</th>
                            <th class="actions"><span class="sr-only-text">Actions</span></th>
                        </tr>
                    </x-slot:head>

                    @forelse ($subtypes as $subtype)
                        <tr wire:key="issue-subtype-{{ $subtype->id }}">
                            <td>{{ $subtype->label }}</td>
                            <td><x-ui.status-pill domain="account" :status="$subtype->is_active ? 'Active' : 'Inactive'" /></td>
                            <td class="actions">
                                <div class="row-actions">
                                    <button type="button" wire:click="moveSubtype({{ $subtype->id }}, 'up')" class="btn btn-ghost btn-sm btn-icon" @disabled($loop->first) aria-label="Move {{ $subtype->label }} up">
                                        <x-ui.icon name="arrow-up" />
                                    </button>
                                    <button type="button" wire:click="moveSubtype({{ $subtype->id }}, 'down')" class="btn btn-ghost btn-sm btn-icon" @disabled($loop->last) aria-label="Move {{ $subtype->label }} down">
                                        <x-ui.icon name="arrow-down" />
                                    </button>
                                    <button type="button" wire:click="toggleSubtype({{ $subtype->id }})" class="btn btn-ghost btn-sm">
                                        {{ $subtype->is_active ? 'Switch off' : 'Switch on' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="3" icon="list" title="No options." description="This category is a single choice on the form. Add one below to make it a two-step choice." />
                    @endforelse
                </x-ui.table>

                <div class="ui-stack" style="padding: 1rem;">
                    <x-ui.input label="Add an option" wire:model="newSubtype" wire:keydown.enter="addSubtype" placeholder="e.g. Fan noise" />
                    <button type="button" wire:click="addSubtype" class="btn btn-primary" wire:loading.attr="disabled" wire:target="addSubtype">Add option</button>
                    <p class="ui-hint">Options can be switched off but not renamed or deleted, so older reports keep reading correctly.</p>
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
