<?php

namespace App\Services\GWater;

use App\Models\AuditLog;
use App\Models\GwExpense;
use App\Models\GwOpeningBalance;
use App\Models\GwPrice;
use App\Models\GwProduct;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Models\GwStockMovement;
use App\Models\MileageLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Services\Transport\TransportScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every G-Water write goes through here (docs/17-module-g-water.md). The rules, in the order each method applies them:
 *  1. the permission (gwater.*), and for a new entry the location it is recorded into (GwaterVisibility::recordingLocation);
 *  2. inside one transaction the product rows are locked (GwaterStockService::lock), so stock and waybill checks cannot race;
 *  3. an existing row is RE-READ with lockForUpdate and authorised on that copy (another location's row is a 404), never
 *     trusted from the caller;
 *  4. the rule itself, using the price in force (GwaterPricingService) and the stock ledger (GwaterStockService);
 *  5. an audit row (AuditLog) naming what happened, never personal data.
 * Money is integer pesewas and quantities integer packs. Nothing is deleted: a wrong entry is voided with a reason and its
 * stock movements are reversed. G-Water only READS Transport (vehicle, driver, mileage log, fuel expense), always through
 * TransportScope; it never writes to those tables.
 */
class GwaterWorkflowService
{
    public const MODULE = 'gwater';

    public function __construct(
        protected GwaterVisibility $visibility,
        protected GwaterPricingService $pricing,
        protected GwaterStockService $stock,
        protected TransportScope $transport,
    ) {}

    // ------------------------------------------------------------------ daily sales

    /**
     * One line of a day's sales. The selling price is the one in force on that day at the location and cannot be overridden
     * here (a different price is a new row on the Prices screen); a second entry the same day is simply another line.
     *
     * @param  array{sale_date?: mixed, product_id?: mixed, packs?: mixed, note?: mixed, location_district_id?: mixed}  $data
     */
    public function recordSale(User $actor, array $data): GwSale
    {
        $this->authorise($actor, 'gwater.record');

        $location = $this->visibility->recordingLocation($actor, $this->optionalId($data['location_district_id'] ?? null));
        $date = $this->date($data['sale_date'] ?? null, 'sale_date');
        $product = $this->product($data['product_id'] ?? null);
        $packs = $this->packs($data['packs'] ?? null, 'packs');

        return DB::transaction(function () use ($actor, $location, $date, $product, $packs, $data) {
            $this->stock->lock();

            $price = $this->pricing->sellingPrice($product->id, $date, $location)
                ?? throw ValidationException::withMessages(['product_id' => 'No selling price is on the price list for '.$date->format('d M Y').'. Add one on the Prices screen first.']);

            $sale = GwSale::query()->create([
                ...$location,
                'sale_date' => $date->toDateString(),
                'product_id' => $product->id,
                'packs' => $packs,
                'unit_price' => $price,
                'amount' => $packs * $price,
                'note' => $this->text($data['note'] ?? null, 255),
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            $this->stock->post($actor, $location, $product, GwStockMovement::SALE, -$packs, $date, [
                'source_type' => 'gw_sale',
                'source_id' => $sale->id,
            ]);

            $this->audit('gwater.sale_recorded', $sale, [
                'product' => $product->code,
                'packs' => $packs,
                'unit_price' => $price,
                'amount' => $sale->amount,
                'sale_date' => $date->toDateString(),
            ]);

            return $sale;
        });
    }

    public function voidSale(GwSale $sale, User $actor, string $reason): GwSale
    {
        $this->authorise($actor, 'gwater.void');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($sale, $actor, $reason) {
            $this->stock->lock();
            $fresh = $this->lockedOwnRow(GwSale::class, $sale->id, $actor);
            $this->assertNotVoided($fresh);

            $this->stock->reverseSource($actor, $this->placeOf($fresh), 'gw_sale', $fresh->id, 'Void of sale #'.$fresh->id);
            $this->markVoided($fresh, $actor, $reason);
            $this->audit('gwater.sale_voided', $fresh, ['packs' => $fresh->packs, 'amount' => $fresh->amount, 'reason' => $reason]);

            return $fresh;
        });
    }

    // ------------------------------------------------------------------ purchases (collection trips)

    /**
     * A collection trip: the waybill, the packs bought (at the cost price in force on the day, frozen on each line), the
     * Transport references and the trip's cash costs, all in one go.
     *
     * @param  array<string, mixed>  $data  purchase_date, waybill_no, supplier_ref, note, lines [[product_id, packs]], vehicle_id,
     *                                       driver_id, mileage_log_id, vehicle_expense_id, fuel_paid_by_user_id, fuel_amount,
     *                                       loading_amount, offloading_amount (pesewas), payee, receipt_ref, location_district_id
     */
    public function recordPurchase(User $actor, array $data): GwPurchase
    {
        $this->authorise($actor, 'gwater.record');

        $location = $this->visibility->recordingLocation($actor, $this->optionalId($data['location_district_id'] ?? null));
        $date = $this->date($data['purchase_date'] ?? null, 'purchase_date');
        $waybill = $this->waybill($data['waybill_no'] ?? null);
        $lines = $this->purchaseLines($data['lines'] ?? []);
        $fuel = $this->amount($data['fuel_amount'] ?? 0, 'fuel_amount');
        $loading = $this->amount($data['loading_amount'] ?? 0, 'loading_amount');
        $offloading = $this->amount($data['offloading_amount'] ?? 0, 'offloading_amount');

        return DB::transaction(function () use ($actor, $location, $date, $waybill, $lines, $fuel, $loading, $offloading, $data) {
            $this->stock->lock();

            $this->assertWaybillFree($location, $waybill);

            $products = GwProduct::query()->active()->whereIn('id', array_keys($lines))->get()->keyBy('id');
            $costs = [];

            foreach ($lines as $productId => $packs) {
                abort_unless($products->has($productId), 404);
                $costs[$productId] = $this->pricing->costPrice($productId, $date, $location)
                    ?? throw ValidationException::withMessages(['lines' => 'No purchase price is on the price list for '.strtoupper($products[$productId]->code).' on '.$date->format('d M Y').'. Add one on the Prices screen first.']);
            }

            $transport = $this->transportLinks($actor, $location, $data);
            $payer = $this->fuelPayer($location, $data['fuel_paid_by_user_id'] ?? null, $transport['vehicle_expense_id'] !== null || $fuel > 0);

            if ($transport['vehicle_expense_id'] !== null && $fuel > 0) {
                throw ValidationException::withMessages([
                    'fuel_amount' => 'The fuel is already taken from Transport. Enter it in one place only: unlink the Transport expense or leave this amount empty.',
                ]);
            }

            $purchase = GwPurchase::query()->create([
                ...$location,
                'purchase_date' => $date->toDateString(),
                'waybill_no' => $waybill,
                'supplier_ref' => $this->text($data['supplier_ref'] ?? null, 60),
                'note' => $this->text($data['note'] ?? null, 2000),
                'status' => GwPurchase::POSTED,
                ...$transport,
                'fuel_paid_by_user_id' => $transport['vehicle_expense_id'] !== null ? $payer?->id : null,
                'fuel_reimbursement_status' => $transport['vehicle_expense_id'] !== null && $payer ? GwPurchase::PENDING : GwPurchase::NOT_APPLICABLE,
                'created_by' => $actor->id,
            ]);

            foreach ($lines as $productId => $packs) {
                $purchase->lines()->create(['product_id' => $productId, 'packs' => $packs, 'unit_cost' => $costs[$productId]]);

                $this->stock->post($actor, $location, $products[$productId], GwStockMovement::PURCHASE, $packs, $date, [
                    'source_type' => 'gw_purchase',
                    'source_id' => $purchase->id,
                    'note' => 'Waybill '.$waybill,
                ]);
            }

            foreach ([GwExpense::FUEL => $fuel, GwExpense::LOADING => $loading, GwExpense::OFFLOADING => $offloading] as $category => $amount) {
                if ($amount <= 0) {
                    continue;
                }

                // Only fuel is reimbursable; labour is paid in cash and is never marked pending.
                $reimbursable = $category === GwExpense::FUEL && $payer !== null;

                GwExpense::query()->create([
                    ...$location,
                    'expense_date' => $date->toDateString(),
                    'category' => $category,
                    'amount' => $amount,
                    'purchase_id' => $purchase->id,
                    'payee' => $this->text($data['payee'] ?? null, 120),
                    'receipt_ref' => $this->text($data['receipt_ref'] ?? null, 60),
                    'paid_by_user_id' => $reimbursable ? $payer->id : null,
                    'reimbursement_status' => $reimbursable ? GwPurchase::PENDING : GwPurchase::NOT_APPLICABLE,
                    'status' => 'posted',
                    'created_by' => $actor->id,
                ]);
            }

            $this->audit('gwater.purchase_recorded', $purchase, [
                'waybill_no' => $waybill,
                'purchase_date' => $date->toDateString(),
                'packs' => array_sum($lines),
                'cost' => collect($lines)->sum(fn (int $packs, int $productId) => $packs * $costs[$productId]),
                'fuel_source' => $transport['vehicle_expense_id'] !== null ? 'transport' : ($fuel > 0 ? 'gwater' : 'none'),
                'vehicle_id' => $transport['vehicle_id'],
                'vehicle_expense_id' => $transport['vehicle_expense_id'],
                'mileage_log_id' => $transport['mileage_log_id'],
                'loading' => $loading,
                'offloading' => $offloading,
                'fuel_amount' => $fuel,
            ]);

            return $purchase->load('lines');
        });
    }

    /** Voids the trip, reverses its stock, voids the cash costs entered with it and lets go of the Transport fuel link. */
    public function voidPurchase(GwPurchase $purchase, User $actor, string $reason): GwPurchase
    {
        $this->authorise($actor, 'gwater.void');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($purchase, $actor, $reason) {
            $this->stock->lock();
            $fresh = $this->lockedOwnRow(GwPurchase::class, $purchase->id, $actor);
            $this->assertNotVoided($fresh);

            $place = $this->placeOf($fresh);
            $this->stock->reverseSource($actor, $place, 'gw_purchase', $fresh->id, 'Void of trip, waybill '.$fresh->waybill_no);

            GwExpense::query()->where('purchase_id', $fresh->id)->lockForUpdate()->get()
                ->reject(fn (GwExpense $expense) => $expense->isVoided())
                ->each(function (GwExpense $expense) use ($actor, $reason, $fresh) {
                    $this->markVoided($expense, $actor, 'Trip voided: '.$reason);
                    $this->audit('gwater.expense_voided', $expense, ['purchase_id' => $fresh->id, 'amount' => $expense->amount]);
                });

            $released = ['vehicle_expense_id' => $fresh->vehicle_expense_id, 'fuel_was_reimbursed' => $fresh->fuel_reimbursement_status === GwPurchase::REIMBURSED];

            // Let go of the Transport fuel row so it can be linked to the right trip; the old link stays in the audit row.
            $fresh->forceFill(['vehicle_expense_id' => null]);
            $this->markVoided($fresh, $actor, $reason);
            $this->audit('gwater.purchase_voided', $fresh, ['waybill_no' => $fresh->waybill_no, 'reason' => $reason, ...$released]);

            return $fresh;
        });
    }

    // ------------------------------------------------------------------ opening balance

    /**
     * The one-off brought-forward of a location: packs per product (posted as `opening` ledger rows) and the amount owed to
     * G Water. Posts once per location; to correct it, void it and post again.
     *
     * @param  array{as_of_date?: mixed, packs?: array<int|string, mixed>, owed_amount?: mixed, note?: mixed, location_district_id?: mixed}  $data
     */
    public function recordOpeningBalance(User $actor, array $data): GwOpeningBalance
    {
        $this->authorise($actor, 'gwater.record');

        $location = $this->visibility->recordingLocation($actor, $this->optionalId($data['location_district_id'] ?? null));
        $date = $this->date($data['as_of_date'] ?? null, 'as_of_date');
        $owed = $this->amount($data['owed_amount'] ?? 0, 'owed_amount');
        $packs = [];

        foreach ((array) ($data['packs'] ?? []) as $productId => $count) {
            if ($count === null || $count === '' || (int) $count === 0) {
                continue;
            }

            $packs[(int) $productId] = $this->packs($count, 'packs');
        }

        if ($packs === [] && $owed === 0) {
            throw ValidationException::withMessages(['packs' => 'Enter the packs brought forward or the amount owed.']);
        }

        return DB::transaction(function () use ($actor, $location, $date, $owed, $packs, $data) {
            $this->stock->lock();

            $existing = $this->visibility->atLocation(GwOpeningBalance::query()->posted(), $location)->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'as_of_date' => 'An opening balance dated '.$existing->as_of_date->format('d M Y').' is already recorded for this location. It is posted once: void it to correct it.',
                ]);
            }

            $products = GwProduct::query()->whereIn('id', array_keys($packs))->get()->keyBy('id');

            $opening = GwOpeningBalance::query()->create([
                ...$location,
                'as_of_date' => $date->toDateString(),
                'owed_amount' => $owed,
                'note' => $this->text($data['note'] ?? null, 255),
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            foreach ($packs as $productId => $count) {
                abort_unless($products->has($productId), 404);

                $this->stock->post($actor, $location, $products[$productId], GwStockMovement::OPENING, $count, $date, [
                    'source_type' => 'gw_opening_balance',
                    'source_id' => $opening->id,
                    'note' => 'Brought forward',
                ]);
            }

            $this->audit('gwater.opening_balance_recorded', $opening, [
                'as_of_date' => $date->toDateString(),
                'packs' => $packs,
                'owed_amount' => $owed,
            ]);

            return $opening;
        });
    }

    public function voidOpeningBalance(GwOpeningBalance $opening, User $actor, string $reason): GwOpeningBalance
    {
        $this->authorise($actor, 'gwater.void');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($opening, $actor, $reason) {
            $this->stock->lock();
            $fresh = $this->lockedOwnRow(GwOpeningBalance::class, $opening->id, $actor);
            $this->assertNotVoided($fresh);

            $this->stock->reverseSource($actor, $this->placeOf($fresh), 'gw_opening_balance', $fresh->id, 'Void of opening balance');
            $this->markVoided($fresh, $actor, $reason);
            $this->audit('gwater.opening_balance_voided', $fresh, ['reason' => $reason]);

            return $fresh;
        });
    }

    // ------------------------------------------------------------------ stock correction

    /** A correction to the ledger: an `adjustment` row, either sign, with a reason. Never an edit of an old row. */
    public function adjustStock(User $actor, array $data): GwStockMovement
    {
        $this->authorise($actor, 'gwater.record');

        $location = $this->visibility->recordingLocation($actor, $this->optionalId($data['location_district_id'] ?? null));
        $date = $this->date($data['movement_date'] ?? null, 'movement_date');
        $product = $this->product($data['product_id'] ?? null);
        $packs = (int) ($data['packs'] ?? 0);
        $reason = $this->text($data['reason'] ?? null, 255);

        if ($packs === 0) {
            throw ValidationException::withMessages(['packs' => 'Enter the packs to add (positive) or take away (negative).']);
        }

        if ($reason === null || mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Say why the stock is being corrected.']);
        }

        return DB::transaction(function () use ($actor, $location, $date, $product, $packs, $reason) {
            $movement = $this->stock->post($actor, $location, $product, GwStockMovement::ADJUSTMENT, $packs, $date, ['source_type' => 'adjustment', 'note' => $reason]);

            $this->audit('gwater.stock_adjusted', $movement, ['product' => $product->code, 'packs' => $packs, 'reason' => $reason]);

            return $movement;
        });
    }

    // ------------------------------------------------------------------ prices

    /**
     * Adds a dated row to the price list (selling and cost price, pesewas). Rows are immutable: a mistake is a new row. A
     * location adds to its own list; only super_admin edits the placeholder default ($data['default'] = true).
     *
     * @param  array{product_id?: mixed, effective_from?: mixed, selling_price?: mixed, cost_price?: mixed, note?: mixed, default?: mixed, location_district_id?: mixed}  $data
     */
    public function addPrice(User $actor, array $data): GwPrice
    {
        $this->authorise($actor, 'gwater.manage_prices');

        $isDefault = (bool) ($data['default'] ?? false);

        if ($isDefault && ! $this->visibility->seesAll($actor)) {
            abort(403, 'Only a super admin changes the system default prices.');
        }

        $location = $isDefault ? null : $this->visibility->recordingLocation($actor, $this->optionalId($data['location_district_id'] ?? null));
        $product = $this->product($data['product_id'] ?? null);
        $from = $this->date($data['effective_from'] ?? null, 'effective_from', allowFuture: true);
        $selling = $this->price($data['selling_price'] ?? null, 'selling_price');
        $cost = $this->price($data['cost_price'] ?? null, 'cost_price');

        return DB::transaction(function () use ($actor, $location, $product, $from, $selling, $cost, $data) {
            $this->stock->lock();
            $this->pricing->assertCanAdd($location, $product->id, $from, $selling, $cost);

            $price = GwPrice::query()->create([
                'region_id' => $location['region_id'] ?? null,
                'district_id' => $location['district_id'] ?? null,
                'product_id' => $product->id,
                'effective_from' => $from->toDateString(),
                'selling_price' => $selling,
                'cost_price' => $cost,
                'note' => $this->text($data['note'] ?? null, 255),
                'created_by' => $actor->id,
            ]);

            $this->audit('gwater.price_added', $price, [
                'product' => $product->code,
                'effective_from' => $from->toDateString(),
                'selling_price' => $selling,
                'cost_price' => $cost,
                'scope' => $location === null ? 'default' : 'location',
            ]);

            return $price;
        });
    }

    // ------------------------------------------------------------------ reimbursement of fuel

    /**
     * Marks pending fuel as paid back. `$kind` is 'expense' (a G-Water fuel row) or 'trip' (fuel linked from Transport, the
     * state sits on the trip). Needs gwater.reimburse, and never the person who recorded the entry or who paid the fuel.
     */
    public function markReimbursed(User $actor, string $kind, int $id, string $reference, Carbon|string|null $paidOn = null): Model
    {
        $this->authorise($actor, 'gwater.reimburse');

        $reference = $this->text($reference, 60);

        if ($reference === null) {
            throw ValidationException::withMessages(['reference' => 'Enter the reference of the payment (cheque, transfer or voucher number).']);
        }

        $date = $paidOn ? $this->date($paidOn, 'paid_on') : today();
        abort_unless(in_array($kind, ['expense', 'trip'], true), 404);

        return DB::transaction(function () use ($actor, $kind, $id, $reference, $date) {
            if ($kind === 'expense') {
                $row = $this->lockedOwnRow(GwExpense::class, $id, $actor);
                $status = $row->reimbursement_status;
                $isFuel = $row->category === GwExpense::FUEL;
                $payer = $row->paid_by_user_id;
            } else {
                $row = $this->lockedOwnRow(GwPurchase::class, $id, $actor);
                $status = $row->fuel_reimbursement_status;
                $isFuel = $row->vehicle_expense_id !== null;
                $payer = $row->fuel_paid_by_user_id;
            }

            if ($row->isVoided() || ! $isFuel || $status !== GwPurchase::PENDING) {
                throw ValidationException::withMessages(['reference' => 'There is no pending fuel reimbursement on this entry.']);
            }

            if ((int) $row->created_by === $actor->id || (int) $payer === $actor->id) {
                throw ValidationException::withMessages(['reference' => 'You recorded this entry or paid this fuel yourself, so someone else has to mark it reimbursed.']);
            }

            if ($kind === 'expense') {
                $row->forceFill(['reimbursement_status' => GwPurchase::REIMBURSED, 'reimbursed_on' => $date->toDateString(), 'reimbursement_ref' => $reference, 'reimbursed_by_user_id' => $actor->id])->save();
            } else {
                $row->forceFill(['fuel_reimbursement_status' => GwPurchase::REIMBURSED, 'fuel_reimbursed_on' => $date->toDateString(), 'fuel_reimbursement_ref' => $reference, 'fuel_reimbursed_by_user_id' => $actor->id])->save();
            }

            $this->audit('gwater.fuel_reimbursed', $row, ['kind' => $kind, 'reference' => $reference, 'paid_on' => $date->toDateString()]);

            return $row;
        });
    }

    // ------------------------------------------------------------------ the Transport link (read-only)

    /**
     * Resolves and checks the references to Transport rows. Everything goes through TransportScope: a vehicle or row outside
     * the actor's reach is a 404, and one in reach but based at another G-Water location than the trip is refused.
     *
     * @return array{vehicle_id: int|null, driver_id: int|null, mileage_log_id: int|null, vehicle_expense_id: int|null}
     */
    protected function transportLinks(User $actor, array $location, array $data): array
    {
        $links = ['vehicle_id' => null, 'driver_id' => null, 'mileage_log_id' => null, 'vehicle_expense_id' => null];

        $vehicleId = $this->optionalId($data['vehicle_id'] ?? null);
        $driverId = $this->optionalId($data['driver_id'] ?? null);
        $logId = $this->optionalId($data['mileage_log_id'] ?? null);
        $expenseId = $this->optionalId($data['vehicle_expense_id'] ?? null);

        if (($logId || $expenseId) && ! $vehicleId) {
            throw ValidationException::withMessages(['vehicle_id' => 'Choose the vehicle first: the mileage and fuel are read from it.']);
        }

        if ($vehicleId) {
            $vehicle = $this->transport->findVehicle($actor, $vehicleId);

            if (! $this->visibility->sameLocation(['region_id' => $vehicle->region_id, 'district_id' => $vehicle->district_id], $location)) {
                throw ValidationException::withMessages(['vehicle_id' => 'That vehicle is based at another location.']);
            }

            $links['vehicle_id'] = $vehicle->id;
        }

        if ($driverId) {
            if (! $this->visibility->peopleAt($location)->whereKey($driverId)->exists()) {
                throw ValidationException::withMessages(['driver_id' => 'That driver is not based at this location.']);
            }

            $links['driver_id'] = $driverId;
        }

        if ($logId) {
            $log = MileageLog::query()->visibleTo($actor)->findOrFail($logId);

            if ((int) $log->vehicle_id !== $links['vehicle_id']) {
                throw ValidationException::withMessages(['mileage_log_id' => 'That mileage entry belongs to another vehicle.']);
            }

            $links['mileage_log_id'] = $log->id;
        }

        if ($expenseId) {
            $expense = VehicleExpense::query()->visibleTo($actor)->findOrFail($expenseId);

            if ($expense->expense_type !== 'fuel' || (int) $expense->vehicle_id !== $links['vehicle_id']) {
                throw ValidationException::withMessages(['vehicle_expense_id' => 'Choose a fuel expense of the same vehicle.']);
            }

            // Never counted twice: one fuel row, one trip (a voided trip lets go of it).
            if (GwPurchase::query()->where('vehicle_expense_id', $expense->id)->exists()) {
                throw ValidationException::withMessages(['vehicle_expense_id' => 'That fuel expense is already linked to another trip.']);
            }

            $links['vehicle_expense_id'] = $expense->id;
        }

        return $links;
    }

    /** The member of staff who paid fuel out of pocket, if any: must be based at the location, and fuel must exist. */
    protected function fuelPayer(array $location, mixed $userId, bool $hasFuel): ?User
    {
        $userId = $this->optionalId($userId);

        if ($userId === null) {
            return null;
        }

        if (! $hasFuel) {
            throw ValidationException::withMessages(['fuel_paid_by_user_id' => 'Enter or link the fuel before saying who paid it.']);
        }

        return $this->visibility->peopleAt($location)->whereKey($userId)->first()
            ?? throw ValidationException::withMessages(['fuel_paid_by_user_id' => 'That person is not based at this location.']);
    }

    // ------------------------------------------------------------------ small rules

    protected function assertWaybillFree(array $location, string $waybill): void
    {
        $existing = $this->visibility->atLocation(GwPurchase::query()->posted(), $location)
            ->whereRaw('LOWER(waybill_no) = ?', [mb_strtolower($waybill)])
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'waybill_no' => 'Waybill '.$waybill.' is already recorded for this location (trip of '.$existing->purchase_date->format('d M Y').'). A voided trip frees its number.',
            ]);
        }
    }

    /** @return array<int, int> product id => packs (a product listed twice is added up) */
    protected function purchaseLines(mixed $lines): array
    {
        $totals = [];

        foreach ((array) $lines as $line) {
            $packs = $line['packs'] ?? null;

            if ($packs === null || $packs === '' || (int) $packs === 0) {
                continue;
            }

            $productId = (int) ($line['product_id'] ?? 0);
            $totals[$productId] = ($totals[$productId] ?? 0) + $this->packs($packs, 'lines');
        }

        if ($totals === []) {
            throw ValidationException::withMessages(['lines' => 'Enter the packs bought for at least one product.']);
        }

        return $totals;
    }

    protected function waybill(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        // Refused, not cut short: a waybill is an identifier and a truncated one would point at another trip.
        if ($text === '' || mb_strlen($text) > 30) {
            throw ValidationException::withMessages(['waybill_no' => 'Enter the waybill number (up to 30 characters).']);
        }

        return $text;
    }

    protected function product(mixed $id): GwProduct
    {
        return GwProduct::query()->active()->find((int) $id)
            ?? throw ValidationException::withMessages(['product_id' => 'Choose a product.']);
    }

    protected function date(mixed $value, string $field, bool $allowFuture = false): Carbon
    {
        try {
            $date = Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Enter a valid date.']);
        }

        if (! $allowFuture && $date->gt(today())) {
            throw ValidationException::withMessages([$field => 'The date cannot be in the future.']);
        }

        return $date;
    }

    protected function packs(mixed $value, string $field): int
    {
        if (! is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > 1_000_000) {
            throw ValidationException::withMessages([$field => 'Packs must be a whole number from 1 to 1,000,000.']);
        }

        return (int) $value;
    }

    /** Pesewas, zero or more. */
    protected function amount(mixed $value, string $field): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (! is_numeric($value) || (int) $value != $value || (int) $value < 0 || (int) $value > 100_000_000_00) {
            throw ValidationException::withMessages([$field => 'Enter a valid amount.']);
        }

        return (int) $value;
    }

    /** A price in pesewas: at least one pesewa. */
    protected function price(mixed $value, string $field): int
    {
        $amount = $this->amount($value, $field);

        if ($amount < 1) {
            throw ValidationException::withMessages([$field => 'Enter a price above zero.']);
        }

        return $amount;
    }

    protected function optionalId(mixed $value): ?int
    {
        return $value === null || $value === '' || (int) $value <= 0 ? null : (int) $value;
    }

    protected function text(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    protected function reason(string $reason): string
    {
        $reason = $this->text($reason, 500);

        if ($reason === null || mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Give a reason for voiding (at least 3 characters).']);
        }

        return $reason;
    }

    protected function authorise(User $actor, string $permission): void
    {
        abort_unless($this->visibility->can($actor, $permission), 403, 'You do not have permission to do that.');
    }

    /**
     * Re-reads the row with a write lock and checks the actor reaches it ON THAT COPY: the copy the caller holds may be
     * stale, or may have been fetched with a client-supplied id.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    protected function lockedOwnRow(string $model, int $id, User $actor): Model
    {
        $row = $model::query()->lockForUpdate()->find($id);
        abort_unless($row && $this->visibility->contains($actor, $row), 404);

        return $row;
    }

    protected function assertNotVoided(Model $row): void
    {
        if ($row->isVoided()) {
            throw ValidationException::withMessages(['reason' => 'This entry is already voided.']);
        }
    }

    protected function markVoided(Model $row, User $actor, string $reason): void
    {
        $row->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();
    }

    /** @return array{region_id: int, district_id: int|null} */
    protected function placeOf(Model $row): array
    {
        return ['region_id' => (int) $row->region_id, 'district_id' => $row->district_id !== null ? (int) $row->district_id : null];
    }

    protected function audit(string $action, Model $row, array $metadata = []): void
    {
        AuditLog::record($action, self::MODULE, $row->getTable(), (int) $row->getKey(), null, null, $metadata + [
            'region_id' => $row->region_id ?? null,
            'district_id' => $row->district_id ?? null,
        ]);
    }
}
