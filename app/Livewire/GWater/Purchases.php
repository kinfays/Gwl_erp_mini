<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwProduct;
use App\Models\GwPurchase;
use App\Models\MileageLog;
use App\Models\VehicleExpense;
use App\Services\GWater\GwaterPricingService;
use App\Services\GWater\GwaterSummaryService;
use App\Services\GWater\GwaterWorkflowService;
use App\Services\GWater\Money;
use App\Services\Transport\TransportScope;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Purchases (collection trips): the waybill, the packs bought per product at the cost price in force, the vehicle and driver
 * from Transport, the fuel (linked from Transport, or typed here) and the one loading / offloading payment, all on one form. Transport
 * is only READ, and always through TransportScope: the vehicle picker, the mileage entries and the fuel expenses offered are
 * the ones the user reaches, and the service re-checks every id it is sent.
 */
class Purchases extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;
    use WithFileUploads;
    use WithPagination;

    public bool $showForm = false;

    /** Text boxes hold cedis as typed; the service gets pesewas. */
    public array $form = [];

    /** @var array<int|string, string> packs per product id */
    public array $packs = [];

    /** The paper waybill, optional: a picture (JPG, PNG or WebP) or a PDF. */
    public $waybillPhoto = null;

    public string $from = '';

    public string $to = '';

    /** The trip whose waybill is open in the preview window. */
    public ?int $previewingId = null;

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.view');

        $this->resetForm();
        $this->from = today()->subDays(90)->toDateString();
        $this->to = today()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'locationId'], true)) {
            $this->resetPage();
        }

        // A different vehicle means different mileage and fuel rows: drop the old choice.
        if ($property === 'form.vehicle_id') {
            $this->form['mileage_log_id'] = '';
            $this->form['vehicle_expense_id'] = '';
        }

        if ($property === 'form.vehicle_expense_id' && $this->form['vehicle_expense_id'] !== '') {
            $this->form['fuel_amount'] = '';
        }

        if ($property === 'locationId') {
            foreach (['vehicle_id', 'driver_id', 'mileage_log_id', 'vehicle_expense_id', 'fuel_paid_by_user_id'] as $field) {
                $this->form[$field] = '';
            }
        }
    }

    public function updatedWaybillPhoto(): void
    {
        $this->validatePhoto();
    }

    protected function validatePhoto(): void
    {
        $this->validate(
            ['waybillPhoto' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(max(1, (int) config('gwl.gwater_waybill_photo_max_mb')) * 1024)]],
            [],
            ['waybillPhoto' => 'waybill file']
        );
    }

    public function removePhoto(): void
    {
        $this->waybillPhoto = null;
        $this->resetErrorBag('waybillPhoto');
    }

    public function openForm(): void
    {
        $this->guard('gwater.record');
        $this->resetForm();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function save(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.record');

        $data = $this->form + ['location_district_id' => $this->recordingDistrict()];

        foreach (['fuel_amount', 'loading_offloading_amount'] as $field) {
            $typed = trim((string) ($this->form[$field] ?? ''));
            $data[$field] = $typed === '' ? 0 : Money::toPesewas($typed);

            if ($data[$field] === null) {
                throw ValidationException::withMessages(["form.{$field}" => 'Enter an amount in cedis, with at most two decimals.']);
            }
        }

        $data['lines'] = collect($this->packs)->map(fn ($count, $productId) => ['product_id' => (int) $productId, 'packs' => $count])->values()->all();

        $this->validatePhoto();
        $this->run(fn () => $workflow->recordPurchase($this->actor(), $data, $this->waybillPhoto ?: null));

        $this->showForm = false;
        $this->resetForm();
        $this->resetPage();
        $this->dispatch('toast', type: 'success', message: 'Trip recorded and the packs added to stock.');
    }

    public function openPreview(int $purchaseId): void
    {
        // Resolved inside the user's own books: a trip outside them is a 404, like everywhere else.
        abort_unless($this->books(GwPurchase::query())->findOrFail($purchaseId)->hasWaybillPhoto(), 404);

        $this->previewingId = $purchaseId;
    }

    public function closePreview(): void
    {
        $this->previewingId = null;
    }

    public function openVoid(int $purchaseId): void
    {
        $this->guard('gwater.void');
        $this->books(GwPurchase::query())->findOrFail($purchaseId);

        $this->voidingId = $purchaseId;
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

        $purchase = $this->books(GwPurchase::query())->findOrFail($this->voidingId);
        $this->run(fn () => $workflow->voidPurchase($purchase, $this->actor(), $this->voidReason), 'void');

        $this->cancelVoid();
        $this->dispatch('toast', type: 'success', message: 'Trip voided and its stock taken back out.');
    }

    protected function resetForm(): void
    {
        $this->form = [
            'purchase_date' => today()->toDateString(),
            'waybill_no' => '',
            'supplier_ref' => '',
            'note' => '',
            'vehicle_id' => '',
            'driver_id' => '',
            'mileage_log_id' => '',
            'vehicle_expense_id' => '',
            'fuel_paid_by_user_id' => '',
            'fuel_amount' => '',
            'loading_offloading_amount' => '',
            'payee' => '',
            'receipt_ref' => '',
        ];
        $this->packs = [];
        $this->waybillPhoto = null;
    }

    public function render(GwaterSummaryService $summary, GwaterPricingService $pricing, TransportScope $transport)
    {
        $user = $this->actor();
        $location = $this->workingLocation();
        $products = GwProduct::query()->active()->orderBy('id')->get();

        $purchases = $location
            ? $this->books(GwPurchase::query())
                ->with(['lines.product', 'expenses', 'vehicle', 'driver', 'vehicleExpense', 'fuelPaidBy', 'creator'])
                ->when($this->from !== '', fn ($query) => $query->whereDate('purchase_date', '>=', $this->from))
                ->when($this->to !== '', fn ($query) => $query->whereDate('purchase_date', '<=', $this->to))
                ->orderByRaw('DATE(purchase_date) desc')->orderByDesc('id')
                ->paginate(12)
            : GwPurchase::query()->whereRaw('1 = 0')->paginate(12);

        $options = ['vehicles' => collect(), 'drivers' => collect(), 'people' => collect(), 'mileageLogs' => collect(), 'fuelExpenses' => collect(), 'costs' => collect()];

        if ($this->showForm && $location) {
            $vehicleId = (int) ($this->form['vehicle_id'] ?: 0);
            $linked = GwPurchase::query()->whereNotNull('vehicle_expense_id')->pluck('vehicle_expense_id');

            $options = [
                // Only vehicles the user reaches (TransportScope), and only those based at this location.
                'vehicles' => $this->visibility()->atLocation($transport->vehicles($user), $location)
                    ->where('vehicles.status', '!=', 'retired')->orderBy('vehicles.number_plate')->limit(300)->get(),
                'drivers' => $this->visibility()->peopleAt($location)->whereHas('roles', fn ($query) => $query->where('name', 'driver'))->orderBy('full_name')->limit(300)->get(),
                'people' => $this->visibility()->peopleAt($location)->orderBy('full_name')->limit(500)->get(),
                'mileageLogs' => $vehicleId
                    ? MileageLog::query()->visibleTo($user)->where('mileage_logs.vehicle_id', $vehicleId)->orderByRaw('DATE(trip_date) desc')->limit(40)->get()
                    : collect(),
                'fuelExpenses' => $vehicleId
                    ? VehicleExpense::query()->visibleTo($user)->where('vehicle_expenses.vehicle_id', $vehicleId)->where('expense_type', 'fuel')
                        ->whereNotIn('vehicle_expenses.id', $linked)->orderByRaw('DATE(expense_date) desc')->limit(40)->get()
                    : collect(),
                'costs' => $products->mapWithKeys(fn (GwProduct $product) => [
                    $product->id => $pricing->costPrice($product->id, $this->safeDate($this->form['purchase_date']), $location),
                ]),
            ];
        }

        $previewing = $this->previewingId && $location ? $this->books(GwPurchase::query())->find($this->previewingId) : null;

        return view('livewire.gwater.purchases', [
            'previewing' => $previewing?->hasWaybillPhoto() ? $previewing : null,
            'products' => $products,
            'purchases' => $purchases,
            'summary' => $summary,
            'canRecord' => $this->visibility()->can($user, 'gwater.record'),
            'canVoid' => $this->visibility()->can($user, 'gwater.void'),
        ] + $options + $this->locationView());
    }

    protected function safeDate(string $value): string
    {
        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return today()->toDateString();
        }
    }
}
