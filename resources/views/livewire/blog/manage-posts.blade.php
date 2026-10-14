<div class="b-manage">
    <div class="b-manage-head">
        <div>
            <p class="b-kicker">{{ __('Public Relations') }}</p>
            <h1>{{ __('Manage articles') }}</h1>
            <p style="margin:.3rem 0 0;color:var(--b-ink-2)">{{ __("Write, publish and pin the articles of your region's blog. Drafts are visible only to the PR team.") }}</p>
        </div>
        <div class="be-actions">
            <a class="b-btn" href="{{ route('blog.home') }}">{{ __('View the blog') }}</a>
            <a class="b-btn b-btn-primary" href="{{ route('blog.create') }}">{{ __('New article') }}</a>
        </div>
    </div>

    @if ($notice)
        <div class="b-notice" role="status">{{ $notice }}</div>
    @endif
    @if ($problem)
        <div class="b-notice b-notice-warn" role="alert">{{ $problem }}</div>
    @endif
    @if (session('blog_notice'))
        <div class="b-notice" role="status">{{ session('blog_notice') }}</div>
    @endif

    <div class="b-filters">
        <label class="b-field" style="flex:1 1 14rem">{{ __('Search') }}
            <input class="b-input" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Title, place or tag') }}">
        </label>
        <label class="b-field">{{ __('Status') }}
            <select class="b-select" wire:model.live="status">
                <option value="">{{ __('All') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="b-field">{{ __('Kind') }}
            <select class="b-select" wire:model.live="category">
                <option value="">{{ __('All kinds') }}</option>
                @foreach ($categories as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        @if ($search !== '' || $status !== '' || $category !== '')
            <div style="align-self:end"><button type="button" class="b-btn b-btn-ghost" wire:click="resetFilters">{{ __('Clear filters') }}</button></div>
        @endif
    </div>

    <div class="b-table-wrap">
        <table class="b-table">
            <caption class="b-sr">{{ __('Blog articles') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('Article') }}</th>
                    @if ($showRegion)
                        <th scope="col">{{ __('Region') }}</th>
                    @endif
                    <th scope="col">{{ __('Kind') }}</th>
                    <th scope="col">{{ __('Status') }}</th>
                    <th scope="col">{{ __('Activity date') }}</th>
                    <th scope="col">{{ __('Updated') }}</th>
                    <th scope="col"><span class="b-sr">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($posts as $post)
                    <tr wire:key="blog-manage-{{ $post->id }}">
                        <td class="b-title">
                            <a href="{{ $post->url() }}">{{ $post->title }}</a>
                            @if ($post->is_pinned)
                                <span class="b-tag-pin">{{ __('Pinned') }}</span>
                            @endif
                            <div style="font-size:.8rem;color:var(--b-ink-3)">{{ $post->author?->full_name }}</div>
                        </td>
                        @if ($showRegion)
                            <td>{{ $post->region?->region_name }}</td>
                        @endif
                        <td>{{ $post->categoryLabel() }}</td>
                        <td><span class="b-status b-status-{{ $post->status }}">{{ $statuses[$post->status] ?? $post->status }}</span></td>
                        <td style="white-space:nowrap">{{ $post->event_date?->format('d M Y') ?? '—' }}</td>
                        <td style="white-space:nowrap">{{ $post->updated_at->format('d M Y') }}</td>
                        <td>
                            <div class="b-row-actions">
                                <a class="b-btn" href="{{ route('blog.edit', $post) }}">{{ __('Edit') }}</a>
                                @if ($post->isPublished())
                                    @if ($post->is_pinned)
                                        <button type="button" class="b-btn" wire:click="unpin({{ $post->id }})">{{ __('Unpin') }}</button>
                                    @else
                                        <button type="button" class="b-btn" wire:click="pin({{ $post->id }})">{{ __('Pin') }}</button>
                                    @endif
                                    <button type="button" class="b-btn" wire:click="unpublish({{ $post->id }})" wire:confirm="Move this article back to drafts? Staff will no longer be able to read it.">{{ __('Unpublish') }}</button>
                                @else
                                    <button type="button" class="b-btn" wire:click="publish({{ $post->id }})">{{ __('Publish') }}</button>
                                @endif
                                <button type="button" class="b-btn b-danger-btn" wire:click="delete({{ $post->id }})" wire:confirm="Delete this article for good? This cannot be undone.">{{ __('Delete') }}</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showRegion ? 7 : 6 }}" class="b-empty" style="padding:2.5rem 1rem">
                            {{ __('No articles yet.') }}
                            <a class="b-btn b-btn-primary" href="{{ route('blog.create') }}">{{ __('Write the first article') }}</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($posts->hasPages())
        <nav class="b-pager" aria-label="{{ __('Pages of articles') }}">
            <button type="button" class="b-btn" wire:click="previousPage" @disabled($posts->onFirstPage())>← {{ __('Newer') }}</button>
            <span>{{ __('Page :page of :last', ['page' => $posts->currentPage(), 'last' => $posts->lastPage()]) }}</span>
            <button type="button" class="b-btn" wire:click="nextPage" @disabled(! $posts->hasMorePages())>{{ __('Older') }} →</button>
        </nav>
    @endif
</div>
