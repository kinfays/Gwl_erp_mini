{{--
    Data table: scrolls sideways (and, when `sticky`, vertically with a pinned header) inside its own
    region so a wide table never breaks the page. Put header rows in the `head` slot and body rows in
    the default slot; use <x-ui.empty-row> inside @forelse…@empty, or set `empty` + the empty props.
    `footer` is for pagination. Docs: docs/11-ui-components.md#table
--}}
@props([
    'label' => null,
    'sticky' => true,
    'striped' => false,
    'dense' => false,
    'pinFirst' => false,
    'empty' => false,
    'emptyTitle' => 'Nothing to show yet',
    'emptyDescription' => null,
    'emptyIcon' => 'inbox',
])

<div {{ $attributes->class(['ui-table-wrap']) }}>
    <div
        @class(['ui-table-scroll', 'is-sticky' => $sticky])
        role="region"
        tabindex="0"
        @if ($label) aria-label="{{ $label }}" @endif
    >
        <table @class(['ui-table', 'is-striped' => $striped, 'is-dense' => $dense, 'pin-first' => $pinFirst])>
            @if ($label)
                <caption class="sr-only-text">{{ $label }}</caption>
            @endif
            @isset($head)
                <thead>{{ $head }}</thead>
            @endisset
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>

    @if ($empty)
        @isset($emptyState)
            {{ $emptyState }}
        @else
            <x-ui.empty-state :icon="$emptyIcon" :title="$emptyTitle" :description="$emptyDescription">
                {{ $emptyAction ?? '' }}
            </x-ui.empty-state>
        @endisset
    @endif

    @isset($footer)
        <div class="ui-table-foot">{{ $footer }}</div>
    @endisset
</div>
