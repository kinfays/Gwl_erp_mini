<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Permission;
use App\Services\Commercial\Trends\TrendReport;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Trends screen (Phase 8f, design 8.45): T2 period on period, T4 rolling windows, T5 rank movement, T6 year on year, T7 projection to month end and T8
 * coverage and timeliness. A thin shell: every figure comes from TrendReport::build(), the same call the Excel / PDF export makes. Which trend is a locked
 * property (a browser cannot swap it); the range, the district and the route / CCA are the filters.
 */
class Trends extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    #[Locked]
    public string $trend = 't2';

    #[Url]
    public string $region = '';

    #[Url]
    public string $district = '';

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    #[Url]
    public string $route = '';

    #[Url]
    public string $cca = '';

    /** The month of T7 / T8 (YYYY-MM); empty = the month of the latest upload. */
    #[Url]
    public string $month = '';

    /** T2 / T6 only: all three kinds, or one. */
    #[Url]
    public string $kind = 'all';

    /** The month shortcut: picking one sets the two dates to that whole month. */
    public string $preset = '';

    public function mount(string $trend = 't2'): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.view_reading', 'commercial.view_billing', 'commercial.view_customer_analytics');
        abort_unless(array_key_exists($trend, TrendReport::TRENDS), 404);
        $this->trend = $trend;
    }

    public function updatedPreset(string $value): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            $this->dateFrom = $value.'-01';
            $this->dateTo = Carbon::parse($value.'-01')->endOfMonth()->toDateString();
        } else {
            $this->dateFrom = '';
            $this->dateTo = '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset('district', 'dateFrom', 'dateTo', 'preset', 'route', 'cca', 'month', 'kind');
    }

    /** @return array<string, string> the last 12 months, newest first */
    protected function monthOptions(): array
    {
        $out = [];

        for ($month = now()->startOfMonth(); count($out) < 12; $month = $month->copy()->subMonthNoOverflow()) {
            $out[$month->format('Y-m')] = $month->format('M Y');
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function filters(): array
    {
        return [
            'region' => ctype_digit($this->region) ? (int) $this->region : null,
            'district' => ctype_digit($this->district) ? (int) $this->district : null,
            'from' => $this->dateFrom, 'to' => $this->dateTo,
            'route' => ctype_digit($this->route) ? (int) $this->route : null,
            'cca' => $this->cca, 'month' => $this->month, 'kind' => $this->kind,
        ];
    }

    public function render(TrendReport $report)
    {
        $r = $report->build($this->trend, $this->filters());

        return view('livewire.commercial.trends', [
            'r' => $r, 'trend' => $this->trend, 'trends' => TrendReport::TRENDS, 'monthOptions' => $this->monthOptions(), 'canExport' => $this->actorCan('commercial.export_reports'),
            'exportQuery' => array_filter(['region' => $r['region_id'], 'district' => $r['district_id'], 'from' => $this->dateFrom, 'to' => $this->dateTo, 'route' => $r['route_id'], 'cca' => $r['cca'], 'month' => $this->month, 'kind' => $r['kind'] === 'all' ? null : $r['kind']], fn ($v) => $v !== null && $v !== ''),
        ]);
    }
}
