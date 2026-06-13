<?php

namespace App\Livewire\Transport;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Department;
use App\Services\ReportsService;
use Livewire\Component;

class Reports extends Component
{
    use EnforcesModuleAccess;

    public string $datePreset = 'this_month';

    public string $customFrom = '';

    public string $customTo = '';

    public string $departmentId = '';

    public array $payload = [];

    public string $fromLabel = '';

    public string $toLabel = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('transport');
        $this->authorizeReports();
        $this->loadReportData();
    }

    public function updated($name): void
    {
        if (in_array($name, ['datePreset', 'customFrom', 'customTo', 'departmentId'], true)) {
            $this->loadReportData();
            $this->dispatch('transport-report-data-updated', charts: $this->payload);
        }
    }

    public function exportQuery(): array
    {
        return [
            'date_preset' => $this->datePreset,
            'custom_from' => $this->customFrom ?: null,
            'custom_to' => $this->customTo ?: null,
            'department_id' => $this->departmentId ?: null,
        ];
    }

    protected function loadReportData(): void
    {
        /** @var ReportsService $reports */
        $reports = app(ReportsService::class);
        [$from, $to] = $reports->resolveDateRange($this->datePreset, $this->customFrom ?: null, $this->customTo ?: null);

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $this->fromLabel = $from->format('d M Y');
        $this->toLabel = $to->format('d M Y');
        $this->payload = $reports->reportPayload($from, $to, $this->departmentId !== '' ? (int) $this->departmentId : null);
    }

    protected function authorizeReports(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin', 'transport_manager') && ! $user->hasPermission('transport.view_reports'))) {
            abort(403);
        }
    }

    public function render()
    {
        return view('livewire.transport.reports', [
            'departments' => Department::query()->orderBy('department_name')->get(),
            'exportQuery' => $this->exportQuery(),
        ]);
    }
}
