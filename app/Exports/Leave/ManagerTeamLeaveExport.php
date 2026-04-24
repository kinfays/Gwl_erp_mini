<?php

namespace App\Exports\Leave;

use App\Models\LeaveRequest;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\{
    FromCollection,
    WithHeadings,
    ShouldAutoSize
};

class ManagerTeamLeaveExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(protected int $managerId) {}

    public function collection(): Collection
    {
        return LeaveRequest::query()
            ->with('requester')
            ->where('manager_id', $this->managerId)
            ->where('leave_status', 'Approved')
            ->get()
            ->map(fn ($r) => [
                'Employee' => $r->requester->full_name,
                'Leave Type' => $r->leave_type,
                'Start Date' => $r->start_date->toDateString(),
                'End Date' => $r->end_date->toDateString(),
                'Days' => $r->total_days_applied,
            ]);
    }

    public function headings(): array
    {
        return [
            'Employee',
            'Leave Type',
            'Start Date',
            'End Date',
            'Days',
        ];
    }
}