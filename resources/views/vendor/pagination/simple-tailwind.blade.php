{{-- App-styled copy of Laravel's simple Tailwind paginator. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="pager pager-simple">
        @if ($paginator->onFirstPage())
            <span class="btn btn-sm" aria-disabled="true">{!! __('pagination.previous') !!}</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-sm">{!! __('pagination.previous') !!}</a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-sm">{!! __('pagination.next') !!}</a>
        @else
            <span class="btn btn-sm" aria-disabled="true">{!! __('pagination.next') !!}</span>
        @endif
    </nav>
@endif
