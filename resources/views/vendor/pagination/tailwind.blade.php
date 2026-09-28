{{-- App-styled copy of Laravel's Tailwind paginator (same URLs, rel and aria semantics). --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="pager">
        <p class="pager-summary">
            {!! __('Showing') !!}
            @if ($paginator->firstItem())
                <b>{{ $paginator->firstItem() }}</b>
                {!! __('to') !!}
                <b>{{ $paginator->lastItem() }}</b>
            @else
                <b>{{ $paginator->count() }}</b>
            @endif
            {!! __('of') !!}
            <b>{{ $paginator->total() }}</b>
            {!! __('results') !!}
        </p>

        <div class="pager-pages">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <span class="pager-btn" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                    <x-ui.icon name="chevron-left" class="icon-sm" />
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="pager-btn" aria-label="{{ __('pagination.previous') }}">
                    <x-ui.icon name="chevron-left" class="icon-sm" />
                </a>
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
                        <span class="pager-num">
                            @if ($page == $paginator->currentPage())
                                <span class="pager-btn" aria-current="page">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="pager-btn" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        </span>
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="pager-btn" aria-label="{{ __('pagination.next') }}">
                    <x-ui.icon name="chevron-right" class="icon-sm" />
                </a>
            @else
                <span class="pager-btn" aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                    <x-ui.icon name="chevron-right" class="icon-sm" />
                </span>
            @endif
        </div>
    </nav>
@endif
