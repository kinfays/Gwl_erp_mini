<?php

namespace App\Listeners\Blog;

use App\Models\User;
use App\Services\Blog\BlogAnnouncementService;
use Illuminate\Auth\Events\Login;

/**
 * A person has just been authenticated: let the first ERP page show them the active login announcements of their region
 * (docs/14-module-regional-blog.md). This only sets a session flag; the page that consumes it does the work, so a login costs
 * no announcement query.
 *
 * Laravel fires `Login` both for the login form and when a remember-me cookie re-authenticates a new browser session.
 * The form is a POST to /login; anything else is a re-authentication, which BlogAnnouncementService throttles.
 *
 * Found by Laravel's event discovery (the handle() type-hint): do not also register it in AppServiceProvider.
 */
class ArmLoginAnnouncements
{
    public function __construct(protected BlogAnnouncementService $announcements) {}

    public function handle(Login $event): void
    {
        $request = request();

        // No session (the console, a queued job): there is nowhere to remember it.
        if (! $event->user instanceof User || ! $request->hasSession()) {
            return;
        }

        $this->announcements->arm($event->user, $request->isMethod('POST') && $request->is('login'));
    }
}
