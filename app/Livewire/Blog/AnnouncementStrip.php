<?php

namespace App\Livewire\Blog;

use App\Services\Blog\BlogAnnouncementService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The slim strip at the top of the blog home: the region's active login announcements, each with a Dismiss button that hides it
 * for the rest of this session. What is listed is BlogAnnouncementService::activeFor() (published, active today, the person's
 * own region through BlogVisibility, not "don't show again"-ed), so the strip can never show more than the login window would.
 */
class AnnouncementStrip extends Component
{
    /** Only narrows for someone who sees every region (super_admin picked one); ignored for everyone else. The browser cannot change it. */
    #[Locked]
    public ?int $regionId = null;

    public function mount(?int $regionId = null): void
    {
        $this->regionId = $regionId;
    }

    public function dismiss(int $postId, BlogAnnouncementService $announcements): void
    {
        $announcements->hideForSession([$postId]);
    }

    public function render(BlogAnnouncementService $announcements)
    {
        $user = Auth::user();

        return view('livewire.blog.announcement-strip', [
            'announcements' => $user ? $announcements->activeFor($user, null, $this->regionId) : collect(),
        ]);
    }
}
