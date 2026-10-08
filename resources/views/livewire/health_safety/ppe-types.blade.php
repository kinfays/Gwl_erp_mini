<div>
    <x-ui.page-header title="PPE types" description="The kinds of PPE you issue. Nothing is pre-loaded: the sizes and service lives are for EHS to decide.">
        <x-slot:actions>
            <x-ui.button icon="sparkles" wire:click="loadSuggested">Load suggested types</x-ui.button>
            <x-ui.button variant="primary" icon="plus" wire:click="create">Add a type</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($suggested !== [])
        <x-ui.card title="Suggested types" description="Common PPE, not yet saved. Change anything, remove what you do not use, fill in the sizes of the sized ones and the replacement months you want, then save." class="dash-row">
            <x-ui.alert tone="warning" class="dash-row">These are only names and categories. Confirm what you issue, the sizes and the service lives with EHS before relying on them.</x-ui.alert>
            <form wire:submit="saveSuggested" class="ui-stack" novalidate>
                @foreach ($suggested as $index => $row)
                    <div class="ui-form-grid" wire:key="hs-ppe-sug-{{ $index }}">
                        <x-ui.input label="Name" wire:model="suggested.{{ $index }}.name" error="rows.{{ $index }}.name" />
                        <x-ui.select label="Category" wire:model="suggested.{{ $index }}.category">
                            @foreach ($categories as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.checkbox label="Comes in sizes" wire:model.live="suggested.{{ $index }}.has_sizes" />
                        @if ($row['has_sizes'])
                            <x-ui.input label="Sizes" wire:model="suggested.{{ $index }}.sizes" error="rows.{{ $index }}.sizes" hint="Separated by commas, for example S, M, L." />
                        @endif
                        <x-ui.input type="number" min="1" label="Replace after (months, optional)" wire:model="suggested.{{ $index }}.replacement_months" error="rows.{{ $index }}.replacement_months" />
                        <div><x-ui.button size="sm" variant="ghost" wire:click="removeSuggested({{ $index }})">Remove</x-ui.button></div>
                    </div>
                @endforeach
                <div class="ui-form-actions">
                    <x-ui.button wire:click="discardSuggested">Discard</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading="saveSuggested">Save these types</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($showForm)
        <x-ui.card :title="$editingId ? 'Edit PPE type' : 'New PPE type'" class="dash-row">
            <form wire:submit="save" class="ui-stack" novalidate>
                <div class="ui-form-grid">
                    <x-ui.input label="Name" wire:model="name" required />
                    <x-ui.select label="Category" wire:model="category" required>
                        @foreach ($categories as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </x-ui.select>
                    <x-ui.checkbox label="Comes in sizes" wire:model.live="hasSizes" :disabled="$editingInUse" />
                    @if ($hasSizes)
                        <x-ui.input label="Sizes" wire:model="sizes" error="sizes" hint="Separated by commas, for example 38, 39, 40 or S, M, L.{{ $editingInUse ? ' A size that already has stock or issues cannot be removed.' : '' }}" required />
                    @endif
                    <x-ui.input type="number" min="1" label="Replace after (months)" wire:model="replacementMonths" hint="Its service life. Leave empty if there is no set life: no replacement date is then shown." />
                    <x-ui.input label="Unit" wire:model="unit" hint="For example pair, each, set." />
                    <div class="span-2"><x-ui.checkbox label="Has its own expiry date" description="Such as respirator filters. The date is asked for each time one is issued." wire:model="hasExpiry" /></div>
                </div>
                @if ($editingInUse)<p class="ui-hint">This type already has stock or issues, so whether it has sizes cannot change.</p>@endif
                <div class="ui-form-actions">
                    <x-ui.button wire:click="cancel">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" loading="save">Save type</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :padded="false" class="dash-row">
        <x-ui.table label="PPE types">
            <x-slot:head><tr><th>Name</th><th>Category</th><th>Sizes</th><th class="num">Replace after</th><th>Expiry date</th><th>Status</th><th class="actions"><span class="sr-only-text">Actions</span></th></tr></x-slot:head>
            @forelse ($types as $type)
                <tr wire:key="hs-ppe-type-{{ $type->id }}">
                    <td>{{ $type->name }} <span class="ui-person-sub">{{ $type->unit }}</span></td>
                    <td>{{ $type->categoryLabel() }}</td>
                    <td>{{ $type->has_sizes ? implode(', ', $type->sizeList()) : '—' }}</td>
                    <td class="num">{{ $type->replacement_months ? $type->replacement_months.' months' : '—' }}</td>
                    <td>{{ $type->has_expiry ? 'Yes' : '—' }}</td>
                    <td><x-ui.status-pill domain="account" :status="$type->is_active ? 'active' : 'inactive'" :label="$type->is_active ? 'Active' : 'Deactivated'" /></td>
                    <td class="actions">
                        <x-ui.button size="sm" wire:click="edit({{ $type->id }})">Edit</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $type->id }})">{{ $type->is_active ? 'Deactivate' : 'Reactivate' }}</x-ui.button>
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="boxes" title="No PPE types yet.">
                    <x-ui.button size="sm" wire:click="loadSuggested">Load suggested types</x-ui.button>
                    <x-ui.button size="sm" wire:click="create">Add one</x-ui.button>
                </x-ui.empty-row>
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
