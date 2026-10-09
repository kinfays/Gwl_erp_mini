<div>
    <x-ui.page-header title="Manage posts" description="Write, publish and pin the articles of your region's blog. Drafts are visible only to the PR team.">
        <x-slot:actions>
            <x-ui.button :href="route('blog.home')" icon="eye">View blog</x-ui.button>
            <x-ui.button :href="route('blog.create')" variant="primary" icon="circle-plus">New article</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($notice)
        <x-ui.alert tone="success" role="status" class="dash-row">{{ $notice }}</x-ui.alert>
    @endif
    @if ($problem)
        <x-ui.alert tone="danger" role="alert" class="dash-row">{{ $problem }}</x-ui.alert>
    @endif

    <x-ui.card class="dash-row">
        <div class="ui-form-grid">
            <x-ui.input label="Search" wire:model.live.debounce.400ms="search" icon="search" placeholder="Title, place or tag" />
            <x-ui.select label="Status" wire:model.live="status">
                <option value="">All</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Kind" wire:model.live="category">
                <option value="">All kinds</option>
                @foreach ($categories as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </div>
        @if ($search !== '' || $status !== '' || $category !== '')
            <div class="ui-form-actions" style="margin-top:10px">
                <x-ui.button size="sm" variant="ghost" wire:click="resetFilters">Clear filters</x-ui.button>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card :padded="false" class="dash-row">
        <x-ui.table label="Blog articles">
            <x-slot:head>
                <tr>
                    <th>Article</th>
                    @if ($showRegion)
                        <th>Region</th>
                    @endif
                    <th>Kind</th>
                    <th>Status</th>
                    <th>Activity date</th>
                    <th>Updated</th>
                    <th><span class="sr-only-text">Actions</span></th>
                </tr>
            </x-slot:head>
            @forelse ($posts as $post)
                <tr wire:key="blog-manage-{{ $post->id }}">
                    <td>
                        <a href="{{ route('blog.show', $post) }}">{{ $post->title }}</a>
                        @if ($post->is_pinned)
                            <x-ui.badge tone="lagoon">Pinned</x-ui.badge>
                        @endif
                        <div class="cell-muted">{{ $post->author?->full_name }}</div>
                    </td>
                    @if ($showRegion)
                        <td>{{ $post->region?->region_name }}</td>
                    @endif
                    <td>{{ $post->categoryLabel() }}</td>
                    <td>
                        <x-ui.badge :tone="$post->isPublished() ? 'success' : 'neutral'">{{ $statuses[$post->status] ?? $post->status }}</x-ui.badge>
                    </td>
                    <td class="nowrap cell-muted">{{ $post->event_date?->format('d M Y') ?? '—' }}</td>
                    <td class="nowrap cell-muted">{{ $post->updated_at->format('d M Y') }}</td>
                    <td class="nowrap">
                        <x-ui.button size="sm" variant="ghost" :href="route('blog.edit', $post)" icon="pencil">Edit</x-ui.button>
                        @if ($post->isPublished())
                            @if ($post->is_pinned)
                                <x-ui.button size="sm" variant="ghost" wire:click="unpin({{ $post->id }})">Unpin</x-ui.button>
                            @else
                                <x-ui.button size="sm" variant="ghost" wire:click="pin({{ $post->id }})">Pin</x-ui.button>
                            @endif
                            <x-ui.button size="sm" variant="ghost" wire:click="unpublish({{ $post->id }})" wire:confirm="Move this article back to drafts? Staff will no longer be able to read it.">Unpublish</x-ui.button>
                        @else
                            <x-ui.button size="sm" variant="ghost" wire:click="publish({{ $post->id }})">Publish</x-ui.button>
                        @endif
                        <x-ui.button size="sm" variant="ghost" icon="trash-2" wire:click="delete({{ $post->id }})" wire:confirm="Delete this article for good? This cannot be undone.">Delete</x-ui.button>
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="$showRegion ? 7 : 6" icon="scroll-text" title="No articles yet.">
                    <x-ui.button size="sm" :href="route('blog.create')">Write the first article</x-ui.button>
                </x-ui.empty-row>
            @endforelse

            @if ($posts->hasPages())
                <x-slot:footer>
                    <div class="pager-end">{{ $posts->links() }}</div>
                </x-slot:footer>
            @endif
        </x-ui.table>
    </x-ui.card>
</div>
