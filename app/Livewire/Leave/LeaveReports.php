<?php

namespace App\Livewire\Leave;

use App\Exports\Leave\ApprovedLeavesExport;
use App\Livewire\Concerns\EnforcesModuleAccess;
use Maatwebsite\Excel\Facades\Excel;
use Livewire\Component;

class LeaveReports extends Component
{
    use EnforcesModuleAccess;

    public string $format = 'xlsx';

    public function mount(): void
    {
        $this->enforceLivewireModule('leave');

        $user = auth()->user();

        if (! $user->hasPermission('leave.export') && ! $user->hasRoles('super_admin', 'admin', 'hr_headoffice', 'hr_region')) {
            abort(403);
        }
    }

    public function export()
    {
        $user = auth()->user();
        $filename = 'leave_report_' . now()->format('Y_m_d_His');

        return Excel::download(
            new ApprovedLeavesExport($user),
            $filename . '.' . $this->format
        );
    }

    public function render()
    {
        return view('livewire.leave.leave-reports');
    }
}
