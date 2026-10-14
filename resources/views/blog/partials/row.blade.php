{{-- One story in the dense "latest" stream. --}}
<article class="b-row">
    <div>
        <p class="b-kicker">
            <a href="{{ route('blog.category', $post->category) }}">{{ $post->categoryLabel() }}</a>
            @if ($post->is_pinned)<span class="b-tag-pin">{{ __('Pinned') }}</span>@endif
        </p>
        <h3><a href="{{ $post->url() }}">{{ $post->title }}</a></h3>
        <p>{{ $post->teaser(200) }}</p>
        @include('blog.partials.meta', ['post' => $post, 'showRegion' => $showRegion ?? false])
    </div>
    <a href="{{ $post->url() }}" tabindex="-1" aria-hidden="true">
        @include('blog.partials.cover', ['post' => $post, 'ratio' => 'b-r43', 'sizes' => '(min-width: 640px) 12rem, 8.5rem'])
    </a>
</article>
