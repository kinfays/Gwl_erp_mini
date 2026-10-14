{{--
    The article exactly as a reader sees it: headline, byline, cover, event box, body, tags. The article page AND the editor's
    "Preview as readers see it" render this one file, so they cannot drift apart.
    $post, $canManage, $regions (a collection: non-empty only for super_admin), $preview (true inside the editor).
--}}
@php
    $preview = $preview ?? false;
    $canManage = $canManage ?? false;
    $regions = $regions ?? collect();
@endphp
<header class="b-art-head">
    <p class="b-kicker">
        <a href="{{ $preview ? '#' : route('blog.category', $post->category) }}">{{ $post->categoryLabel() }}</a>
        @unless ($post->isPublished())<span class="b-tag-draft">{{ __("Draft: only your region's PR team can see this") }}</span>@endunless
        @if ($post->is_pinned)<span class="b-tag-pin">{{ __('Pinned') }}</span>@endif
    </p>
    <h1>{{ $post->title }}</h1>
    @if (filled($post->summary))
        <p class="b-standfirst">{{ $post->summary }}</p>
    @endif

    <div class="b-byline">
        @if ($post->author)<span>{{ __('By') }} <strong>{{ $post->author->full_name }}</strong></span>@endif
        @if ($post->published_at)<time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('j F Y') }}</time>@endif
        @if ($post->district)<span>{{ $post->district->district_name }}</span>@endif
        @if ($regions->isNotEmpty() && $post->region)<span>{{ $post->region->region_name }}</span>@endif
        @if ($post->reading_minutes)<span>{{ $post->reading_minutes }} {{ __('min read') }}</span>@endif

        @unless ($preview)
            <span class="b-actions">
                <button type="button" class="b-btn b-btn-ghost" x-data="{ done: false }"
                    x-on:click="navigator.clipboard.writeText(@js($post->url())).then(() => { done = true; setTimeout(() => done = false, 2000) })">
                    <x-ui.icon name="copy" />
                    <span x-text="done ? '{{ __('Link copied') }}' : '{{ __('Copy link') }}'">{{ __('Copy link') }}</span>
                </button>
                <button type="button" class="b-btn b-btn-ghost" onclick="window.print()">
                    <x-ui.icon name="printer" />
                    {{ __('Print') }}
                </button>
                @if ($canManage)
                    <a class="b-btn" href="{{ route('blog.edit', $post) }}"><x-ui.icon name="pencil" /> {{ __('Edit') }}</a>
                @endif
            </span>
        @endunless
    </div>
</header>

@if ($post->hasCover())
    @php $cover = $post->coverDimensions('large'); @endphp
    <figure class="b-cover">
        <div class="b-frame">
            <img
                src="{{ $post->coverUrl('large') }}"
                @if ($post->coverSrcset()) srcset="{{ $post->coverSrcset() }}" sizes="(min-width: 1100px) 1000px, 100vw" @endif
                @if ($cover) width="{{ $cover[0] }}" height="{{ $cover[1] }}" @endif
                alt="" decoding="async" style="object-position: {{ $post->coverFocus() }}">
        </div>
        @if ($post->cover_caption || $post->cover_credit)
            <figcaption>{{ $post->cover_caption }} @if ($post->cover_credit)<span class="bm-credit">{{ $post->cover_credit }}</span>@endif</figcaption>
        @endif
    </figure>
@endif

@if ($post->event_date || filled($post->venue))
    <div class="b-eventbox-wrap">
        <section class="b-eventbox" aria-label="{{ __('Event details') }}">
            <h2>{{ __('Event details') }}</h2>
            <dl style="display:contents">
                @if ($post->event_date)
                    <div><dt>{{ __('Date') }}</dt><dd><time datetime="{{ $post->event_date->toDateString() }}">{{ $post->event_date->format('l, j F Y') }}</time></dd></div>
                @endif
                @if (filled($post->venue))
                    <div><dt>{{ __('Venue') }}</dt><dd>{{ $post->venue }}</dd></div>
                @endif
                @if ($post->district)
                    <div><dt>{{ __('District') }}</dt><dd>{{ $post->district->district_name }}</dd></div>
                @endif
            </dl>
        </section>
    </div>
@endif

<div class="bm-prose" data-lightbox-group>{!! $post->bodyHtml() !!}</div>

@if (! empty($post->tags))
    <div class="b-tagsline b-chips">
        @foreach ($post->tags as $tag)
            <a class="b-chip" href="{{ $preview ? '#' : route('blog.tag', $tag) }}">{{ $tag }}</a>
        @endforeach
    </div>
@endif
