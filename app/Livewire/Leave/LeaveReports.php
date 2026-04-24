<?php

use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Leave\ApprovedLeavesExport;

public string $format = 'xlsx';

public function export()
{
    $user = auth()->user();
    $filename = 'leave_report_' . now()->format('Y_m_d');

    return Excel::download(
        new ApprovedLeavesExport($user),
        $filename . '.' . $this->format
    );
}