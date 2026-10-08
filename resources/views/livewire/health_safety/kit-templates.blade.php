<div>
    <x-ui.page-header title="Kit templates" description="What a new first aid kit of each type starts with. Saving a template never changes a kit that already exists." />

    <x-ui.alert tone="info" title="Confirm the contents" class="dash-row">
        Ask EHS or a first aid trainer what each type of kit should hold before relying on these lists. The suggested starter items are only a generic place to begin.
    </x-ui.alert>

    <x-ui.card class="dash-row">
        <form wire:submit="save" class="ui-stack" novalidate>
            <div class="ui-form-grid">
                <x-ui.select label="Kit type" wire:model.live="kitType" hint="Types already set up: {{ collect($types)->map(fn ($label, $value) => $label.' ('.($counts[$value] ?? 0).')')->join(', ') }}">
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <div style="align-self:end">
                    <x-ui.button icon="sparkles" wire:click="loadStarterItems">Load suggested starter items</x-ui.button>
                </div>
            </div>

            @if ($starterLoaded)
                <x-ui.alert tone="warning">These are suggestions, not your template yet. Change anything that is wrong, then save. Confirm the contents with EHS or a first aid trainer.</x-ui.alert>
            @endif

            @error('items')<p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>@enderror

            @foreach ($rows as $index => $row)
                <div class="ui-form-grid" wire:key="hs-tpl-{{ $index }}">
                    <x-ui.input label="Item" wire:model="rows.{{ $index }}.item_name" />
                    <x-ui.input type="number" min="1" label="Should hold" wire:model="rows.{{ $index }}.required_qty" error="items.{{ $index }}.required_qty" />
                    <x-ui.checkbox label="Has an expiry date" wire:model="rows.{{ $index }}.has_expiry" />
                    <div><x-ui.button size="sm" variant="ghost" wire:click="removeRow({{ $index }})">Remove</x-ui.button></div>
                </div>
            @endforeach

            <div><x-ui.button size="sm" icon="plus" wire:click="addRow">Add an item</x-ui.button></div>

            <div class="ui-form-actions">
                <x-ui.button type="submit" variant="primary" loading="save">Save template</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
