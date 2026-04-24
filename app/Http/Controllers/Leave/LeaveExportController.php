<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Exports\Leave\{
    ApprovedLeavesExport,
    ManagerTeamLeaveExport
};
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
            'approved_leaves_' . now()->format('Y_m_d') . '.xlsx'
        );
    }

    public function teamExcel()
    {
        $user = auth()->user();

        Audit::log(
            action: 'leave_export_excel',
            module: 'leave',
            targetType: 'team_leaves',
            targetId: $user->employee->id,
            metadata: ['by' => $user->id]
        );

        return Excel::download(
            new ManagerTeamLeaveExport($user->employee->id),
            'team_leave_' . now()->format('Y_m_d') . '.xlsx'
        );
    }
}