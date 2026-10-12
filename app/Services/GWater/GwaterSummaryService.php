<?php

namespace App\Services\GWater;

use App\Models\GwExpense;
use App\Models\GwOpeningBalance;
use App\Models\GwProduct;
use App\Models\GwPurchase;
use App\Models\GwPurchaseLine;
use App\Models\GwSale;
use App\Models\GwSaleReceipt;
use App\Models\GwSupplierPayment;
use App\Models\GwSupplierPaymentLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only figures for the Overview and Reimbursements screens, always for ONE location (Head Office, or a region without
 * it). The location is decided by the caller from GwaterVisibility; every query here is narrowed to it through
 * GwaterVisibility::atLocation, so nothing leaks between locations. Money is integer pesewas. (The profit and loss, and its
 * exports, are Phase 3.)
 */
class GwaterSummaryService
{
    public function __construct(
        protected GwaterVisibility $visibility,
        protected GwaterStockService $stock,
    ) {}

    /**
     * Packs on hand per active product.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return Collection<int, array{product: GwProduct, packs: int}>
     */
    public function stockOnHand(array $location): Collection
    {
        return GwProduct::query()->active()->orderBy('id')->get()->map(fn (GwProduct $product) => [
            'product' => $product,
            'packs' => $this->stock->balance($location, $product->id),
        ]);
    }

    /**
     * Sales and purchases of a calendar month per product.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return Collection<int, array{product: GwProduct, sold_packs: int, sales: int, bought_packs: int, purchases: int}>
     */
    public function monthFigures(array $location, ?Carbon $month = null): Collection
    {
        $from = ($month ?? today())->copy()->startOfMonth()->toDateString();
        $to = ($month ?? today())->copy()->endOfMonth()->toDateString();

        $sales = $this->visibility->atLocation(GwSale::query()->posted(), $location)
            ->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
            ->get()->groupBy('product_id');

        $lines = GwPurchaseLine::query()
            ->whereHas('purchase', fn ($purchase) => $this->visibility->atLocation($purchase->posted(), $location)
                ->whereDate('purchase_date', '>=', $from)->whereDate('purchase_date', '<=', $to))
            ->get()->groupBy('product_id');

        return GwProduct::query()->active()->orderBy('id')->get()->map(fn (GwProduct $product) => [
            'product' => $product,
            'sold_packs' => (int) ($sales[$product->id] ?? collect())->sum('packs'),
            'sales' => (int) ($sales[$product->id] ?? collect())->sum('amount'),
            'bought_packs' => (int) ($lines[$product->id] ?? collect())->sum('packs'),
            'purchases' => (int) ($lines[$product->id] ?? collect())->sum(fn (GwPurchaseLine $line) => $line->packs * $line->unit_cost),
        ]);
    }

    /**
     * Amount owed to G Water: the opening amount owed + purchases at their frozen cost - payments marked paid (up to a day,
     * when given). The same figure as the end of owedStatement().
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     */
    public function owedToGwater(array $location, ?Carbon $asOf = null): int
    {
        return $this->owedStatement($location, $asOf)['owed'];
    }

    /**
     * The account with G Water line by line, oldest first, with the running balance: the opening amount owed, every trip at
     * the cost it was bought at (its lines' frozen unit cost), and every payment marked paid. Payment requests not yet paid
     * are listed apart, as "requested", and do not reduce what is owed.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return array{lines: list<array<string, mixed>>, owed: int, opening: int, purchases: int, paid: int, requested: int}
     */
    public function owedStatement(array $location, ?Carbon $asOf = null): array
    {
        $upTo = fn ($query, string $column) => $asOf ? $query->whereDate($column, '<=', $asOf->toDateString()) : $query;

        $lines = collect();

        foreach ($upTo($this->visibility->atLocation(GwOpeningBalance::query()->posted(), $location), 'as_of_date')->get() as $opening) {
            $lines->push(['date' => $opening->as_of_date->toDateString(), 'order' => 0, 'kind' => 'opening', 'id' => $opening->id, 'label' => 'Brought forward', 'charge' => (int) $opening->owed_amount, 'payment' => 0]);
        }

        $trips = $upTo($this->visibility->atLocation(GwPurchase::query()->posted(), $location), 'purchase_date')->with('lines')->get();

        foreach ($trips as $trip) {
            $lines->push(['date' => $trip->purchase_date->toDateString(), 'order' => 1, 'kind' => 'trip', 'id' => $trip->id, 'label' => 'Waybill '.$trip->waybill_no, 'charge' => $trip->totalCost(), 'payment' => 0]);
        }

        $payments = $this->visibility->atLocation(GwSupplierPayment::query()->posted(), $location)->get();

        foreach ($payments->where('status', GwSupplierPayment::PAID)->filter(fn (GwSupplierPayment $payment) => ! $asOf || $payment->paid_on->lte($asOf)) as $payment) {
            $lines->push(['date' => $payment->paid_on->toDateString(), 'order' => 2, 'kind' => 'payment', 'id' => $payment->id, 'label' => 'Payment '.$payment->reference, 'charge' => 0, 'payment' => (int) $payment->amount]);
        }

        $balance = 0;
        $lines = $lines->sortBy([['date', 'asc'], ['order', 'asc'], ['id', 'asc']])->values()->map(function (array $line) use (&$balance) {
            $balance += $line['charge'] - $line['payment'];

            return $line + ['balance' => $balance];
        });

        return [
            'lines' => $lines->all(),
            'owed' => $balance,
            'opening' => (int) $lines->where('kind', 'opening')->sum('charge'),
            'purchases' => (int) $lines->where('kind', 'trip')->sum('charge'),
            'paid' => (int) $lines->where('kind', 'payment')->sum('payment'),
            'requested' => (int) $payments->where('status', GwSupplierPayment::REQUESTED)->sum('amount'),
        ];
    }

