<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Services\GWater\GwaterProfitService;
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

    /** Packs on hand at or below which a product is flagged "low". */
    protected function lowStockThreshold(array $location, int $productId): int
    {
        return (int) config('gwl.gwater_low_stock_packs');
    }

    public function render(GwaterSummaryService $summary, GwaterProfitService $profit)
    {
        $location = $this->workingLocation();
        $canReports = $this->visibility()->can($this->actor(), 'gwater.view_reports');

        $data = ['stock' => collect(), 'month' => collect(), 'owed' => 0, 'pending' => collect(), 'recentSales' => collect(), 'recentTrips' => collect(), 'report' => null, 'damageFlags' => collect()];

        if ($location) {
            $stock = $summary->stockOnHand($location)->map(fn (array $row) => $row + [
                'threshold' => $threshold = $this->lowStockThreshold($location, $row['product']->id),
                'low' => $row['packs'] <= $threshold,
            ]);

            $report = $canReports ? $profit->report($location, today()->startOfMonth(), today(), $this->actor()) : null;

            $data = [
                'stock' => $stock,
                'month' => $summary->monthFigures($location),
                'owed' => $summary->owedToGwater($location),
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
