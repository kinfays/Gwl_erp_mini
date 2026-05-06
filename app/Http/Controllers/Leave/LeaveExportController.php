<?php

namespace App\Http\Controllers\Leave;

use App\Exports\Leave\ApprovedLeavesExport;
use App\Exports\Leave\ManagerTeamLeaveExport;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Maatwebsite\Excel\Facades\Excel;

class LeaveExportController extends Controller
{
    public function approvedExcel()
    {
        $user = auth()->user();

        Audit::log(
            action: 'leave_export_excel',
            module: 'leave',
            targetType: 'approved_leaves',
            targetId: now()->year,
            metadata: ['by' => $user->id]
        );

        return Excel::download(
            new ApprovedLeavesExport($user),
            'approved_leaves_'.now()->format('Y_m_d').'.xlsx'
        );
    }

    public function teamExcel()
    {
        $user = auth()->user();
        $employee = $user?->employee ?? $user?->employeeByStaffId;

        abort_if(! $employee, 403, 'Employee profile is required for team leave export.');

        Audit::log(
            action: 'leave_export_excel',
            module: 'leave',
            targetType: 'team_leaves',
            targetId: $employee->id,
            metadata: ['by' => $user->id]
        );

        return Excel::download(
            new ManagerTeamLeaveExport($employee->id),
            'team_leave_'.now()->format('Y_m_d').'.xlsx'
        );
    }
}
