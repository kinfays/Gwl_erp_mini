<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Services\GWater\GwaterSummaryService;
use Livewire\Component;

/** Stock on hand, this month's sales and purchases, what is owed to G Water and the fuel waiting to be paid back. */
class Overview extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.view');
    }

    public function render(GwaterSummaryService $summary)
    {
        $location = $this->workingLocation();

        $data = ['stock' => collect(), 'month' => collect(), 'owed' => 0, 'pending' => collect(), 'recentSales' => collect(), 'recentTrips' => collect()];

        if ($location) {
            $data = [
                'stock' => $summary->stockOnHand($location),
                'month' => $summary->monthFigures($location),
                'owed' => $summary->owedToGwater($location),
                'pending' => $summary->pendingReimbursements($location),
                'recentSales' => $this->books(GwSale::query()->posted())->with('product')->orderByRaw('DATE(sale_date) desc')->orderByDesc('id')->limit(8)->get(),
                'recentTrips' => $this->books(GwPurchase::query()->posted())->with('lines.product')->orderByRaw('DATE(purchase_date) desc')->orderByDesc('id')->limit(6)->get(),
            ];
        }

        return view('livewire.gwater.overview', $data + $this->locationView() + ['canRecord' => $this->visibility()->can($this->actor(), 'gwater.record')]);
    }
}
