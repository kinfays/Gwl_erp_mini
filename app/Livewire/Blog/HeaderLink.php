<?php

namespace App\Livewire\Blog;

use App\Services\Blog\BlogReadService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Defer;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "Blog" link at the right of the ERP header, with a badge of the published articles of the person's own region they have
 * not read yet (docs/14-module-regional-blog.md, "Unread articles"). It is the whole link, so its accessible name carries the
 * count ("Regional Blog, 3 unread articles").
 *
 * It sits in the header of EVERY page, so it is deferred (the page never waits for it: the same link, without a number, is
 * the placeholder), the number is cached per person by BlogReadService, and it refreshes by itself: a poll about once a minute
 * plus a refresh when the browser tab gets focus, so reading an article in the blog tab updates this tab's badge when the
 * person switches back. The layout mounts it only for someone the navigation already offers the Blog link to (flag on, module
 * access); BlogReadService asks again and does no blog query otherwise.
 */
#[Defer]
class HeaderLink extends Component
{
    /** Where the link goes and its icon, chosen by the navigation (ErpNavigation::blogLink()). The browser cannot change them. */
    #[Locked]
    public string $url = '';

    #[Locked]
    public string $icon = 'scroll-text';

    public function mount(string $url, string $icon = 'scroll-text'): void
    {
        $this->url = $url;
        $this->icon = $icon;
    }

    /**
     * What the deferred component shows until it has loaded: the same link without a number, so the header does not jump.
     * A deferred component is not mounted yet, so its properties are empty: the mount parameters arrive here instead.
     *
     * @param  array<string, mixed>  $params
     */
    public function placeholder(array $params = []): \Illuminate\Contracts\View\View
    {
        return view('livewire.blog.header-link', [
            'url' => (string) ($params['url'] ?? route('blog.home')),
            'icon' => (string) ($params['icon'] ?? 'scroll-text'),
            'count' => 0,
            'display' => '',
            'live' => false,
            'pollSeconds' => 0,
        ]);
    }

    /** The poll and the focus handler call this: any request re-renders the component with the current number. */
    public function refresh(): void
    {
        // Nothing to do: render() reads the (short-cached) number.
    }

    public function render()
    {
        $user = Auth::user();
        $reads = app(BlogReadService::class);
        $count = $user ? $reads->unreadCount($user) : 0;

        return view('livewire.blog.header-link', [
            'url' => $this->url,
            'icon' => $this->icon,
            'count' => $count,
            'display' => $reads->display($count),
            'live' => true,
            // As often as the number can change: never faster than the cache keeps it.
            'pollSeconds' => max(30, (int) config('gwl.blog_unread_cache_seconds', 60)),
        ]);
    }
}
