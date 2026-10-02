<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\User;
use App\Services\Leave\CompulsoryLeaveService;
use Livewire\Component;

/**
 * The Compulsory Leave page: how many days come off the gross annual entitlement of Head Office and regional office staff
 * in a year, and when the shutdown starts and staff return. Head Office HR, Global Admin and super_admin only.
 */
class CompulsoryLeave extends Component
{
    use EnforcesModuleAccess;

    public int $year;

    public string $days = '';

    public string $startDate = '';

    public string $resumeDate = '';

    public string $notes = '';

    public function mount(CompulsoryLeaveService $compulsory): void
    {
        $this->enforceLivewireModule('leave');
        abort_unless($compulsory->canManage($this->actor()), 403);

        $this->year = (int) now()->format('Y');
        $this->load($compulsory);
    }

    public function updatedYear(CompulsoryLeaveService $compulsory): void
    {
        $this->year = in_array((int) $this->year, $this->years(), true) ? (int) $this->year : (int) now()->format('Y');
        $this->resetValidation();
        $this->load($compulsory);
    }

    public function save(CompulsoryLeaveService $compulsory): void
    {
        $validated = $this->validate([
            'days' => ['required', 'integer', 'min:0', 'max:60'],
            'startDate' => ['nullable', 'date'],
            'resumeDate' => ['nullable', 'date', 'after:startDate'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'days.required' => 'Enter how many days come off the entitlement.',
            'resumeDate.after' => 'Staff return after the shutdown starts: pick a later date.',
        ]);

        $result = $compulsory->save($this->actor(), $this->year, [
            'days' => (int) $validated['days'],
            'start_date' => $validated['startDate'] ?: null,
            'resume_date' => $validated['resumeDate'] ?: null,
            'notes' => $validated['notes'] ?: null,
        ]);

        $message = "Compulsory leave for {$this->year} saved.";

        if ($result['recalculated']) {
            $message .= " {$result['recalculated']['updated']} staff entitlement(s) recalculated.";
        }

        session()->flash('success', $message);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function render(CompulsoryLeaveService $compulsory)
    {
        return view('livewire.leave.compulsory-leave', [
            'status' => $compulsory->statusFor($this->year),
            'coverage' => $compulsory->coverage(),
            'years' => $this->years(),
            'defaultDays' => (int) config('gwl.leave_compulsory_default_days', 11),
            'seniorGross' => (int) config('gwl.leave_annual_days.senior_and_management', 36),
        ]);
    }

    /** This year and next: the year being planned, and the one in force. */
    protected function years(): array
    {
        $current = (int) now()->format('Y');

        return [$current, $current + 1];
    }

    protected function load(CompulsoryLeaveService $compulsory): void
    {
        $status = $compulsory->statusFor($this->year);
        $period = $status['period'];

        $this->days = (string) ($period?->days ?? $status['days']);
        $this->startDate = $period?->start_date?->toDateString() ?? '';
        $this->resumeDate = $period?->resume_date?->toDateString() ?? '';
        $this->notes = (string) ($period?->notes ?? '');
    }

    protected function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($user, 403, 'Unauthorized.');

        return $user;
    }
}
