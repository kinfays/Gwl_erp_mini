<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwProduct;
use App\Services\GWater\GwaterPricingService;
use App\Services\GWater\GwaterWorkflowService;
use App\Services\GWater\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The location's own dated price list. A new row for every change (selling price, cost price or both); a row is never edited.
 * Shows what is in force today and whether it is still the placeholder default. Needs gwater.manage_prices to add a row.
 */
class Prices extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;

    public array $form = ['product_id' => '', 'effective_from' => '', 'selling_price' => '', 'cost_price' => '', 'note' => '', 'default' => false];

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.view');

        $this->form['product_id'] = (string) (GwProduct::query()->active()->orderBy('id')->value('id') ?? '');
        $this->form['effective_from'] = today()->toDateString();
    }

    public function save(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.manage_prices');

        $data = $this->form + ['location_district_id' => $this->recordingDistrict()];

        foreach (['selling_price', 'cost_price'] as $field) {
            $data[$field] = Money::toPesewas($this->form[$field] ?? null);

            if ($data[$field] === null) {
                throw ValidationException::withMessages(["form.{$field}" => 'Enter the price in cedis, with at most two decimals (e.g. 26.00).']);
            }
        }

        $data['default'] = $this->isSuperAdmin() && (bool) ($this->form['default'] ?? false);

        $this->run(fn () => $workflow->addPrice($this->actor(), $data));

        $this->form['selling_price'] = '';
        $this->form['cost_price'] = '';
        $this->form['note'] = '';
        $this->resetErrorBag();
        $this->dispatch('toast', type: 'success', message: 'Price added to the list.');
    }

    public function render(GwaterPricingService $pricing)
    {
        $location = $this->workingLocation();
        $products = GwProduct::query()->active()->orderBy('id')->get();

        return view('livewire.gwater.prices', [
            'products' => $products,
            'inForce' => $location ? $pricing->inForce($location) : collect(),
            'histories' => $location ? $products->mapWithKeys(fn (GwProduct $product) => [$product->id => $pricing->history($location, $product->id)]) : collect(),
            'canManage' => $this->visibility()->can($this->actor(), 'gwater.manage_prices'),
        ] + $this->locationView());
    }
}