    /**
     * The "outstanding on waybills" view of the Excel sheets, per product: packs paid for or ordered (brought forward plus the
     * packs on payment requests) against packs delivered on waybills since the opening date. A report built from trips and
     * payment requests, not a second stock. A negative outstanding means more was delivered than was paid for.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return Collection<int, array{product: GwProduct, brought_forward: int, requested: int, ordered: int, delivered: int, outstanding: int, waybills: list<array{waybill: string, date: Carbon, packs: int}>}>
     */
    public function waybillOutstanding(array $location): Collection
    {
        $opening = $this->visibility->atLocation(GwOpeningBalance::query()->posted(), $location)->with('lines')->first();
        $since = $opening?->as_of_date;

        $trips = $this->visibility->atLocation(GwPurchase::query()->posted(), $location)
            ->when($since, fn ($query) => $query->whereDate('purchase_date', '>=', $since->toDateString()))
            ->with('lines')->orderByRaw('DATE(purchase_date) asc')->orderBy('id')->get();

        $paymentLines = GwSupplierPaymentLine::query()
            ->whereHas('payment', fn ($payment) => $this->visibility->atLocation($payment->posted(), $location))
            ->get()->groupBy('product_id');

        return GwProduct::query()->active()->orderBy('id')->get()->map(function (GwProduct $product) use ($opening, $trips, $paymentLines) {
            $broughtForward = (int) ($opening?->lines->where('product_id', $product->id)->sum('outstanding_packs') ?? 0);
            $requested = (int) ($paymentLines[$product->id] ?? collect())->sum('packs');
            $waybills = $trips->map(fn (GwPurchase $trip) => ['waybill' => $trip->waybill_no, 'date' => $trip->purchase_date, 'packs' => (int) $trip->lines->where('product_id', $product->id)->sum('packs')])
                ->filter(fn (array $row) => $row['packs'] > 0)->values();
            $delivered = (int) $waybills->sum('packs');

            return [
                'product' => $product,
                'brought_forward' => $broughtForward,
                'requested' => $requested,
                'ordered' => $broughtForward + $requested,
                'delivered' => $delivered,
                'outstanding' => $broughtForward + $requested - $delivered,
                'waybills' => $waybills->all(),
            ];
        });
    }

    /**
     * Credit sales not yet paid in full, as at a day (default: now): each with what is owed on it then. The customer's
     * receipts count only up to that day, so the figure at the end of a past period is what the books said then.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return Collection<int, array{sale: GwSale, customer: string, phone: string|null, date: Carbon, amount: int, received: int, owed: int, last_payment: Carbon|null}>
     */
    public function creditSalesOwing(array $location, ?Carbon $asOf = null): Collection
    {
        $day = $asOf?->toDateString();

        return $this->visibility->atLocation(GwSale::query()->posted()->where('payment_type', GwSale::CREDIT), $location)
            ->when($day, fn ($query) => $query->whereDate('sale_date', '<=', $day))
            ->with(['product', 'receipts' => fn ($receipts) => $receipts->posted()->when($day, fn ($query) => $query->whereDate('received_on', '<=', $day))])
            ->orderByRaw('DATE(sale_date) asc')->orderBy('id')
            ->get()
            ->map(fn (GwSale $sale) => [
                'sale' => $sale,
                'customer' => (string) $sale->customer_name,
                'phone' => $sale->customer_phone,
                'date' => $sale->sale_date,
                'amount' => $sale->amount,
                'received' => $sale->receivedAmount(),
                'owed' => max(0, $sale->amount - $sale->receivedAmount()),
                'last_payment' => $sale->receipts->max('received_on'),
            ])
            ->filter(fn (array $row) => $row['owed'] > 0)
            ->values();
    }

