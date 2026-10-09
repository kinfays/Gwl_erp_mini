<x-erp-layout module="blog" :title="$post->title">
    <div class="content">
        <style>
            .blog-article { max-width: 760px; }
            .blog-body { line-height: 1.7; overflow-wrap: anywhere; }
            .blog-body > :first-child { margin-top: 0; }
            .blog-body p, .blog-body ul, .blog-body ol, .blog-body blockquote, .blog-body pre { margin: 0 0 1em; }
            .blog-body h2 { font-size: 1.35em; font-weight: 600; margin: 1.6em 0 .5em; }
            .blog-body h3 { font-size: 1.15em; font-weight: 600; margin: 1.4em 0 .4em; }
            .blog-body ul { list-style: disc; padding-left: 1.5em; }
            .blog-body ol { list-style: decimal; padding-left: 1.5em; }
            .blog-body a { text-decoration: underline; }
            .blog-body img { max-width: 100%; height: auto; border-radius: 6px; }
            .blog-body blockquote { border-left: 3px solid currentColor; padding-left: 1em; opacity: .8; }
            .blog-body pre { overflow-x: auto; padding: .75em 1em; border: 1px solid currentColor; border-radius: 6px; }
            .blog-meta { display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center; }
        </style>

        @if (session('blog_notice'))
            <x-ui.alert tone="success" role="status" class="dash-row">{{ session('blog_notice') }}</x-ui.alert>
        @endif

        <x-ui.page-header :title="$post->title">
            <div class="blog-meta ui-hint">
                <x-ui.badge tone="primary">{{ $post->categoryLabel() }}</x-ui.badge>
                @unless ($post->isPublished())
                    <x-ui.badge tone="warning">Draft: only your region's PR team can see this</x-ui.badge>
                @endunless
                @if ($post->is_pinned)
                    <x-ui.badge tone="lagoon">Pinned</x-ui.badge>
                @endif
                @if ($showRegion)
                    <span>{{ $post->region?->region_name }}</span>
                @endif
                @if ($post->event_date)
                    <span><x-ui.icon name="calendar-days" class="icon-sm" /> {{ $post->event_date->format('d M Y') }}</span>
                @endif
                @if (filled($post->venue) || $post->district)
                    <span><x-ui.icon name="map-pin" class="icon-sm" /> {{ collect([$post->venue, $post->district?->district_name])->filter()->join(', ') }}</span>
                @endif
            </div>
            <x-slot:actions>
                <x-ui.button :href="route('blog.home')" icon="arrow-left">All articles</x-ui.button>
                @if ($canManage)
                    <x-ui.button :href="route('blog.edit', $post)" variant="primary" icon="pencil">Edit</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.card class="dash-row blog-article">
            @if ($post->hasCover())
                <img src="{{ $post->coverUrl() }}" alt="" style="width:100%;max-height:420px;object-fit:cover;border-radius:6px;display:block;margin-bottom:18px">
            @endif

            <div class="blog-body">{!! $post->bodyHtml() !!}</div>

            @if (! empty($post->tags))
                <div class="blog-meta" style="margin-top:18px">
                    @foreach ($post->tags as $tag)
                        <x-ui.badge>{{ $tag }}</x-ui.badge>
                    @endforeach
                </div>
            @endif

            <p class="ui-hint" style="margin-top:18px">
                @if ($post->published_at)
                    Published {{ $post->published_at->format('d M Y') }}
                @endif
                @if ($post->author)
                    by {{ $post->author->full_name }}
                @endif
            </p>
        </x-ui.card>
    </div>
</x-erp-layout>
