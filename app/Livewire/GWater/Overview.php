<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Services\GWater\GwaterProfitService;
use App\Services\GWater\GwaterSettings;
use App\Services\GWater\GwaterSummaryService;
use Livewire\Component;

/**
 * Stock per product with low-stock flags, this month's sales, net profit (from GwaterProfitService, so it is the number the
 * Profit and loss screen shows; only for holders of gwater.view_reports), what is owed to G Water, damage-rate flags and the
 * fuel waiting to be paid back.
 */
class Overview extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.view');
    }

    public function render(GwaterSummaryService $summary, GwaterProfitService $profit, GwaterSettings $settings)
    {
        $location = $this->workingLocation();
        $canReports = $this->visibility()->can($this->actor(), 'gwater.view_reports');

        $data = ['stock' => collect(), 'month' => collect(), 'owed' => 0, 'pending' => collect(), 'recentSales' => collect(), 'recentTrips' => collect(), 'report' => null, 'damageFlags' => collect(), 'customersOwe' => ['total' => 0, 'count' => 0, 'customers' => 0, 'oldest_days' => null]];

        if ($location) {
            $stock = $summary->stockOnHand($location)->map(fn (array $row) => $row + [
                'threshold' => $threshold = $settings->lowStock($location, $row['product']),
                'low' => $row['packs'] <= $threshold,
            ]);

            $report = $canReports ? $profit->report($location, today()->startOfMonth(), today(), $this->actor()) : null;

            $data = [
                'stock' => $stock,
                'month' => $summary->monthFigures($location),
                'owed' => $summary->owedToGwater($location),
                'customersOwe' => $summary->owedByCustomers($location),
                'pending' => $summary->pendingReimbursements($location),
                'recentSales' => $this->books(GwSale::query()->posted())->with('product')->orderByRaw('DATE(sale_date) desc')->orderByDesc('id')->limit(8)->get(),
                'recentTrips' => $this->books(GwPurchase::query()->posted())->with('lines.product')->orderByRaw('DATE(purchase_date) desc')->orderByDesc('id')->limit(6)->get(),
                'report' => $report,
                'damageFlags' => $report
                    ? collect($report['products'])->filter(fn (array $line) => $line['damage_rate_pct'] !== null && $line['damage_rate_pct'] > (float) config('gwl.gwater_damage_rate_flag_pct'))
                    : collect(),
            ];
        }

        return view('livewire.gwater.overview', $data + $this->locationView() + ['canRecord' => $this->visibility()->can($this->actor(), 'gwater.record'), 'canReports' => $canReports]);
    }
}