    /**
     * What customers owe on credit sales, in total, and how old the oldest debt is.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return array{total: int, count: int, customers: int, oldest_days: int|null}
     */
    public function owedByCustomers(array $location, ?Carbon $asOf = null): array
    {
        $rows = $this->creditSalesOwing($location, $asOf);
        $today = ($asOf ?? today())->copy()->startOfDay();

        return [
            'total' => (int) $rows->sum('owed'),
            'count' => $rows->count(),
            'customers' => $rows->pluck('customer')->map(fn (string $name) => mb_strtolower(trim($name)))->unique()->count(),
            'oldest_days' => $rows->isEmpty() ? null : (int) $rows->min('date')->diffInDays($today),
        ];
    }

    /**
     * The same debts grouped by customer (the same name typed in a different case or with spaces is one customer), biggest first.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return Collection<int, array{customer: string, phone: string|null, sales: int, owed: int, oldest: Carbon}>
     */
    public function owingByCustomer(array $location, ?Carbon $asOf = null): Collection
    {
        return $this->creditSalesOwing($location, $asOf)
            ->groupBy(fn (array $row) => mb_strtolower(trim($row['customer'])))
            ->map(fn (Collection $rows) => [
                'customer' => $rows->first()['customer'],
                'phone' => $rows->pluck('phone')->filter()->last(),
                'sales' => $rows->count(),
                'owed' => (int) $rows->sum('owed'),
                'oldest' => $rows->min('date'),
            ])
            ->sortByDesc('owed')
            ->values();
    }

    /**
     * Fuel waiting to be paid back, one item per G-Water fuel expense or Transport-linked trip.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return Collection<int, array{kind: string, id: int, date: Carbon, waybill: string|null, person_id: int|null, person: string, amount: int, recorded_by: string|null, recorded_by_id: int|null, changed_in_transport: bool, reference: string|null, paid_on: Carbon|null}>
     */
    public function pendingReimbursements(array $location): Collection
    {
        return $this->reimbursements($location, GwPurchase::PENDING);
    }

    /** The last paid-back fuel items, newest first. */
    public function reimbursed(array $location, int $limit = 30): Collection
    {
        return $this->reimbursements($location, GwPurchase::REIMBURSED)->sortByDesc('paid_on')->take($limit)->values();
    }

    /** A trip's fuel in pesewas: the linked Transport expense (read live, never copied) or its G-Water fuel row(s). */
    public function tripFuel(GwPurchase $purchase): int
    {
        if ($purchase->vehicle_expense_id) {
            return (int) round(((float) ($purchase->vehicleExpense?->amount ?? 0)) * 100);
        }

        return (int) $purchase->expenses->where('category', GwExpense::FUEL)->reject(fn (GwExpense $expense) => $expense->isVoided())->sum('amount');
    }

    /** Whether the linked Transport fuel row was edited after the trip was recorded. */
    public function changedInTransport(GwPurchase $purchase): bool
    {
        $expense = $purchase->vehicle_expense_id ? $purchase->vehicleExpense : null;

        return $expense !== null && $expense->updated_at?->gt($purchase->created_at->copy()->addSeconds(5)) === true;
    }

    // ------------------------------------------------------------------ internals

    protected function reimbursements(array $location, string $status): Collection
    {
        $expenses = $this->visibility->atLocation(GwExpense::query()->posted(), $location)
            ->where('category', GwExpense::FUEL)
            ->where('reimbursement_status', $status)
            ->with(['paidBy', 'creator', 'purchase'])
            ->get()
            ->map(fn (GwExpense $expense) => [
                'kind' => 'expense',
                'id' => $expense->id,
                'date' => $expense->expense_date,
                'waybill' => $expense->purchase?->waybill_no,
                'person_id' => $expense->paid_by_user_id,
                'person' => $expense->paidBy?->full_name ?? 'Unknown',
                'amount' => $expense->amount,
                'recorded_by' => $expense->creator?->full_name,
                'recorded_by_id' => $expense->created_by,
                'changed_in_transport' => false,
                'reference' => $expense->reimbursement_ref,
                'paid_on' => $expense->reimbursed_on,
            ]);

        $trips = $this->visibility->atLocation(GwPurchase::query()->posted(), $location)
            ->whereNotNull('vehicle_expense_id')
            ->where('fuel_reimbursement_status', $status)
            ->with(['fuelPaidBy', 'creator', 'vehicleExpense', 'expenses'])
            ->get()
            ->map(fn (GwPurchase $trip) => [
                'kind' => 'trip',
                'id' => $trip->id,
                'date' => $trip->purchase_date,
                'waybill' => $trip->waybill_no,
                'person_id' => $trip->fuel_paid_by_user_id,
                'person' => $trip->fuelPaidBy?->full_name ?? 'Unknown',
                'amount' => $this->tripFuel($trip),
                'recorded_by' => $trip->creator?->full_name,
                'recorded_by_id' => $trip->created_by,
                'changed_in_transport' => $this->changedInTransport($trip),
                'reference' => $trip->fuel_reimbursement_ref,
                'paid_on' => $trip->fuel_reimbursed_on,
            ]);

        return $expenses->concat($trips)->sortBy([['person', 'asc'], ['date', 'asc']])->values();
    }
}
