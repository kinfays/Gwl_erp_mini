<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwOpeningBalance;
use App\Models\GwProduct;
use App\Models\GwStockMovement;
use App\Services\GWater\GwaterWorkflowService;
use App\Services\GWater\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/** The one-off brought-forward of a location: packs per product and the amount owed to G Water. Posted once; void to redo. */
class OpeningBalances extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;

    public array $form = ['as_of_date' => '', 'owed_amount' => '', 'note' => ''];

    /** @var array<int|string, string> */
    public array $packs = [];

    public bool $voiding = false;

    public string $voidReason = '';

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.record');

        $this->form['as_of_date'] = today()->toDateString();
    }

    public function save(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.record');

        $owed = trim((string) $this->form['owed_amount']) === '' ? 0 : Money::toPesewas($this->form['owed_amount']);

        if ($owed === null) {
            throw ValidationException::withMessages(['form.owed_amount' => 'Enter the amount owed in cedis, with at most two decimals.']);
        }

        $data = [
            'as_of_date' => $this->form['as_of_date'],
            'note' => $this->form['note'],
            'owed_amount' => $owed,
            'packs' => $this->packs,
            'location_district_id' => $this->recordingDistrict(),
        ];

        $this->run(fn () => $workflow->recordOpeningBalance($this->actor(), $data));

        $this->packs = [];
        $this->form['owed_amount'] = '';
        $this->form['note'] = '';
        $this->resetErrorBag();
        $this->dispatch('toast', type: 'success', message: 'Opening balance recorded.');
    }

    public function openVoid(): void
    {
        $this->guard('gwater.void');
        $this->voiding = true;
        $this->voidReason = '';
        $this->resetErrorBag();
    }

    public function cancelVoid(): void
    {
        $this->voiding = false;
        $this->voidReason = '';
    }

    public function confirmVoid(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.void');

        $opening = $this->books(GwOpeningBalance::query()->posted())->firstOrFail();
        $this->run(fn () => $workflow->voidOpeningBalance($opening, $this->actor(), $this->voidReason), 'void');

        $this->cancelVoid();
        $this->dispatch('toast', type: 'success', message: 'Opening balance voided. You can record it again.');
    }

    public function render()
    {
        $location = $this->workingLocation();
        $opening = $location ? $this->books(GwOpeningBalance::query()->posted())->with('creator')->first() : null;

        return view('livewire.gwater.opening-balances', [
            'products' => GwProduct::query()->active()->orderBy('id')->get(),
            'opening' => $opening,
            'openingPacks' => $opening
                ? GwStockMovement::query()->where('source_type', 'gw_opening_balance')->where('source_id', $opening->id)->get()->groupBy('product_id')->map->sum('packs')
                : collect(),
            'canVoid' => $this->visibility()->can($this->actor(), 'gwater.void'),
        ] + $this->locationView());
    }
}
