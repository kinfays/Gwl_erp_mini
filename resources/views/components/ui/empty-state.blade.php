{{--
    Empty state: say what is missing and offer the next action in the slot (a button or link).
    Docs: docs/11-ui-components.md#empty-state
--}}
@props([
    'icon' => 'inbox',
    'title',
    'description' => null,
])

<div {{ $attributes->class(['ui-empty']) }}>
    <span class="ui-empty-icon" aria-hidden="true"><x-ui.icon :name="$icon" class="icon-lg" /></span>
    <p class="ui-empty-title">{{ $title }}</p>
    @if ($description)
        <p class="ui-empty-desc">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="ui-empty-actions">{{ $slot }}</div>
    @endif
</div>
