<x-blog-layout title="Latest" active="home" :region-name="$regionName" description="Activities, meetings, workshops and training in the region, written by the Public Relations team.">
    <div class="b-wrap">
        @if (! $hasRegion)
            <div class="b-notice b-notice-warn" role="alert">
                <strong>{{ __('No region on your account.') }}</strong>
                {{ __('The blog shows the articles of your own region, and your account is not linked to one yet. Please ask HR to check your staff record.') }}
            </div>
        @else
            @if (session('blog_notice'))
                <div class="b-notice" role="status">{{ session('blog_notice') }}</div>
            @endif

            @if ($regions->isNotEmpty())
                <form class="b-filters" method="GET" action="{{ route('blog.home') }}" style="margin-top:1.25rem">
                    <label class="b-field">{{ __('Region') }}
                        <select class="b-select" name="region" onchange="this.form.submit()">
                            <option value="">{{ __('All regions') }}</option>
                            @foreach ($regions as $region)
                                <option value="{{ $region->id }}" @selected((int) $regionId === $region->id)>{{ $region->region_name }}</option>
                            @endforeach
                        </select>
                    </label>
                </form>
            @endif

            @if (! $lead)
                <div class="b-empty">
                    <h2>{{ __('Nothing published yet') }}</h2>
                    <p>{{ __('No article has been published for this region yet. Check back soon.') }}</p>
                    @if (auth()->user() && app(\App\Services\Blog\BlogVisibility::class)->canManage(auth()->user()))
                        <a class="b-btn b-btn-primary" href="{{ route('blog.create') }}">{{ __('Write the first article') }}</a>
                    @endif
                </div>
            @else
                <section class="b-front" aria-labelledby="top-stories">
                    <h2 id="top-stories" class="b-sr">{{ __('Top stories') }}</h2>

                    <article class="b-lead @unless ($lead->hasCover()) b-lead-nocover @endunless">
                        @if ($lead->hasCover())
                            <a href="{{ $lead->url() }}" tabindex="-1" aria-hidden="true">
                                @include('blog.partials.cover', ['post' => $lead, 'ratio' => 'b-r169', 'sizes' => '(min-width: 900px) 60vw, 100vw', 'eager' => true])
                            </a>
                            @if ($lead->cover_caption || $lead->cover_credit)
                                <p class="b-cap">{{ $lead->cover_caption }} @if ($lead->cover_credit)<span class="bm-credit">{{ $lead->cover_credit }}</span>@endif</p>
                            @endif
                        @endif
                        <p class="b-kicker" style="margin-top:.9rem">
                            <a href="{{ route('blog.category', $lead->category) }}">{{ $lead->categoryLabel() }}</a>
                            @if ($lead->is_pinned)<span class="b-tag-pin">{{ __('Pinned') }}</span>@endif
                        </p>
                        <h3><a href="{{ $lead->url() }}">{{ $lead->title }}</a></h3>
                        <p class="b-lead-sum">{{ $lead->teaser(260) }}</p>
                        <ul class="b-meta">
                            @if ($lead->author)<li>{{ __('By') }} {{ $lead->author->full_name }}</li>@endif
                            @if ($lead->published_at)<li><time datetime="{{ $lead->published_at->toDateString() }}">{{ $lead->published_at->format('j M Y') }}</time></li>@endif
                            @if ($lead->district)<li>{{ $lead->district->district_name }}</li>@endif
                            @if ($lead->reading_minutes)<li>{{ $lead->reading_minutes }} {{ __('min read') }}</li>@endif
                        </ul>
                    </article>

                    @if ($secondary->isNotEmpty())
                        <div class="b-secondary">
                            @foreach ($secondary as $story)
                                <article class="b-sec">
                                    <div>
                                        <p class="b-kicker"><a href="{{ route('blog.category', $story->category) }}">{{ $story->categoryLabel() }}</a></p>
                                        <h3><a href="{{ $story->url() }}">{{ $story->title }}</a></h3>
                                        @include('blog.partials.meta', ['post' => $story])
                                    </div>
                                    <a href="{{ $story->url() }}" tabindex="-1" aria-hidden="true">
                                        @include('blog.partials.cover', ['post' => $story, 'ratio' => 'b-r43', 'sizes' => '7.5rem'])
                                    </a>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>

                <div class="b-cols">
                    <section aria-labelledby="latest-h">
                        <h2 id="latest-h" class="b-sechead">{{ __('Latest') }}</h2>
                        @forelse ($stream as $post)
                            @include('blog.partials.row', ['post' => $post, 'showRegion' => $regions->isNotEmpty()])
                        @empty
                            <p class="b-empty">{{ __('That is everything for now.') }}</p>
                        @endforelse
                        {{ $stream->links('blog.partials.pager') }}
                    </section>

                    @include('blog.partials.sidebar')
                </div>
            @endif
        @endif
    </div>
</x-blog-layout>
