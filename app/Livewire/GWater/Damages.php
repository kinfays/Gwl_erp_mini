<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwDamage;
use App\Models\GwProduct;
use App\Models\GwPurchase;
use App\Services\GWater\GwaterWorkflowService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Damaged packs: product, packs, stage and reason, optionally tied to a trip. Each one takes the packs out of stock, is
 * written off at the cost in force that day (frozen on the row) and can be voided with a reason (the packs go back).
 */
class Damages extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;
    use WithPagination;

    public array $form = ['damage_date' => '', 'product_id' => '', 'packs' => '', 'stage' => 'transit', 'reason' => '', 'purchase_id' => ''];

    public string $from = '';

    public string $to = '';

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.view');

        $this->form['damage_date'] = today()->toDateString();
        $this->form['product_id'] = (string) (GwProduct::query()->active()->orderBy('id')->value('id') ?? '');
        $this->from = today()->subDays(90)->toDateString();
        $this->to = today()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'locationId'], true)) {
            $this->resetPage();
        }

        if ($property === 'locationId') {
            $this->form['purchase_id'] = '';
        }
    }

    public function save(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.record');

        $this->run(fn () => $workflow->recordDamage($this->actor(), $this->form + ['location_district_id' => $this->recordingDistrict()]));

        $this->form['packs'] = '';
        $this->form['reason'] = '';
        $this->form['purchase_id'] = '';
        $this->resetErrorBag();
        $this->dispatch('toast', type: 'success', message: 'Damage recorded and taken out of stock.');
    }

    public function openVoid(int $damageId): void
    {
        $this->guard('gwater.void');
        $this->books(GwDamage::query())->findOrFail($damageId);

        $this->voidingId = $damageId;
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

        $damage = $this->books(GwDamage::query())->findOrFail($this->voidingId);
        $this->run(fn () => $workflow->voidDamage($damage, $this->actor(), $this->voidReason), 'void');

        $this->cancelVoid();
        $this->dispatch('toast', type: 'success', message: 'Damage voided and the packs put back in stock.');
    }

    public function render()
    {
        $location = $this->workingLocation();

        $damages = $location
            ? $this->books(GwDamage::query())->with(['product', 'purchase', 'creator'])
                ->when($this->from !== '', fn ($query) => $query->whereDate('damage_date', '>=', $this->from))
                ->when($this->to !== '', fn ($query) => $query->whereDate('damage_date', '<=', $this->to))
                ->orderByRaw('DATE(damage_date) desc')->orderByDesc('id')
                ->paginate(15)
            : GwDamage::query()->whereRaw('1 = 0')->paginate(15);

        return view('livewire.gwater.damages', [
            'products' => GwProduct::query()->active()->orderBy('id')->get(),
            'damages' => $damages,
            'stages' => GwDamage::STAGES,
            'trips' => $location ? $this->books(GwPurchase::query()->posted())->orderByRaw('DATE(purchase_date) desc')->orderByDesc('id')->limit(60)->get() : collect(),
            'canRecord' => $this->visibility()->can($this->actor(), 'gwater.record'),
            'canVoid' => $this->visibility()->can($this->actor(), 'gwater.void'),
            'exports' => $this->exportLinks('damages', $this->from, $this->to),
        ] + $this->locationView());
    }
}
