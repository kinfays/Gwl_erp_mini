<div>
    <x-ui.page-header :title="$regionName ? $regionName.' blog' : 'Regional blog'" description="Activities, meetings, workshops and training in the region, written by the Public Relations team.">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button :href="route('blog.manage')" icon="list">Manage posts</x-ui.button>
                <x-ui.button :href="route('blog.create')" variant="primary" icon="circle-plus">New article</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (! $hasRegion)
        <x-ui.alert tone="warning" title="No region on your account" class="dash-row">
            The blog shows the articles of your own region, and your account is not linked to one yet. Please ask HR to check your staff record.
        </x-ui.alert>
    @else
        <x-ui.card class="dash-row">
            <div class="ui-form-grid">
                <x-ui.input label="Search" wire:model.live.debounce.400ms="search" icon="search" placeholder="Title, place or tag" />
                <x-ui.select label="Kind" wire:model.live="category">
                    <option value="">All kinds</option>
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                @if ($pickRegion)
                    <x-ui.select label="Region" wire:model.live="regionId">
                        <option value="">All regions</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region->id }}">{{ $region->region_name }}</option>
                        @endforeach
                    </x-ui.select>
                @endif
            </div>
            @if ($search !== '' || $category !== '' || $regionId !== '')
                <div class="ui-form-actions" style="margin-top:10px">
                    <x-ui.button size="sm" variant="ghost" wire:click="resetFilters">Clear filters</x-ui.button>
                </div>
            @endif
        </x-ui.card>

        @if ($posts->isEmpty())
            <x-ui.card class="dash-row">
                <x-ui.empty-state icon="scroll-text" title="Nothing here yet" :description="($search !== '' || $category !== '') ? 'No article matches those filters.' : 'No article has been published for this region yet.'">
                    @if ($canManage)
                        <x-ui.button :href="route('blog.create')" variant="primary" icon="circle-plus">Write the first article</x-ui.button>
                    @endif
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <div class="dash-row" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:16px">
                @foreach ($posts as $post)
                    <x-ui.card :padded="false" wire:key="blog-post-{{ $post->id }}">
                        @if ($post->hasCover())
                            <a href="{{ route('blog.show', $post) }}" tabindex="-1" aria-hidden="true">
                                <img src="{{ $post->coverUrl() }}" alt="" loading="lazy" style="width:100%;aspect-ratio:16/9;object-fit:cover;display:block">
                            </a>
                        @endif
                        <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px">
                            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                                <x-ui.badge tone="primary">{{ $post->categoryLabel() }}</x-ui.badge>
                                @if ($post->is_pinned)
                                    <x-ui.badge tone="lagoon">Pinned</x-ui.badge>
                                @endif
                                @if ($pickRegion)
                                    <x-ui.badge>{{ $post->region?->region_name }}</x-ui.badge>
                                @endif
                            </div>
                            <h2 class="ui-card-title" style="margin:0"><a href="{{ route('blog.show', $post) }}">{{ $post->title }}</a></h2>
                            <p style="margin:0">{{ $post->teaser() }}</p>
                            <p class="ui-hint" style="margin:0">
                                @if ($post->event_date)
                                    {{ $post->event_date->format('d M Y') }}
                                @else
                                    {{ $post->published_at?->format('d M Y') }}
                                @endif
                                @if (filled($post->venue) || $post->district)
                                    &middot; {{ collect([$post->venue, $post->district?->district_name])->filter()->join(', ') }}
                                @endif
                            </p>
                            @if (! empty($post->tags))
                                <div style="display:flex;flex-wrap:wrap;gap:4px">
                                    @foreach ($post->tags as $tag)
                                        <x-ui.button size="sm" variant="ghost" wire:click="searchTag(@js($tag))">#{{ $tag }}</x-ui.button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </x-ui.card>
                @endforeach
            </div>

            @if ($posts->hasPages())
                <div class="pager-end">{{ $posts->links() }}</div>
            @endif
        @endif
    @endif
</div>
