<?php

namespace App\Services\GWater;

use App\Models\AuditLog;
use App\Models\GwDamage;
use App\Models\GwExpense;
use App\Models\GwOpeningBalance;
use App\Models\GwPrice;
use App\Models\GwProduct;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Models\GwStockMovement;
use App\Models\GwSupplierPayment;
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

        // Packs already paid for but not yet delivered on a waybill (the "outstanding" of the Excel sheets).
        $outstanding = [];

        foreach ((array) ($data['outstanding'] ?? []) as $productId => $count) {
            if ($count === null || $count === '' || (int) $count === 0) {
                continue;
            }

            $outstanding[(int) $productId] = $this->packs($count, 'outstanding');
        }

        if ($packs === [] && $outstanding === [] && $owed === 0) {
            throw ValidationException::withMessages(['packs' => 'Enter the packs brought forward, the packs still to be delivered, or the amount owed.']);
        }

        return DB::transaction(function () use ($actor, $location, $date, $owed, $packs, $outstanding, $data) {
            $this->stock->lock();

            $existing = $this->visibility->atLocation(GwOpeningBalance::query()->posted(), $location)->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'as_of_date' => 'An opening balance dated '.$existing->as_of_date->format('d M Y').' is already recorded for this location. It is posted once: void it to correct it.',
                ]);
            }

            $products = GwProduct::query()->whereIn('id', array_unique([...array_keys($packs), ...array_keys($outstanding)]))->get()->keyBy('id');

            $opening = GwOpeningBalance::query()->create([
                ...$location,
                'as_of_date' => $date->toDateString(),
                'owed_amount' => $owed,
                'note' => $this->text($data['note'] ?? null, 255),
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            foreach (array_unique([...array_keys($packs), ...array_keys($outstanding)]) as $productId) {
                abort_unless($products->has($productId), 404);

                // The brought-forward packs are valued at the cost in force on the opening date, frozen on this line: the
                // profit and loss never reads the price list for history.
                $unitCost = ($packs[$productId] ?? 0) > 0
                    ? ($this->pricing->costPrice($productId, $date, $location)
                        ?? throw ValidationException::withMessages(['packs' => 'No purchase price is on the price list for '.strtoupper($products[$productId]->code).' on '.$date->format('d M Y').'. Add one on the Prices screen first.']))
                    : 0;

                $opening->lines()->create([
                    'product_id' => $productId,
                    'packs' => $packs[$productId] ?? 0,
                    'unit_cost' => $unitCost,
                    'outstanding_packs' => $outstanding[$productId] ?? 0,
                ]);

                if (($packs[$productId] ?? 0) > 0) {
                    $this->stock->post($actor, $location, $products[$productId], GwStockMovement::OPENING, $packs[$productId], $date, [
                        'source_type' => 'gw_opening_balance',
                        'source_id' => $opening->id,
                        'note' => 'Brought forward',
                    ]);
                }
            }

            $this->audit('gwater.opening_balance_recorded', $opening, [
                'as_of_date' => $date->toDateString(),
                'packs' => $packs,
                'outstanding' => $outstanding,
                'owed_amount' => $owed,
            ]);

            return $opening->load('lines');
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

    // ------------------------------------------------------------------ damages

    /**
     * Damaged packs: taken out of stock and written off at the cost price in force on the day, frozen on the row. Optionally
     * tied to a trip (then the damaged packs of a product cannot exceed what the trip brought).
     *
     * @param  array{damage_date?: mixed, product_id?: mixed, packs?: mixed, stage?: mixed, reason?: mixed, purchase_id?: mixed, location_district_id?: mixed}  $data
     */
    public function recordDamage(User $actor, array $data): GwDamage
    {
        $this->authorise($actor, 'gwater.record');

        $location = $this->visibility->recordingLocation($actor, $this->optionalId($data['location_district_id'] ?? null));
        $date = $this->date($data['damage_date'] ?? null, 'damage_date');
        $product = $this->product($data['product_id'] ?? null);
        $packs = $this->packs($data['packs'] ?? null, 'packs');
        $stage = (string) ($data['stage'] ?? '');
        $reason = $this->text($data['reason'] ?? null, 500);

        if (! in_array($stage, GwDamage::STAGES, true)) {
            throw ValidationException::withMessages(['stage' => 'Choose where the damage happened: '.implode(', ', GwDamage::STAGES).'.']);
        }

        if ($reason === null || mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Say what was damaged and why (at least 3 characters).']);
        }

        return DB::transaction(function () use ($actor, $location, $date, $product, $packs, $stage, $reason, $data) {
            $this->stock->lock();

            $trip = $this->tripOf($actor, $location, $data['purchase_id'] ?? null);

            if ($trip) {
                $brought = (int) $trip->lines()->where('product_id', $product->id)->sum('packs');
                $already = (int) GwDamage::query()->posted()->where('purchase_id', $trip->id)->where('product_id', $product->id)->sum('packs');

                if ($packs + $already > $brought) {
                    throw ValidationException::withMessages([
                        'packs' => 'That trip brought '.$brought.' pack(s) of '.strtolower($product->code).' and '.$already.' are already recorded as damaged.',
                    ]);
                }
            }

            $cost = $this->pricing->costPrice($product->id, $date, $location)
                ?? throw ValidationException::withMessages(['product_id' => 'No purchase price is on the price list for '.$date->format('d M Y').'. Add one on the Prices screen first.']);

            $damage = GwDamage::query()->create([
                ...$location,
                'damage_date' => $date->toDateString(),
                'product_id' => $product->id,
                'packs' => $packs,
                'stage' => $stage,
                'unit_cost' => $cost,
                'reason' => $reason,
                'purchase_id' => $trip?->id,
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            $this->stock->post($actor, $location, $product, GwStockMovement::DAMAGE, -$packs, $date, [
                'source_type' => 'gw_damage',
                'source_id' => $damage->id,
                'note' => ucfirst($stage).' damage',
            ]);

            $this->audit('gwater.damage_recorded', $damage, [
                'product' => $product->code,
                'packs' => $packs,
                'stage' => $stage,
                'unit_cost' => $cost,
                'write_off' => $packs * $cost,
                'purchase_id' => $trip?->id,
            ]);

            return $damage;
        });
    }

    public function voidDamage(GwDamage $damage, User $actor, string $reason): GwDamage
    {
        $this->authorise($actor, 'gwater.void');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($damage, $actor, $reason) {
            $this->stock->lock();
            $fresh = $this->lockedOwnRow(GwDamage::class, $damage->id, $actor);
            $this->assertNotVoided($fresh);

            $this->stock->reverseSource($actor, $this->placeOf($fresh), 'gw_damage', $fresh->id, 'Void of damage #'.$fresh->id);
            $this->markVoided($fresh, $actor, $reason);
            $this->audit('gwater.damage_voided', $fresh, ['packs' => $fresh->packs, 'reason' => $reason]);

            return $fresh;
        });
    }

    // ------------------------------------------------------------------ expenses

    /**
     * A cost of the trade in pesewas. Fuel counts ONCE per trip: a trip whose fuel is linked from Transport, or that already
     * has a G-Water fuel expense, takes no second one. Only fuel can be reimbursable (paid out of pocket by `paid_by_user_id`);
     * loading and offloading are paid in cash and can never be.
     *
     * @param  array{expense_date?: mixed, category?: mixed, amount?: mixed, purchase_id?: mixed, vehicle_id?: mixed, payee?: mixed, receipt_ref?: mixed, note?: mixed, paid_by_user_id?: mixed, location_district_id?: mixed}  $data
     */
    public function recordExpense(User $actor, array $data): GwExpense
    {
        $this->authorise($actor, 'gwater.record');

        $location = $this->visibility->recordingLocation($actor, $this->optionalId($data['location_district_id'] ?? null));
        $date = $this->date($data['expense_date'] ?? null, 'expense_date');
        $category = (string) ($data['category'] ?? '');
        $amount = $this->amount($data['amount'] ?? null, 'amount');

        if (! in_array($category, GwExpense::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Choose a category: '.implode(', ', GwExpense::CATEGORIES).'.']);
        }

        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
        }

        $payerId = $this->optionalId($data['paid_by_user_id'] ?? null);

        if ($payerId !== null && $category !== GwExpense::FUEL) {
            throw ValidationException::withMessages(['paid_by_user_id' => 'Only fuel is paid back. Loading and offloading are paid in cash, and other costs are not reimbursable.']);
        }

        return DB::transaction(function () use ($actor, $location, $date, $category, $amount, $payerId, $data) {
            $this->stock->lock();

            $trip = $this->tripOf($actor, $location, $data['purchase_id'] ?? null);

            if ($category === GwExpense::FUEL && $trip) {
                if ($trip->vehicle_expense_id !== null) {
                    throw ValidationException::withMessages(['purchase_id' => 'That trip already takes its fuel from Transport. Fuel is counted once per trip.']);
                }

                if ($trip->expenses()->posted()->where('category', GwExpense::FUEL)->exists()) {
                    throw ValidationException::withMessages(['purchase_id' => 'That trip already has a fuel expense. Fuel is counted once per trip: void it first to replace it.']);
                }
            }

            $vehicleId = $this->optionalId($data['vehicle_id'] ?? null);

            if ($vehicleId !== null) {
                $vehicle = $this->transport->findVehicle($actor, $vehicleId);

                if (! $this->visibility->sameLocation(['region_id' => $vehicle->region_id, 'district_id' => $vehicle->district_id], $location)) {
                    throw ValidationException::withMessages(['vehicle_id' => 'That vehicle is based at another location.']);
                }
            }

            $payer = $this->fuelPayer($location, $payerId, $category === GwExpense::FUEL);
            $reimbursable = $category === GwExpense::FUEL && $payer !== null;

            $expense = GwExpense::query()->create([
                ...$location,
                'expense_date' => $date->toDateString(),
                'category' => $category,
                'amount' => $amount,
                'purchase_id' => $trip?->id,
                'vehicle_id' => $vehicleId,
                'payee' => $this->text($data['payee'] ?? null, 120),
                'receipt_ref' => $this->text($data['receipt_ref'] ?? null, 60),
                'note' => $this->text($data['note'] ?? null, 2000),
                'paid_by_user_id' => $reimbursable ? $payer->id : null,
                'reimbursement_status' => $reimbursable ? GwPurchase::PENDING : GwPurchase::NOT_APPLICABLE,
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            $this->audit('gwater.expense_recorded', $expense, [
                'category' => $category,
                'amount' => $amount,
                'purchase_id' => $trip?->id,
                'vehicle_id' => $vehicleId,
                'reimbursable' => $reimbursable,
            ]);

            return $expense;
        });
    }

    /** A fuel expense that was already paid back cannot be voided: the payment would be left untracked. */
    public function voidExpense(GwExpense $expense, User $actor, string $reason): GwExpense
    {
        $this->authorise($actor, 'gwater.void');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($expense, $actor, $reason) {
            $this->stock->lock();
            $fresh = $this->lockedOwnRow(GwExpense::class, $expense->id, $actor);
            $this->assertNotVoided($fresh);

            if ($fresh->reimbursement_status === GwPurchase::REIMBURSED) {
                throw ValidationException::withMessages(['reason' => 'This fuel was already paid back, so it cannot be voided.']);
            }

            $this->markVoided($fresh, $actor, $reason);
            $this->audit('gwater.expense_voided', $fresh, ['category' => $fresh->category, 'amount' => $fresh->amount, 'reason' => $reason]);

            return $fresh;
        });
    }

    // ------------------------------------------------------------------ payments to G Water

    /**
     * A payment request (status requested) or, when `paid_on` is given, a payment already made. A request may carry the packs
     * it was raised for ("550 bottle packs x 26.00"); the amount is then the total of those lines, or must agree with it.
     * The reference is unique among the location's non-voided payments.
     *
     * @param  array{request_date?: mixed, reference?: mixed, amount?: mixed, lines?: mixed, paid_on?: mixed, note?: mixed, location_district_id?: mixed}  $data
     */
    public function recordPayment(User $actor, array $data): GwSupplierPayment
    {
        $this->authorise($actor, 'gwater.record');

        $location = $this->visibility->recordingLocation($actor, $this->optionalId($data['location_district_id'] ?? null));
        $date = $this->date($data['request_date'] ?? null, 'request_date');
        $reference = $this->text($data['reference'] ?? null, 60);
        $paidOn = ($data['paid_on'] ?? null) === null || ($data['paid_on'] ?? '') === '' ? null : $this->date($data['paid_on'], 'paid_on');
        $lines = $this->paymentLines($data['lines'] ?? []);
        $typed = $this->amount($data['amount'] ?? 0, 'amount');

        if ($reference === null) {
            throw ValidationException::withMessages(['reference' => 'Enter the reference of the payment request.']);
        }

        if ($paidOn && $paidOn->lt($date)) {
            throw ValidationException::withMessages(['paid_on' => 'It cannot be paid before it was requested.']);
        }

        return DB::transaction(function () use ($actor, $location, $date, $reference, $paidOn, $lines, $typed, $data) {
            $this->stock->lock();

            $products = GwProduct::query()->active()->whereIn('id', collect($lines)->pluck('product_id'))->get()->keyBy('id');

            // A line left without a price takes the cost price in force on the request date.
            $lines = collect($lines)->map(function (array $line) use ($products, $date, $location) {
                abort_unless($products->has($line['product_id']), 404);

                $line['unit_price'] ??= $this->pricing->costPrice($line['product_id'], $date, $location)
                    ?? throw ValidationException::withMessages(['lines' => 'No purchase price is on the price list for that date. Type the price per pack or add one on the Prices screen.']);

                return $line;
            })->all();

            $linesTotal = collect($lines)->sum(fn (array $line) => $line['packs'] * $line['unit_price']);
            $amount = $lines === [] ? $typed : ($typed > 0 ? $typed : $linesTotal);

            if ($lines !== [] && $amount !== $linesTotal) {
                throw ValidationException::withMessages(['amount' => 'The amount ('.Money::format($amount).') does not match the packs and prices ('.Money::format($linesTotal).').']);
            }

            if ($amount < 1) {
                throw ValidationException::withMessages(['amount' => 'Enter the amount, or the packs and their price.']);
            }

            $existing = $this->visibility->atLocation(GwSupplierPayment::query()->posted(), $location)
                ->whereRaw('LOWER(reference) = ?', [mb_strtolower($reference)])->first();

            if ($existing) {
                throw ValidationException::withMessages(['reference' => 'Reference '.$reference.' is already used by a payment of '.$existing->request_date->format('d M Y').' for this location.']);
            }

            $payment = GwSupplierPayment::query()->create([
                ...$location,
                'request_date' => $date->toDateString(),
                'reference' => $reference,
                'amount' => $amount,
                'paid_on' => $paidOn?->toDateString(),
                'note' => $this->text($data['note'] ?? null, 255),
                'status' => $paidOn ? GwSupplierPayment::PAID : GwSupplierPayment::REQUESTED,
                'created_by' => $actor->id,
            ]);

            foreach ($lines as $line) {
                $payment->lines()->create($line);
            }

            $this->audit('gwater.payment_recorded', $payment, [
                'reference' => $reference,
                'amount' => $amount,
                'status' => $payment->status,
                'packs' => collect($lines)->sum('packs'),
            ]);

            return $payment->load('lines');
        });
    }

    public function markPaymentPaid(GwSupplierPayment $payment, User $actor, mixed $paidOn = null): GwSupplierPayment
    {
        $this->authorise($actor, 'gwater.record');
        $date = $paidOn === null || $paidOn === '' ? today() : $this->date($paidOn, 'paid_on');

        return DB::transaction(function () use ($payment, $actor, $date) {
            $this->stock->lock();
            $fresh = $this->lockedOwnRow(GwSupplierPayment::class, $payment->id, $actor);

            if ($fresh->status !== GwSupplierPayment::REQUESTED) {
                throw ValidationException::withMessages(['paid_on' => 'Only a payment request that is still waiting can be marked paid.']);
            }

            if ($date->lt($fresh->request_date)) {
                throw ValidationException::withMessages(['paid_on' => 'It cannot be paid before it was requested ('.$fresh->request_date->format('d M Y').').']);
            }

            $fresh->forceFill(['status' => GwSupplierPayment::PAID, 'paid_on' => $date->toDateString()])->save();
            $this->audit('gwater.payment_paid', $fresh, ['reference' => $fresh->reference, 'amount' => $fresh->amount, 'paid_on' => $date->toDateString()]);

            return $fresh;
        });
    }

    public function voidPayment(GwSupplierPayment $payment, User $actor, string $reason): GwSupplierPayment
    {
        $this->authorise($actor, 'gwater.void');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $this->stock->lock();
            $fresh = $this->lockedOwnRow(GwSupplierPayment::class, $payment->id, $actor);
            $this->assertNotVoided($fresh);

            $wasPaid = $fresh->status === GwSupplierPayment::PAID;
            $this->markVoided($fresh, $actor, $reason);
            $this->audit('gwater.payment_voided', $fresh, ['reference' => $fresh->reference, 'amount' => $fresh->amount, 'was_paid' => $wasPaid, 'reason' => $reason]);

            return $fresh;
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

    /**
     * A trip of the books being written to, if one was named: posted, inside the actor's reach and at the recording location.
     * Anything else is a 404.
     */
    protected function tripOf(User $actor, array $location, mixed $id): ?GwPurchase
    {
        $id = $this->optionalId($id);

        if ($id === null) {
            return null;
        }

        $query = $this->visibility->atLocation(GwPurchase::query()->posted(), $location);
        $trip = $this->visibility->scope($query, $actor)->whereKey($id)->first();

        abort_unless($trip, 404);

        return $trip;
    }

    /** @return list<array{product_id: int, packs: int, unit_price: int|null}> */
    protected function paymentLines(mixed $lines): array
    {
        $out = [];

        foreach ((array) $lines as $line) {
            $packs = $line['packs'] ?? null;

            if ($packs === null || $packs === '' || (int) $packs === 0) {
                continue;
            }

            $price = $line['unit_price'] ?? null;
            $out[] = [
                'product_id' => (int) ($line['product_id'] ?? 0),
                'packs' => $this->packs($packs, 'lines'),
                'unit_price' => $price === null || $price === '' ? null : $this->price($price, 'lines'),
            ];
        }

        return $out;
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
    {        // Cached profit figures of this location are now out of date: the next read recomputes them.
        if (($row->region_id ?? null) !== null) {
            $place = $this->placeOf($row);
            DB::afterCommit(fn () => GwaterBooksVersion::touch($place));
        }


        AuditLog::record($action, self::MODULE, $row->getTable(), (int) $row->getKey(), null, null, $metadata + [
            'region_id' => $row->region_id ?? null,
            'district_id' => $row->district_id ?? null,
        ]);
    }
}
