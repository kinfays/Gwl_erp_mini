<?php

namespace App\Livewire\Letters;

use App\Models\Employee;
use App\Models\LetterNotification;
use Livewire\Component;

class Notifications extends Component
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
        $employee = $this->employee();

        if (! $employee) {
            return;
        }

        LetterNotification::query()
            ->where('secretariat_id', $employee->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $this->dispatch('toast', type: 'success', message: 'Letter notifications marked as read.');
    }

    public function openNotification(int $notificationId)
    {
        $employee = $this->employee();

        abort_if(! $employee, 403);

        $notification = LetterNotification::query()
            ->where('secretariat_id', $employee->id)
            ->findOrFail($notificationId);

        $notification->update(['is_read' => true]);

        $prompt = $notification->letter
            ? $notification->letter->routingHistories()
                ->where('to_secretariat_id', $employee->id)
                ->where('received_confirm', false)
                ->exists()
            : false;

        return redirect()->route('letters.active', [
            'letter' => $notification->letter_id,
            'prompt' => $prompt ? 1 : 0,
        ]);
    }

    public function render()
    {
        $employee = $this->employee();

        $notifications = collect();
        $unreadCount = 0;

        if ($employee) {
            $base = LetterNotification::query()
                ->with('letter')
                ->where('secretariat_id', $employee->id);

            $unreadCount = (clone $base)->where('is_read', false)->count();

            $notifications = (clone $base)
                ->when($this->tab === 'unread', fn ($query) => $query->where('is_read', false))
                ->latest()
                ->limit(10)
                ->get();
        }

        return view('livewire.letters.notifications', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
            'pollSeconds' => max(10, (int) config('gwl.leave_notification_poll_seconds', 90)),
        ]);
    }

    protected function employee(): ?Employee
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }
}
