<?php

namespace App\Livewire\Letters;

use App\Models\Employee;
use App\Models\LetterNotification;
use Livewire\Component;

class Notifications extends Component
{
    public bool $open = false;

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function openNotification(int $notificationId)
    {
        $employee = $this->employee();

        abort_if(! $employee, 403);

        $notification = LetterNotification::query()
            ->where('secretariat_id', $employee->id)
            ->findOrFail($notificationId);

        $notification->update(['is_read' => true]);

        return redirect()->route('letters.active', [
            'letter' => $notification->letter_id,
            'prompt' => 1,
        ]);
    }

    public function render()
    {
        $employee = $this->employee();

        $notifications = $employee
            ? LetterNotification::query()
                ->with('letter')
                ->where('secretariat_id', $employee->id)
                ->latest()
                ->limit(8)
                ->get()
            : collect();

        return view('livewire.letters.notifications', [
            'notifications' => $notifications,
            'unreadCount' => $notifications->where('is_read', false)->count(),
        ]);
    }

    protected function employee(): ?Employee
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }
}
