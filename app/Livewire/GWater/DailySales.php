<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Models\GwProduct;
use App\Models\GwSale;
use App\Models\GwSaleReceipt;
use App\Services\GWater\GwaterPricingService;
use App\Services\GWater\GwaterSummaryService;
use App\Services\GWater\GwaterWorkflowService;
use App\Services\GWater\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daily sales: a day's packs per product at the selling price in force that day. The price is shown and cannot be typed
 * here. A sale is paid at once (cash) or on credit, for a named customer who pays later: the Credit column shows what is
 * still owed on it, "Receive payment" records money coming in (as many times as it takes), and the card above lists who owes.
 * Each line can be voided with a reason (gwater.void); a credit sale with payments on it only after those are voided.
 */
class DailySales extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;
    use WithPagination;

    public array $form = ['sale_date' => '', 'product_id' => '', 'packs' => '', 'payment_type' => 'cash', 'customer_name' => '', 'customer_phone' => '', 'note' => ''];

    public string $from = '';

    public string $to = '';

    /** '' = every sale, 'credit' = credit sales, 'owing' = credit sales still owing money. */
    #[Url(except: '')]
    public string $show = '';

    public ?int $voidingId = null;

    public string $voidReason = '';

    public ?int $receivingId = null;

    public array $receipt = ['amount' => '', 'received_on' => '', 'reference' => '', 'note' => ''];

    public ?int $voidingReceiptId = null;

    public string $receiptVoidReason = '';

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
        if (in_array($property, ['from', 'to', 'show', 'locationId'], true)) {
            $this->resetPage();
        }
    }

    public function save(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.record');

        $this->run(fn () => $workflow->recordSale($this->actor(), $this->form + ['location_district_id' => $this->recordingDistrict()]));

        $this->form['packs'] = '';
        $this->form['note'] = '';
        $this->form['customer_name'] = '';
        $this->form['customer_phone'] = '';
        $this->resetErrorBag();
        $this->dispatch('toast', type: 'success', message: $this->form['payment_type'] === 'credit' ? 'Credit sale recorded.' : 'Sale recorded.');
    }

    // ------------------------------------------------------------------ voiding a sale

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
        $this->resetErrorBag();

        $sale = $this->books(GwSale::query())->findOrFail($this->voidingId);
        $this->run(fn () => $workflow->voidSale($sale, $this->actor(), $this->voidReason), 'void');

        $this->cancelVoid();
        $this->dispatch('toast', type: 'success', message: 'Sale voided and the stock put back.');
    }

    // ------------------------------------------------------------------ money received on a credit sale

    public function openReceive(int $saleId): void
    {
        $this->guard('gwater.record');
        $sale = $this->books(GwSale::query())->with('receipts')->findOrFail($saleId);

        $this->receivingId = $saleId;
        $this->receipt = ['amount' => Money::input($sale->balanceOwed()), 'received_on' => today()->toDateString(), 'reference' => '', 'note' => ''];
        $this->resetErrorBag();
    }

    public function cancelReceive(): void
    {
        $this->receivingId = null;
    }

    public function receive(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.record');
        abort_unless($this->receivingId, 422);
        $this->resetErrorBag();

        $amount = Money::toPesewas($this->receipt['amount'] ?? null);

        if ($amount === null) {
            throw ValidationException::withMessages(['receipt.amount' => 'Enter the amount in cedis, with at most two decimals.']);
        }

        $sale = $this->books(GwSale::query())->findOrFail($this->receivingId);
        $this->run(fn () => $workflow->recordSaleReceipt($this->actor(), $sale, [...$this->receipt, 'amount' => $amount]), 'receipt');

        $this->cancelReceive();
        $this->dispatch('toast', type: 'success', message: 'Payment received.');
    }

    public function openVoidReceipt(int $receiptId): void
    {
        $this->guard('gwater.void');
        $this->books(GwSaleReceipt::query())->findOrFail($receiptId);

        $this->voidingReceiptId = $receiptId;
        $this->receiptVoidReason = '';
        $this->resetErrorBag();
    }

    public function cancelVoidReceipt(): void
    {
        $this->voidingReceiptId = null;
        $this->receiptVoidReason = '';
    }

    public function confirmVoidReceipt(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.void');
        abort_unless($this->voidingReceiptId, 422);
        $this->resetErrorBag();

        $receipt = $this->books(GwSaleReceipt::query())->findOrFail($this->voidingReceiptId);
        $this->run(fn () => $workflow->voidSaleReceipt($receipt, $this->actor(), $this->receiptVoidReason), 'receiptvoid');

        $this->cancelVoidReceipt();
        $this->dispatch('toast', type: 'success', message: 'Payment voided: the customer owes it again.');
    }

    public function render(GwaterPricingService $pricing, GwaterSummaryService $summary)
    {
        $location = $this->workingLocation();
        $products = GwProduct::query()->active()->orderBy('id')->get();

        $inRange = fn ($query) => $query
            ->when($this->from !== '', fn ($inner) => $inner->whereDate('sale_date', '>=', $this->from))
            ->when($this->to !== '', fn ($inner) => $inner->whereDate('sale_date', '<=', $this->to));

        $sales = $location
            ? $inRange($this->books(GwSale::query()))->with(['product', 'creator', 'receipts' => fn ($receipts) => $receipts->posted()->orderByRaw('DATE(received_on)')->orderBy('id')])
                ->when($this->show !== '', fn ($query) => $query->where('payment_type', GwSale::CREDIT)->posted())
                // Still owing: the amount is more than the receipts not voided (a plain subquery, so it runs on any database).
                ->when($this->show === 'owing', fn ($query) => $query->whereRaw('gw_sales.amount > COALESCE((SELECT SUM(r.amount) FROM gw_sale_receipts r WHERE r.sale_id = gw_sales.id AND r.status != ?), 0)', ['voided']))
                ->orderByRaw('DATE(sale_date) desc')->orderByDesc('id')
                ->paginate(15)
            : GwSale::query()->whereRaw('1 = 0')->paginate(15);

        $price = $location && $this->form['product_id'] !== '' && $this->form['sale_date'] !== ''
            ? $this->safePrice($pricing, (int) $this->form['product_id'], $this->form['sale_date'], $location)
            : null;

        $totals = $location ? $inRange($this->books(GwSale::query()->posted()))->get()->groupBy('product_id') : collect();

        return view('livewire.gwater.daily-sales', [
            'products' => $products,
            'sales' => $sales,
            'price' => $price,
            'totals' => $totals,
            'owedByCustomers' => $location ? $summary->owedByCustomers($location) : ['total' => 0, 'count' => 0, 'customers' => 0, 'oldest_days' => null],
            'owing' => $location ? $summary->owingByCustomer($location) : collect(),
            'receivingSale' => $this->receivingId && $location ? $this->books(GwSale::query())->with('receipts')->find($this->receivingId) : null,
            'canRecord' => $this->visibility()->can($this->actor(), 'gwater.record'),
            'canVoid' => $this->visibility()->can($this->actor(), 'gwater.void'),
            'exports' => $this->exportLinks('receivables'),
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
