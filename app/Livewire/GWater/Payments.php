<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwProduct;
use App\Models\GwSupplierPayment;
use App\Services\GWater\GwaterSummaryService;
use App\Services\GWater\GwaterWorkflowService;
use App\Services\GWater\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Payments to G Water: requests (and the packs they were raised for), marking them paid, voiding; and the owed balance as a
 * statement of the account (opening owed, every trip at its frozen cost, every payment marked paid).
 */
class Payments extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;

    public array $form = [];

    /** @var array<int|string, string> packs per product id on the request */
    public array $packs = [];

    /** @var array<int|string, string> price per pack typed per product id (cedis); empty = the cost price in force */
    public array $prices = [];

    public ?int $payingId = null;

    public string $paidOn = '';

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.view');

        $this->resetForm();
    }

    public function save(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.record');

        $lines = [];

        foreach ($this->packs as $productId => $count) {
            if (trim((string) $count) === '') {
                continue;
            }

            $typed = trim((string) ($this->prices[$productId] ?? ''));
            $price = $typed === '' ? null : Money::toPesewas($typed);

            if ($typed !== '' && $price === null) {
                throw ValidationException::withMessages(['form.lines' => 'Enter each price in cedis, with at most two decimals.']);
            }

            $lines[] = ['product_id' => (int) $productId, 'packs' => $count, 'unit_price' => $price];
        }

        $amountText = trim((string) $this->form['amount']);
        $amount = $amountText === '' ? 0 : Money::toPesewas($amountText);

        if ($amount === null) {
            throw ValidationException::withMessages(['form.amount' => 'Enter the amount in cedis, with at most two decimals.']);
        }

        $this->run(fn () => $workflow->recordPayment($this->actor(), [
            'request_date' => $this->form['request_date'],
            'reference' => $this->form['reference'],
            'amount' => $amount,
            'paid_on' => $this->form['already_paid'] ? ($this->form['paid_on'] ?: today()->toDateString()) : null,
            'note' => $this->form['note'],
            'lines' => $lines,
            'location_district_id' => $this->recordingDistrict(),
        ]));

        $this->resetForm();
        $this->packs = [];
        $this->prices = [];
        $this->resetErrorBag();
        $this->dispatch('toast', type: 'success', message: 'Payment recorded.');
    }

    public function openPay(int $paymentId): void
    {
        $this->guard('gwater.record');
        $this->books(GwSupplierPayment::query())->findOrFail($paymentId);

        $this->payingId = $paymentId;
        $this->paidOn = today()->toDateString();
        $this->resetErrorBag();
    }

    public function cancelPay(): void
    {
        $this->payingId = null;
    }

    public function pay(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.record');
        abort_unless($this->payingId, 422);

        $payment = $this->books(GwSupplierPayment::query())->findOrFail($this->payingId);
        $this->run(fn () => $workflow->markPaymentPaid($payment, $this->actor(), $this->paidOn), 'pay');

        $this->cancelPay();
        $this->dispatch('toast', type: 'success', message: 'Marked as paid.');
    }

    public function openVoid(int $paymentId): void
    {
        $this->guard('gwater.void');
        $this->books(GwSupplierPayment::query())->findOrFail($paymentId);

        $this->voidingId = $paymentId;
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

        $payment = $this->books(GwSupplierPayment::query())->findOrFail($this->voidingId);
        $this->run(fn () => $workflow->voidPayment($payment, $this->actor(), $this->voidReason), 'void');

        $this->cancelVoid();
        $this->dispatch('toast', type: 'success', message: 'Payment voided.');
    }

    protected function resetForm(): void
    {
        $this->form = ['request_date' => today()->toDateString(), 'reference' => '', 'amount' => '', 'already_paid' => false, 'paid_on' => today()->toDateString(), 'note' => ''];
    }

    public function render(GwaterSummaryService $summary)
    {
        $location = $this->workingLocation();

        return view('livewire.gwater.payments', [
            'products' => GwProduct::query()->active()->orderBy('id')->get(),
            'payments' => $location ? $this->books(GwSupplierPayment::query())->with(['lines.product', 'creator'])->orderByRaw('DATE(request_date) desc')->orderByDesc('id')->limit(100)->get() : collect(),
            'statement' => $location ? $summary->owedStatement($location) : null,
            'canRecord' => $this->visibility()->can($this->actor(), 'gwater.record'),
            'canVoid' => $this->visibility()->can($this->actor(), 'gwater.void'),
        ] + $this->locationView());
    }
}
