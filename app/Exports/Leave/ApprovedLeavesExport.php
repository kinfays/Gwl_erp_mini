<?php

namespace App\Exports\Leave;

use App\Models\LeaveRequest;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ApprovedLeavesExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function __construct(protected $user) {}

    public function collection(): Collection
    {
        $q = LeaveRequest::query()
            ->with('requester')
            ->where('leave_status', 'Approved');

        if ($this->user->isHrUser() && ! $this->user->isHeadOfficeHr()) {
            $employee = $this->user->employee ?? $this->user->employeeByStaffId;

            abort_if(! $employee, 403, 'Employee profile is required for regional leave export.');

            $q->where('region_id', $employee->region_id);
        }

        return $q->get()->map(fn ($r) => [
            'Employee' => $r->requester->full_name,
            'Leave Type' => $r->leave_type,
            'Start Date' => $r->start_date->toDateString(),
            'End Date' => $r->end_date->toDateString(),
            'Days' => $r->total_days_applied,
            'Approved On' => $r->updated_at->toDateString(),
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
            'Approved On',
        ];
    }
}
