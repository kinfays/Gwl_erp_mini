<div>
    <x-ui.page-header title="PPE reorder levels" description="When a store's stock of a type (all sizes together) falls to this number or below, it is flagged low. Leave a level empty for no flag." />

    @if ($stores->isEmpty())
        <x-ui.alert tone="info" class="dash-row">There is no PPE store yet. Mark a site as one under <a href="{{ route('health_safety.sites') }}">Sites</a> first.</x-ui.alert>
    @else
        <x-ui.card class="dash-row">
            <form wire:submit="save" class="ui-stack" novalidate>
                <div class="ui-form-grid">
                    <x-ui.select label="Store" wire:model.live="storeId">
                        @foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach
                    </x-ui.select>
                </div>

                <div class="ui-form-grid">
                    @foreach ($types as $type)
                        <x-ui.input type="number" min="0" inputmode="numeric" :label="$type->name" wire:model="levels.{{ $type->id }}" hint="In stock now: {{ $totals[$type->id] ?? 0 }}" wire:key="hs-lvl-{{ $type->id }}" />
                    @endforeach
                </div>
                @error('level')<p class="ui-error"><x-ui.icon name="circle-alert" class="icon-sm" /><span>{{ $message }}</span></p>@enderror

                <div class="ui-form-actions">
                    <x-ui.button type="submit" variant="primary" loading="save">Save levels</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif
</div>
