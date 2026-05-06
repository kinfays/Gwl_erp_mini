<?php

namespace App\Livewire\Notifications;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class GeneralBell extends Component
{
    public bool $open = false;

    public string $tab = 'unread';

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'all' ? 'all' : 'unread';
    }

    public function markAllRead(): void
    {
        $user = auth()->user();

        if (! $user || ! Schema::hasTable('notifications')) {
            return;
        }

        $user->unreadNotifications()
            ->where(function ($query) {
                $query->where('data->module', '!=', 'letters')
                    ->orWhereNull('data->module');
            })
            ->update(['read_at' => now()]);

        $this->dispatch('toast', type: 'success', message: 'Notifications marked as read.');
    }

    public function openNotification(string $notificationId)
    {
        $user = auth()->user();

        abort_if(! $user || ! Schema::hasTable('notifications'), 404);

        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()
            ->where(function ($query) {
                $query->where('data->module', '!=', 'letters')
                    ->orWhereNull('data->module');
            })
            ->findOrFail($notificationId);

        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        if ($url) {
            return redirect()->to($url);
        }

        return redirect()->route('dashboard');
    }

    public function render()
    {
        $user = auth()->user();
        $notifications = collect();
        $unreadCount = 0;

        if ($user && Schema::hasTable('notifications')) {
            $base = $user->notifications()
                ->where(function ($query) {
                    $query->where('data->module', '!=', 'letters')
                        ->orWhereNull('data->module');
                });

            $unreadCount = (clone $base)->whereNull('read_at')->count();

            $notifications = (clone $base)
                ->when($this->tab === 'unread', fn ($query) => $query->whereNull('read_at'))
                ->latest()
                ->limit(10)
                ->get();
        }

        return view('livewire.notifications.general-bell', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
            'pollSeconds' => max(10, (int) config('gwl.leave_notification_poll_seconds', 90)),
        ]);
    }
}
