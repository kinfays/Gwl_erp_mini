{{-- Upcoming events, pinned, districts and tags: only this region's published articles (BlogFeedService). --}}
@php
    $regionQuery = ($regionId ?? null) && ($regions ?? collect())->isNotEmpty() ? ['region' => $regionId] : [];
@endphp
<aside class="b-side" aria-label="{{ __('More from the blog') }}">
    @if ($sidebar['events']->isNotEmpty())
        <section aria-labelledby="side-events">
            <h2 id="side-events">{{ __('Upcoming events') }}</h2>
            <ul>
                @foreach ($sidebar['events'] as $event)
                    <li class="b-event">
                        <div class="b-event-date" aria-hidden="true"><strong>{{ $event->event_date->format('j') }}</strong><span>{{ $event->event_date->format('M') }}</span></div>
                        <div>
                            <a href="{{ $event->url() }}">{{ $event->title }}</a>
                            <small>
                                <time datetime="{{ $event->event_date->toDateString() }}">{{ $event->event_date->format('l, j F') }}</time>
                                @if ($event->venue) · {{ $event->venue }} @endif
                                @if ($event->district) · {{ $event->district->district_name }} @endif
                            </small>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($sidebar['pinned']->isNotEmpty())
        <section aria-labelledby="side-pinned">
            <h2 id="side-pinned">{{ __('Pinned') }}</h2>
            <ul class="b-side-list">
                @foreach ($sidebar['pinned'] as $pinned)
                    <li><a href="{{ $pinned->url() }}">{{ $pinned->title }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($sidebar['districts']->isNotEmpty())
        <section aria-labelledby="side-districts">
            <h2 id="side-districts">{{ __('Browse by district') }}</h2>
            <ul class="b-side-list">
                @foreach ($sidebar['districts'] as $district)
                    <li><a href="{{ route('blog.district', ['district' => $district->id] + $regionQuery) }}">{{ $district->name }}</a><span class="b-count">{{ $district->total }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($sidebar['tags']->isNotEmpty())
        <section aria-labelledby="side-tags">
            <h2 id="side-tags">{{ __('Topics') }}</h2>
            <div class="b-chips">
                @foreach ($sidebar['tags'] as $tag => $total)
                    <a class="b-chip" href="{{ route('blog.tag', ['tag' => $tag] + $regionQuery) }}">{{ $tag }}</a>
                @endforeach
            </div>
        </section>
    @endif
</aside>
