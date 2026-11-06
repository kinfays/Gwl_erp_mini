{{-- Who holds an asset, as the Assets lists and detail show it: the holder, else a "General use" badge (a shared asset, like a pool car), else muted "Unassigned". Plain-text exports use IctAsset::assigneeLabel(). --}}
@props(['asset'])

@if ($asset?->assignedTo)
    {{ $asset->assignedTo->full_name }}
@elseif ($asset?->is_general_use)
    <x-ui.badge tone="lagoon">General use</x-ui.badge>
@else
    Unassigned
@endif
