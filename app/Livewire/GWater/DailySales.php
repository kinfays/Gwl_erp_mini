<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwProduct;
use App\Models\GwSale;
use App\Services\GWater\GwaterPricingService;
use App\Services\GWater\GwaterWorkflowService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daily sales: a day's packs per product at the selling price in force that day. The price is shown and cannot be typed
 * here; a second entry for the same day is another line. Each line can be voided with a reason (gwater.void).
 */
class DailySales extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;
    use WithPagination;

    public array $form = ['sale_date' => '', 'product_id' => '', 'packs' => '', 'note' => ''];

    public string $from = '';

    public string $to = '';

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.view');

        $this->form['sale_date'] = today()->toDateString();
        $this->form['product_id'] = (string) (GwProduct::query()->active()->orderBy('id')->value('id') ?? '');
        $this->from = today()->subDays(30)->toDateString();
        $this->to = today()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'locationId'], true)) {
            $this->resetPage();
        }
    }

    public function save(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.record');

        $this->run(fn () => $workflow->recordSale($this->actor(), $this->form + ['location_district_id' => $this->recordingDistrict()]));

        $this->form['packs'] = '';
        $this->form['note'] = '';
        $this->resetErrorBag();
        $this->dispatch('toast', type: 'success', message: 'Sale recorded.');
    }

    public function openVoid(int $saleId): void
    {
        $this->guard('gwater.void');
        // Resolved inside the user's books, so an id from another location is a 404 before the dialog even opens.
        $this->books(GwSale::query())->findOrFail($saleId);

        $this->voidingId = $saleId;
        $this->voidReason = '';
        $this->resetErrorBag();
    }

    public function cancelVoid(): void
    {
        $this->voidingId = null;
        $this->voidReason = '';
    }

    public function confirmVoid(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.void');
        abort_unless($this->voidingId, 422);

        $sale = $this->books(GwSale::query())->findOrFail($this->voidingId);
        $this->run(fn () => $workflow->voidSale($sale, $this->actor(), $this->voidReason), 'void');

        $this->cancelVoid();
        $this->dispatch('toast', type: 'success', message: 'Sale voided and the stock put back.');
    }

    public function render(GwaterPricingService $pricing)
    {
        $location = $this->workingLocation();
        $products = GwProduct::query()->active()->orderBy('id')->get();

        $sales = $location
            ? $this->books(GwSale::query())->with(['product', 'creator'])
                ->when($this->from !== '', fn ($query) => $query->whereDate('sale_date', '>=', $this->from))
                ->when($this->to !== '', fn ($query) => $query->whereDate('sale_date', '<=', $this->to))
                ->orderByRaw('DATE(sale_date) desc')->orderByDesc('id')
                ->paginate(15)
            : GwSale::query()->whereRaw('1 = 0')->paginate(15);

        $price = $location && $this->form['product_id'] !== '' && $this->form['sale_date'] !== ''
            ? $this->safePrice($pricing, (int) $this->form['product_id'], $this->form['sale_date'], $location)
            : null;

        $totals = $location
            ? $this->books(GwSale::query()->posted())
                ->when($this->from !== '', fn ($query) => $query->whereDate('sale_date', '>=', $this->from))
                ->when($this->to !== '', fn ($query) => $query->whereDate('sale_date', '<=', $this->to))
                ->get()->groupBy('product_id')
            : collect();

        return view('livewire.gwater.daily-sales', [
            'products' => $products,
            'sales' => $sales,
            'price' => $price,
            'totals' => $totals,
            'canRecord' => $this->visibility()->can($this->actor(), 'gwater.record'),
            'canVoid' => $this->visibility()->can($this->actor(), 'gwater.void'),
        ] + $this->locationView());
    }

    protected function safePrice(GwaterPricingService $pricing, int $productId, string $date, array $location): ?int
    {
        try {
            return $pricing->sellingPrice($productId, $date, $location);
        } catch (\Throwable) {
            return null; // a half-typed date
        }
    }
}
