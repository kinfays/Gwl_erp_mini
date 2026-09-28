{{--
    Page title block under the layout's breadcrumb: one <h1> per page, a short description, and the
    page's primary actions in the `actions` slot (primary action last). Docs: docs/11-ui-components.md#page-header
--}}
@props([
    'title',
    'description' => null,
])

<div {{ $attributes->class(['page-head']) }}>
    <div class="ph-left">
        <h1>{{ $title }}</h1>
        @if ($description)
            <p>{{ $description }}</p>
        @endif
        {{ $slot }}
    </div>

    @isset($actions)
        <div class="ph-right">{{ $actions }}</div>
    @endisset
</div>
