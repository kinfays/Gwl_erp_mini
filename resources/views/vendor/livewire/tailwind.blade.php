{{-- App-styled copy of Livewire's pagination view (same wire:click actions, keys and dusk hooks). --}}
@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Pagination Navigation" class="pager">
            <p class="pager-summary">
                <span>{!! __('Showing') !!}</span>
                <b>{{ $paginator->firstItem() }}</b>
                <span>{!! __('to') !!}</span>
                <b>{{ $paginator->lastItem() }}</b>
                <span>{!! __('of') !!}</span>
                <b>{{ $paginator->total() }}</b>
                <span>{!! __('results') !!}</span>
            </p>

            <div class="pager-pages">
                {{-- Previous Page Link --}}
                @if ($paginator->onFirstPage())
                    <span class="pager-btn" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                        <x-ui.icon name="chevron-left" class="icon-sm" />
                    </span>
                @else
                    <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after" class="pager-btn" aria-label="{{ __('pagination.previous') }}">
                        <x-ui.icon name="chevron-left" class="icon-sm" />
                    </button>
                @endif

                <span class="pager-compact">{{ __('Page :current of :last', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>

                {{-- Pagination Elements --}}
                @foreach ($elements as $element)
                    {{-- "Three Dots" Separator --}}
                    @if (is_string($element))
                        <span class="pager-gap" aria-disabled="true">{{ $element }}</span>
                    @endif

                    {{-- Array Of Links --}}
                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            <span class="pager-num" wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                                @if ($page == $paginator->currentPage())
                                    <span class="pager-btn" aria-current="page">{{ $page }}</span>
                                @else
                                    <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="pager-btn" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                        {{ $page }}
                                    </button>
                                @endif
                            </span>
                        @endforeach
                    @endif
                @endforeach

                {{-- Next Page Link --}}
                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after" class="pager-btn" aria-label="{{ __('pagination.next') }}">
                        <x-ui.icon name="chevron-right" class="icon-sm" />
                    </button>
                @else
                    <span class="pager-btn" aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                        <x-ui.icon name="chevron-right" class="icon-sm" />
                    </span>
                @endif
            </div>
        </nav>
    @endif
</div>
