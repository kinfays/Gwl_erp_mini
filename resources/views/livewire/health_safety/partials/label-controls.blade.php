{{-- QR label controls shared by the extinguisher and kit lists (manage_equipment only). Needs: $total, $labelBase, $labelLayouts, $labelMax, $selected, $labelLayout, and a wire:model-able "labels" error. --}}
<div class="ui-toolbar" aria-label="Print QR labels">
    <select class="form-input" wire:model="labelLayout" aria-label="Label size">
        @foreach ($labelLayouts as $value => $definition)
            <option value="{{ $value }}">{{ $definition['label'] }}</option>
        @endforeach
    </select>
    <x-ui.button size="sm" icon="qr-code" wire:click="printSelected" :disabled="count($selected) === 0">Print labels for selected ({{ count($selected) }})</x-ui.button>
    <x-ui.button size="sm" icon="qr-code" wire:click="printAllInFilter" :disabled="$total === 0">Print labels for all {{ $total }} in this filter</x-ui.button>
    <span class="ui-hint">Labels will open <strong>{{ $labelBase }}</strong>. Print labels from production only: the address is part of the code. A sheet holds up to {{ $labelMax }}.</span>
</div>
@error('labels')<x-ui.alert tone="danger" role="alert" class="dash-row">{{ $message }}</x-ui.alert>@enderror
