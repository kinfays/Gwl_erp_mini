{{-- The Regional Blog link of the ERP header. The component's root is the link itself (the placeholder renders this same view). --}}
<a
    href="{{ $url }}"
    target="_blank"
    rel="noopener"
    class="icon-btn blog-link"
    title="{{ __('Regional Blog (opens in a new tab)') }}"
    aria-label="{{ $count > 0
        ? __('Regional Blog, :unread (opens in a new tab)', ['unread' => trans_choice(':count unread article|:count unread articles', $count)])
        : __('Regional Blog (opens in a new tab)') }}"
    @if ($live)
        wire:poll.{{ $pollSeconds }}s="refresh"
        x-data
        x-on:focus.window.debounce.400ms="$wire.refresh()"
        x-on:visibilitychange.window.debounce.400ms="if (! document.hidden) $wire.refresh()"
    @endif
>
    <x-ui.icon name="{{ $icon }}" />
    <span class="blog-link-label" aria-hidden="true">{{ __('Blog') }}</span>
    @if ($count > 0)
        <span class="count-badge blog-badge" aria-hidden="true">{{ $display }}</span>
    @endif
</a>
