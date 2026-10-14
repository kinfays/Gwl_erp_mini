<x-blog-layout :title="$post->title" :active="'category:'.$post->category" :region-name="$regionName ?? $post->region?->region_name" :description="$post->teaser(200)">
    @push('head')
        <link rel="canonical" href="{{ $post->url() }}">
    @endpush

    <article class="b-article">
        @include('blog.partials.article', ['post' => $post, 'canManage' => $canManage, 'regions' => $regions, 'preview' => false])

        @if ($neighbours['older'] || $neighbours['newer'])
            <nav class="b-neighbours" aria-label="{{ __('More articles') }}">
                @if ($neighbours['older'])
                    <a href="{{ $neighbours['older']->url() }}" rel="prev"><small>← {{ __('Older') }}</small><span>{{ $neighbours['older']->title }}</span></a>
                @else
                    <span></span>
                @endif
                @if ($neighbours['newer'])
                    <a href="{{ $neighbours['newer']->url() }}" rel="next" class="b-next"><small>{{ __('Newer') }} →</small><span>{{ $neighbours['newer']->title }}</span></a>
                @endif
            </nav>
        @endif

        @if ($fromDistrict->isNotEmpty())
            <section class="b-related" aria-labelledby="more-district">
                <h2 id="more-district">{{ __('More from :district', ['district' => $post->district->district_name]) }}</h2>
                <div class="b-grid4">
                    @foreach ($fromDistrict as $other)
                        <article class="b-mini">
                            <a href="{{ $other->url() }}" tabindex="-1" aria-hidden="true">@include('blog.partials.cover', ['post' => $other, 'ratio' => 'b-r43', 'sizes' => '14rem'])</a>
                            <h3><a href="{{ $other->url() }}">{{ $other->title }}</a></h3>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($related->isNotEmpty())
            <section class="b-related" aria-labelledby="related-h">
                <h2 id="related-h">{{ __('Related articles') }}</h2>
                <div class="b-grid4">
                    @foreach ($related as $other)
                        <article class="b-mini">
                            <a href="{{ $other->url() }}" tabindex="-1" aria-hidden="true">@include('blog.partials.cover', ['post' => $other, 'ratio' => 'b-r43', 'sizes' => '14rem'])</a>
                            <p class="b-kicker">{{ $other->categoryLabel() }}</p>
                            <h3><a href="{{ $other->url() }}">{{ $other->title }}</a></h3>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    </article>

    @push('scripts')
        @vite('resources/js/blog-lightbox.js')
    @endpush
</x-blog-layout>
