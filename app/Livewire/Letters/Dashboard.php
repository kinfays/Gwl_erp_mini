<?php

namespace App\Livewire\Letters;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\LetterStatusLog;
use App\Models\MailLetter;
use App\Services\Letters\LetterWorkflowService;
use Livewire\Component;

class Dashboard extends Component
{
    use EnforcesModuleAccess;

    public function mount(): void
    {
        $this->enforceLivewireModule('letters');
    }

    public function render(LetterWorkflowService $workflow)
    {
        $employee = $this->employee();

        if (! $employee) {
            return view('livewire.letters.dashboard', [
                'missingEmployee' => true,
                'stats' => ['total' => 0, 'pending' => 0, 'dispatched' => 0, 'closed' => 0],
                'attentionLetters' => collect(),
            ]);
        }

        $visible = $workflow->visibleLettersQuery($employee);

        $stats = [
            'total' => (clone $visible)->count(),
            'pending' => (clone $visible)->whereHas('statusLogs', function ($query) use ($employee) {
                $query->where('secretariat_id', $employee->id)
                    ->whereIn('status', ['Received', 'In Review'])
                    ->where('is_closed', false);
            })->count(),
            'dispatched' => (clone $visible)->whereHas('statusLogs', function ($query) use ($employee) {
                $query->where('secretariat_id', $employee->id)
                    ->where('status', 'Dispatched')
                    ->where('is_closed', false);
            })->count(),
            'closed' => (clone $visible)->whereHas('statusLogs', function ($query) use ($employee) {
                $query->where('secretariat_id', $employee->id)
                    ->where('is_closed', true);
            })->count(),
        ];

        $attentionLetters = MailLetter::query()
            ->with(['region', 'memoSender', 'statusLogs.secretariat'])
            ->whereHas('statusLogs', function ($query) use ($employee) {
                $query->where('secretariat_id', $employee->id)
                    ->whereIn('status', ['Received', 'In Review'])
                    ->where('is_closed', false);
            })
            ->latest()
            ->limit(8)
            ->get();

        return view('livewire.letters.dashboard', [
            'missingEmployee' => false,
            'stats' => $stats,
            'attentionLetters' => $attentionLetters,
        ]);
    }

    protected function employee(): ?Employee
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }
}
