<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwProduct;
use App\Services\GWater\GwaterStockService;
use App\Services\GWater\GwaterSummaryService;
use App\Services\GWater\GwaterWorkflowService;
use Livewire\Component;

/**
 * The stock ledger of a product with its running balance. The ledger is immutable: this screen only reads it, except for the
 * correction form, which posts an `adjustment` row (never an edit) with a reason.
 */
class StockLedger extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;

    public string $productId = '';

    public string $from = '';

    public string $to = '';

    public array $form = ['product_id' => '', 'packs' => '', 'movement_date' => '', 'reason' => ''];

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.view');

        $first = (string) (GwProduct::query()->active()->orderBy('id')->value('id') ?? '');
        $this->productId = $first;
        $this->form['product_id'] = $first;
        $this->form['movement_date'] = today()->toDateString();
        $this->from = today()->subDays(90)->toDateString();
        $this->to = today()->toDateString();
    }

    public function correct(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.record');

        $this->run(fn () => $workflow->adjustStock($this->actor(), $this->form + ['location_district_id' => $this->recordingDistrict()]));

        $this->form['packs'] = '';
        $this->form['reason'] = '';
        $this->resetErrorBag();
        $this->dispatch('toast', type: 'success', message: 'Stock correction recorded.');
    }

    public function render(GwaterStockService $stock, GwaterSummaryService $summary)
    {
        $location = $this->workingLocation();
        $products = GwProduct::query()->active()->orderBy('id')->get();
        $product = $products->firstWhere('id', (int) $this->productId) ?? $products->first();

        $ledger = $location && $product
            ? $stock->ledger($location, $product->id, $this->from ?: null, $this->to ?: null)->reverse()->values()
            : collect();

        return view('livewire.gwater.stock-ledger', [
            'products' => $products,
            'product' => $product,
            'ledger' => $ledger,
            'onHand' => $location ? $summary->stockOnHand($location) : collect(),
            'waybills' => $location ? $summary->waybillOutstanding($location) : collect(),
            'canRecord' => $this->visibility()->can($this->actor(), 'gwater.record'),
        ] + $this->locationView());
    }
}
