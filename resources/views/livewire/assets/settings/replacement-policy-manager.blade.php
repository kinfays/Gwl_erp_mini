<div>
    <x-ui.page-header title="Replacement Policy" description="How many years after purchase a device is due for replacement. Drives the Replacement Forecast on the dashboard." />

    <div class="ui-grid ui-grid-main">
        <x-ui.card title="Per-Type Overrides" description="An override replaces the default for one asset type. Types without one follow the default." :padded="false">
            <x-ui.table label="Replacement policy overrides">
                <x-slot:head>
                    <tr>
                        <th>Asset type</th>
                        <th class="num">Replace after (years)</th>
                        <th class="actions"><span class="sr-only-text">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($overrides as $override)
                    <tr wire:key="policy-{{ $override->id }}">
                        <td>{{ $assetTypes[$override->asset_type] ?? $override->asset_type }}</td>
                        <td class="num">
                            <input type="number" min="1" max="50" value="{{ $override->years }}" class="form-input" style="width: 5rem;"
                                aria-label="Years for {{ $assetTypes[$override->asset_type] ?? $override->asset_type }}"
                                x-data x-on:change="$wire.updateOverride({{ $override->id }}, $event.target.value)">
                        </td>
                        <td class="actions">
                            <button type="button" wire:click="deleteOverride({{ $override->id }})" class="btn btn-ghost btn-sm btn-icon is-danger" title="Remove override" aria-label="Remove override for {{ $assetTypes[$override->asset_type] ?? $override->asset_type }}">
                                <x-ui.icon name="trash-2" />
                            </button>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="3" icon="clock" title="No overrides." description="Every asset type follows the default policy." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <div class="ui-stack">
            <x-ui.card title="Default Policy" class="manager-form">
                <div class="ui-stack">
                    <x-ui.input label="Replace after (years)" type="number" min="1" max="50" wire:model="defaultYears" hint="Applies to every asset type without its own override." />
                    <button type="button" wire:click="saveDefault" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveDefault">Save default</button>
                </div>
            </x-ui.card>

            <x-ui.card title="Add Override" class="manager-form">
                <div class="ui-stack">
                    <x-ui.select label="Asset type" wire:model="overrideType">
                        <option value="">Select type</option>
                        @foreach ($assetTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input label="Replace after (years)" type="number" min="1" max="50" wire:model="overrideYears" />
                    <button type="button" wire:click="addOverride" class="btn btn-primary" wire:loading.attr="disabled" wire:target="addOverride">Add override</button>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
