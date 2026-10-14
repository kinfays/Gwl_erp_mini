<x-blog-layout title="Photo Gallery" active="gallery" :region-name="$regionName" description="Photos from the published articles of the region.">
    <div class="b-wrap">
        <header class="b-pagehead">
            <p class="b-kicker">{{ __('Pictures') }}</p>
            <h1>{{ __('Photo Gallery') }}</h1>
            <p>{{ __('Photos from published articles, newest first. Open one to view it larger; the title takes you to the article.') }}</p>
        </header>

        @if (! $hasRegion)
            <div class="b-notice b-notice-warn" role="alert">{{ __('Your account is not linked to a region yet, so there is nothing to show. Please ask HR to check your staff record.') }}</div>
        @elseif ($albums === null || $albums->isEmpty())
            <div class="b-empty">
                <h2>{{ __('No photos yet') }}</h2>
                <p>{{ __('Pictures appear here when an article with photos is published.') }}</p>
            </div>
        @else
            @foreach ($albums as $album)
                <section class="b-album" data-lightbox-group aria-labelledby="album-{{ $album->id }}">
                    <p class="b-kicker">{{ $album->categoryLabel() }}</p>
                    <h2 id="album-{{ $album->id }}"><a href="{{ $album->url() }}">{{ $album->title }}</a></h2>
                    <ul class="b-meta">
                        @if ($album->published_at)<li><time datetime="{{ $album->published_at->toDateString() }}">{{ $album->published_at->format('j M Y') }}</time></li>@endif
                        @if ($album->district)<li>{{ $album->district->district_name }}</li>@endif
                        <li>{{ trans_choice(':count photo|:count photos', $album->gallery->count()) }}</li>
                    </ul>
                    <div class="b-wall">
                        @foreach ($album->gallery as $photo)
                            @php [$lw, $lh] = $photo->dimensions('large'); [$tw, $th] = $photo->dimensions('thumb'); @endphp
                            <a href="{{ $photo->url('large') }}" data-lightbox data-w="{{ $lw }}" data-h="{{ $lh }}" data-caption="{{ $photo->caption ?: $album->title }}">
                                <img src="{{ $photo->url('thumb') }}" srcset="{{ $photo->srcset() }}" sizes="(min-width: 640px) 12rem, 33vw" width="{{ $tw }}" height="{{ $th }}" alt="{{ $photo->alt ?? '' }}" loading="lazy" decoding="async">
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach

            {{ $albums->links('blog.partials.pager') }}
        @endif
    </div>

    @push('scripts')
        @vite('resources/js/blog-lightbox.js')
    @endpush
</x-blog-layout>
