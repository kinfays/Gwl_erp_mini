<?php

namespace App\Livewire\Blog\Concerns;

use App\Models\User;
use App\Services\Blog\BlogVisibility;
use Illuminate\Support\Facades\Auth;

/**
 * The Livewire-side wrapper of BlogVisibility. A separate trait from the Assets, Commercial and Health & Safety ones on
 * purpose (CLAUDE.md: scoping traits are not shared), and it holds no rule of its own: the rules live in BlogVisibility,
 * which the controller and the publishing service call too.
 */
trait ScopesBlogByActor
{
    protected function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function visibility(): BlogVisibility
    {
        return app(BlogVisibility::class);
    }

    /** Passes only for someone who may write to a blog (PR Officer with a region, or super_admin). */
    protected function guardBlogManager(): void
    {
        abort_unless(Auth::user() && $this->visibility()->canManage($this->actor()), 403, 'You do not have permission to manage blog posts.');
    }
}
