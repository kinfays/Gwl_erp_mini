<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Services\Hr\HrAnalyticsService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The HR analytics page: milestones, headcount and turnover, distribution, exit reasons, and who has no grade yet.
 * What each viewer sees is decided by HrAnalyticsService::scopeFor(): Head Office HR, Global Admin and super_admin see every
 * region, regional HR their own, everyone else is refused.
 */
class HrAnalytics extends Component
{
    use EnforcesModuleAccess;

    #[Url(except: '')]
    public string $regionId = '';

    #[Url(except: '')]
    public string $departmentId = '';

    public array $analytics = [];

    public function mount(HrAnalyticsService $service): void
    {
        $this->enforceLivewireModule('leave');
        $service->scopeFor(auth()->user());
        $this->load($service);
    }

    public function updated($name, HrAnalyticsService $service): void
    {
        if (in_array($name, ['regionId', 'departmentId'], true)) {
            $this->load($service);
            $this->dispatch('hr-analytics-updated', charts: $this->charts());
        }
    }

    public function render(HrAnalyticsService $service)
    {
        return view('livewire.leave.hr-analytics', [
            'filters' => $service->filterOptions(auth()->user()),
            'charts' => $this->charts(),
        ]);
    }

    protected function load(HrAnalyticsService $service): void
    {
        $this->analytics = $service->analytics(auth()->user(), [
            'region_id' => $this->regionId,
            'department_id' => $this->departmentId,
        ]);
    }

    /** The series each chart draws, in the {labels, data} shape x-ui.chart reads from a browser event. */
    protected function charts(): array
    {
        $a = $this->analytics;
        $pair = fn (array $rows) => ['labels' => array_column($rows, 'label'), 'data' => array_column($rows, 'count')];

        return [
            'departments' => $pair(array_slice($a['distribution']['departments'] ?? [], 0, 12)),
            'regions' => $pair($a['distribution']['regions'] ?? []),
            'locations' => $pair($a['distribution']['locations'] ?? []),
            'categories' => $pair($a['distribution']['categories'] ?? []),
            'employment' => $pair($a['distribution']['employment'] ?? []),
            'ageBands' => $pair($a['age']['bands'] ?? []),
            'exitReasons' => $pair($a['exit_reasons']['rows'] ?? []),
        ];
    }
}
