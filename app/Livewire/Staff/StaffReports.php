<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Services\Staff\StaffReportService;
use Livewire\Component;

class StaffReports extends Component
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
            $this->dispatch('staff-report-data-updated', charts: $this->payload);
        }
    }

    protected function loadReportData(): void
    {
        /** @var StaffReportService $reports */
        $reports = app(StaffReportService::class);
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
        app(StaffReportService::class)->authorize(auth()->user());
    }

    public function render(StaffReportService $reports)
    {
        return view('livewire.staff.staff-reports', [
            'exportUrl' => route('staff.reports.export', array_filter([
                'datePreset' => $this->datePreset,
                'customFrom' => $this->customFrom,
                'customTo' => $this->customTo,
                'departmentId' => $this->departmentId,
                'regionId' => $this->regionId,
                'districtId' => $this->districtId,
            ], fn ($value) => $value !== '')),
            'filters' => $reports->filterOptions(
                auth()->user(),
                $this->regionId !== '' ? (int) $this->regionId : null
            ),
        ]);
    }
}
