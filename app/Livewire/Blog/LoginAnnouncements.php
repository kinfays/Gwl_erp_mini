<?php

namespace App\Livewire\Blog;

use App\Models\BlogPost;
use App\Services\Blog\BlogAnnouncementService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The window of active login announcements, mounted once in the main ERP layout (never on the blog tab).
 *
 * The Login event only set a session flag (ArmLoginAnnouncements). The first ERP page that renders consumes that flag HERE, in
 * mount(): that is what makes the window appear once per login and not again on a refresh or the next page. When the flag is
 * not set, which is almost always, this component does nothing and runs no query.
 *
 * What is shown is BlogAnnouncementService::activeFor(): published, active today, the person's own region (BlogVisibility),
 * not dismissed, at most gwl.blog_login_announcements_max, newest first. The list is `#[Locked]` plain data fixed at mount, so
 * the browser cannot swap an article in; "Don't show again" re-checks the article against the person's own scope.
 */
class LoginAnnouncements extends Component
{
    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $items = [];

    public int $index = 0;

    public bool $open = false;

    public function mount(BlogAnnouncementService $announcements): void
    {
        $user = Auth::user();

        // Not just logged in: nothing to do and nothing to ask the database.
        if (! $user || ! $announcements->consumeArmed()) {
            return;
        }

        $this->items = $announcements->activeFor($user)
            ->map(fn (BlogPost $post) => $this->present($post))
            ->values()
            ->all();

        $this->open = $this->items !== [];
    }

    /** "Next" in the stepper (or the end of it). */
    public function next(): void
    {
        $this->advance();
    }

    /** "Close": hide every announcement of this window for the rest of this login session. */
    public function close(BlogAnnouncementService $announcements): void
    {
        $announcements->hideForSession(array_column($this->items, 'id'));
        $this->open = false;
    }

    /** "Don't show again": never again for this person; then on to the next one. */
    public function dismissCurrent(BlogAnnouncementService $announcements): void
    {
        $item = $this->items[$this->index] ?? null;

        if ($item !== null && Auth::user()) {
            $announcements->dismiss(Auth::user(), (int) $item['id']);
        }

        $this->advance();
    }

    /** "Read full article" opened it in a new tab: that page marks it read; here it is only put away for this session. */
    public function opened(BlogAnnouncementService $announcements): void
    {
        $item = $this->items[$this->index] ?? null;

        if ($item !== null) {
            $announcements->hideForSession([(int) $item['id']]);
        }

        $this->advance();
    }

    protected function advance(): void
    {
        if ($this->index + 1 < count($this->items)) {
            $this->index++;

            return;
        }

        $this->open = false;
    }

    /** @return array<string, mixed> only what the window prints (all of it readable by this person) */
    protected function present(BlogPost $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'kind' => $post->categoryLabel(),
            'teaser' => $post->teaser(240),
            'thumb' => $post->hasCover() ? $post->coverUrl('thumb') : null,
            'url' => $post->url(),
            'event' => $post->event_date?->format('j M Y'),
            'venue' => $post->venue,
            'district' => $post->district?->district_name,
            'until' => $post->announce_until?->format('j M Y'),
        ];
    }

    public function render()
    {
        return view('livewire.blog.login-announcements');
    }
}
