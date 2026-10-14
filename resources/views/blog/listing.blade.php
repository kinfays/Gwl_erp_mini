@php
    $kicker = match ($kind) {
        'category' => __('Section'),
        'tag' => __('Topic'),
        'district' => __('District'),
        default => __('Search'),
    };
@endphp
<x-blog-layout :title="$heading" :active="$activeCategory ? 'category:'.$activeCategory : ($kind === 'search' ? 'search' : null)" :region-name="$regionName">
    <div class="b-wrap">
        <header class="b-pagehead">
            <p class="b-kicker">{{ $kicker }}</p>
            <h1>{{ $heading }}</h1>
            @if ($stream)
                <p>{{ trans_choice(':count article|:count articles', $stream->total()) }}</p>
            @endif
        </header>

        @if (! $hasRegion)
            <div class="b-notice b-notice-warn" role="alert">{{ __('Your account is not linked to a region yet, so there is nothing to show. Please ask HR to check your staff record.') }}</div>
        @else
            <div class="b-cols" style="padding-top:1rem">
                <section aria-label="{{ __('Articles') }}">
                    @if ($kind === 'search' && trim((string) ($filters['q'] ?? '')) === '')
                        <p class="b-empty">{{ __('Type a word in the search box to look through the articles.') }}</p>
                    @else
                        @forelse ($stream as $post)
                            @include('blog.partials.row', ['post' => $post, 'showRegion' => $regions->isNotEmpty()])
                        @empty
                            <div class="b-empty">
                                <h2>{{ __('No articles found') }}</h2>
                                <p>{{ __('Nothing matches that yet.') }} <a href="{{ route('blog.home') }}">{{ __('See the latest articles') }}</a></p>
                            </div>
                        @endforelse
                        @if ($stream)
                            {{ $stream->links('blog.partials.pager') }}
                        @endif
                    @endif
                </section>

                @include('blog.partials.sidebar')
            </div>
        @endif
    </div>
</x-blog-layout>
