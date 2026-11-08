<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvAnalyticsService;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Reports and charts for the vouchers of ONE location (the user's own; super_admin picks), in the Finance and the Audit modules
 * (`module`). It shows what PvAnalyticsService returns and works nothing out itself; the Audit door sees what Audit sees (never a
 * draft). Needs {module}.view_reports; the export buttons also need {module}.export.
 */
class Reports extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;

    public const PRESETS = [
        'last_12_months' => 'Last 12 months',
        'this_year' => 'This year',
        'last_year' => 'Last year',
        'this_month' => 'This month',
        'custom' => 'Custom range',
    ];

    /** 'finance' or 'audit': the door the user came through. */
    public string $module = 'finance';

    public string $preset = 'last_12_months';

    public string $from = '';

    public string $to = '';

    public function mount(string $module = 'finance'): void
    {
        $this->module = $module === 'audit' ? 'audit' : 'finance';
        $this->enforceFinance($this->module === 'audit' ? Permission::MODULE_AUDIT : Permission::MODULE_FINANCE);
        $this->guard($this->module.'.view_reports');

        $this->applyPreset('last_12_months');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function preset(string $preset): array
    {
        $today = today();

        return match ($preset) {
            'this_year' => [$today->copy()->startOfYear(), $today],
            'last_year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()->startOfDay()],
            'this_month' => [$today->copy()->startOfMonth(), $today],
            default => [$today->copy()->subMonthsNoOverflow(11)->startOfMonth(), $today],
        };
    }

    protected function applyPreset(string $preset): void
    {
        [$from, $to] = self::preset($preset);
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function updated(string $property): void
    {
        if ($property === 'preset' && $this->preset !== 'custom') {
            $this->applyPreset($this->preset);
        }

        if (in_array($property, ['from', 'to'], true)) {
            $this->preset = 'custom';
        }

        if (in_array($property, ['preset', 'from', 'to', 'locationId'], true) && ($report = $this->loadReport())) {
            $this->dispatch('fin-reports-updated', charts: $this->charts($report));
        }
    }

    /** @return array<string, mixed>|null */
    protected function loadReport(): ?array
    {
        $location = $this->workingLocation();

        if (! $location) {
            return null;
        }

        try {
            Carbon::parse($this->from);
            Carbon::parse($this->to);
        } catch (\Throwable) {
            return null; // a half-typed date
        }

        return app(PvAnalyticsService::class)->report($this->actor(), $this->as(), $location, $this->from, $this->to);
    }

    protected function as(): string
    {
        return $this->module === 'audit' ? FinanceScope::AS_AUDIT : FinanceScope::AS_FINANCE;
    }

    /**
     * The numbers the charts draw, from the same payload as the tables beside them.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, array<string, list<mixed>>>
     */
    protected function charts(array $report): array
    {
        $statuses = collect($report['statuses'])->where('count', '>', 0);

        return [
            'months' => $report['months'],
            'statuses' => ['labels' => $statuses->pluck('label')->values()->all(), 'count' => $statuses->pluck('count')->values()->all()],
            'categories' => ['labels' => collect($report['categories'])->pluck('name')->all(), 'amount' => collect($report['categories'])->map(fn (array $row) => round($row['amount'] / 100, 2))->all()],
            'reasons' => ['labels' => collect($report['reasons'])->pluck('label')->all(), 'count' => collect($report['reasons'])->pluck('count')->all()],
            'aging' => ['labels' => $report['waiting']['aging']['labels'], 'count' => $report['waiting']['aging']['counts']],
        ];
    }

    public function render()
    {
        $report = $this->loadReport();
        $can = fn (string $permission) => $this->can($permission);
        $query = array_filter(['from' => $this->from, 'to' => $this->to, 'district' => $this->isSuperAdmin() ? $this->locationId : null]);

        return view('livewire.finance.reports', [
            'report' => $report,
            'charts' => $report ? $this->charts($report) : [],
            'presets' => self::PRESETS,
            'exportQuery' => $query,
            'canExport' => $can($this->module.'.export') && $can($this->module.'.view_reports'),
            'exportRoute' => $this->module === 'audit' ? 'audit.export' : 'finance.export',
            'showRoute' => $this->module === 'audit' ? 'audit.pvs.show' : 'finance.pvs.show',
            'party' => $this->module,
        ] + $this->locationView());
    }
}
