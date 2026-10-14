@if ($paginator->hasPages())
    <nav class="b-pager" role="navigation" aria-label="{{ __('Pages of articles') }}">
        @if ($paginator->onFirstPage())
            <span class="b-btn is-off" aria-disabled="true">← {{ __('Newer') }}</span>
        @else
            <a class="b-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev">← {{ __('Newer') }}</a>
        @endif

        <span>{{ __('Page :page of :last', ['page' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>

        @if ($paginator->hasMorePages())
            <a class="b-btn b-btn-primary" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Older') }} →</a>
        @else
            <span class="b-btn is-off" aria-disabled="true">{{ __('Older') }} →</span>
        @endif
    </nav>
@endif
