{{-- The small line under a headline: date, district, reading time. --}}
<ul class="b-meta">
    @if ($post->published_at)
        <li><time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('j M Y') }}</time></li>
    @endif
    @if ($post->district)
        <li><a href="{{ route('blog.district', $post->district_id) }}" class="b-link-plain">{{ $post->district->district_name }}</a></li>
    @endif
    @if ($post->reading_minutes)
        <li>{{ $post->reading_minutes }} {{ __('min read') }}</li>
    @endif
    @if (! empty($showRegion) && $post->region)
        <li>{{ $post->region->region_name }}</li>
    @endif
</ul>
