<?php

namespace App\Services\GWater;

use App\Models\GwExpense;
use App\Models\GwOpeningBalance;
use App\Models\GwProduct;
use App\Models\GwPurchase;
use App\Models\GwPurchaseLine;
use App\Models\GwSale;
use App\Models\GwSupplierPayment;
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
     * Amount owed to G Water: the opening amount owed + purchases at cost - payments marked paid.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     */
    public function owedToGwater(array $location): int
    {
        $opening = (int) $this->visibility->atLocation(GwOpeningBalance::query()->posted(), $location)->sum('owed_amount');

        $purchases = GwPurchaseLine::query()
            ->whereHas('purchase', fn ($purchase) => $this->visibility->atLocation($purchase->posted(), $location))
            ->get()
            ->sum(fn (GwPurchaseLine $line) => $line->packs * $line->unit_cost);

        $paid = (int) $this->visibility->atLocation(GwSupplierPayment::query()->where('status', 'paid'), $location)->sum('amount');

        return $opening + (int) $purchases - $paid;
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
