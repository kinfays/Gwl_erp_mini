<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Services\Staff\StaffLeaveReportService;
use Livewire\Component;

class LeaveReports extends Component
{
    use EnforcesModuleAccess;

    public string $datePreset = 'this_month';

    public string $customFrom = '';

    public string $customTo = '';

    public string $departmentId = '';

    public string $regionId = '';

    public string $districtId = '';

    public array $payload = [];

    public string $fromLabel = '';

    public string $toLabel = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('staff');
        $this->authorizeReports();
        $this->loadReportData();
    }

    public function updated($name): void
    {
        if ($name === 'regionId') {
            $this->districtId = '';
        }

        if (in_array($name, ['datePreset', 'customFrom', 'customTo', 'departmentId', 'regionId', 'districtId'], true)) {
            $this->loadReportData();
            $this->dispatch('staff-leave-report-data-updated', charts: $this->payload);
        }
    }

    protected function loadReportData(): void
    {
        /** @var StaffLeaveReportService $reports */
        $reports = app(StaffLeaveReportService::class);
        [$from, $to] = $reports->resolveDateRange($this->datePreset, $this->customFrom ?: null, $this->customTo ?: null);

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $this->fromLabel = $from->format('d M Y');
        $this->toLabel = $to->format('d M Y');
        $this->payload = $reports->reportPayload(
            auth()->user(),
            $from,
            $to,
            $this->departmentId !== '' ? (int) $this->departmentId : null,
            $this->regionId !== '' ? (int) $this->regionId : null,
            $this->districtId !== '' ? (int) $this->districtId : null
        );
    }

    protected function authorizeReports(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin') && ! $user->hasPermission('staff.view_reports'))) {
            abort(403);
        }

        $employee = $user->employee ?? $user->employeeByStaffId;

        if ($user->hasRoles('hr_region') && ! $user->hasRoles('super_admin', 'hr_headoffice') && ! $employee?->region_id) {
            abort(403, 'Employee region is required for regional staff reports.');
        }
    }

    public function render(StaffLeaveReportService $reports)
    {
        return view('livewire.staff.leave-reports', [
            'filters' => $reports->filterOptions(
                auth()->user(),
                $this->regionId !== '' ? (int) $this->regionId : null
            ),
        ]);
    }
}
