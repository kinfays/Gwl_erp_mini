<?php

namespace App\Services\HealthSafety;

use App\Models\HsPpeReorderLevel;
use App\Models\HsPpeStockMovement;
use App\Models\HsPpeType;
use App\Models\HsSite;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The PPE stock ledger. Every change is a new signed line in hs_ppe_stock_movements; nothing is edited or deleted, and a
 * balance is the sum of its lines per store, type and size.
 *
 * Sign rules: receipt, return and transfer_in are positive; issue, write_off and transfer_out negative; an adjustment may
 * be either but never zero. A balance can never go negative: the check is made inside the transaction that posts the line,
 * after taking a lock on the type's row, so two people issuing the last pairs of boots at once cannot both succeed.
 * Adjustments and write-offs need a reason.
 *
 * Stock lives in PPE stores (sites flagged is_ppe_store) and every store touched must be inside the actor's part of the
 * register.
 */
class PpeStockService
{
    public const MAX_QUANTITY = 100000;

    public function __construct(protected EquipmentScope $scope) {}

    // ------------------------------------------------------------------ the five things an officer does by hand

    public function receive(User $actor, HsSite $store, HsPpeType $type, ?string $size, int $quantity, ?string $reference = null, ?string $notes = null): HsPpeStockMovement
    {
        $this->guardQuantity($quantity);

        $movement = $this->post($actor, $store, $type, $size, HsPpeStockMovement::RECEIPT, $quantity, [
            'reference' => $this->clean($reference, 100),
            'notes' => $this->clean($notes, 1000),
        ]);

        $this->audit('health_safety.ppe_received', $movement);

        return $movement;
    }

    /** @param  int  $signedQuantity  positive adds to the count, negative takes away; never zero */
    public function adjust(User $actor, HsSite $store, HsPpeType $type, ?string $size, int $signedQuantity, string $reason): HsPpeStockMovement
    {
        if ($signedQuantity === 0 || abs($signedQuantity) > self::MAX_QUANTITY) {
            throw ValidationException::withMessages(['quantity' => 'Enter how many to add (or take away, with a minus). It cannot be zero.']);
        }

        $movement = $this->post($actor, $store, $type, $size, HsPpeStockMovement::ADJUSTMENT, $signedQuantity, [
            'notes' => $this->requireReason($reason),
        ]);

        $this->audit('health_safety.ppe_adjusted', $movement);

        return $movement;
    }

    public function writeOff(User $actor, HsSite $store, HsPpeType $type, ?string $size, int $quantity, string $reason): HsPpeStockMovement
    {
        $this->guardQuantity($quantity);

        $movement = $this->post($actor, $store, $type, $size, HsPpeStockMovement::WRITE_OFF, -$quantity, [
            'notes' => $this->requireReason($reason),
        ]);

        $this->audit('health_safety.ppe_written_off', $movement);

        return $movement;
    }

    /**
     * Move stock between two PPE stores: a transfer_out and a transfer_in in one transaction, sharing a reference.
     *
     * @return array{out: HsPpeStockMovement, in: HsPpeStockMovement}
     */
    public function transfer(User $actor, HsSite $from, HsSite $to, HsPpeType $type, ?string $size, int $quantity, ?string $notes = null): array
    {
        $this->guardQuantity($quantity);

        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_store' => 'Choose a different store to transfer to.']);
        }

        return DB::transaction(function () use ($actor, $from, $to, $type, $size, $quantity, $notes) {
            $reference = 'TRF-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
            $extra = ['reference' => $reference, 'notes' => $this->clean($notes, 1000)];

            // The destination is checked first so a refusal there does not leave a half-posted transfer to roll back.
            $this->guardStore($actor, $to, 'to_store');

            $out = $this->post($actor, $from, $type, $size, HsPpeStockMovement::TRANSFER_OUT, -$quantity, $extra);
            $in = $this->post($actor, $to, $type, $size, HsPpeStockMovement::TRANSFER_IN, $quantity, $extra);

            Audit::log('health_safety.ppe_transferred', 'health_safety', 'hs_ppe_stock_movements', $out->id, [
                'reference' => $reference,
                'type' => $type->name,
                'size' => $out->size,
                'quantity' => $quantity,
                'from' => $from->name,
                'to' => $to->name,
            ]);

            return ['out' => $out, 'in' => $in];
        });
    }

    // ------------------------------------------------------------------ the one place a line is written

    /**
     * Post one signed line. Also used by PpeIssueService for the issue and return lines, inside its own transaction.
     *
     * @param  array{reference?: string|null, notes?: string|null, issue_id?: int|null, occurred_on?: \DateTimeInterface|string|null}  $extra
     */
    public function post(User $actor, HsSite $store, HsPpeType $type, ?string $size, string $movementType, int $signedQuantity, array $extra = []): HsPpeStockMovement
    {
        $this->guardStore($actor, $store);
        $this->guardSign($movementType, $signedQuantity);

        return DB::transaction(function () use ($actor, $store, $type, $size, $movementType, $signedQuantity, $extra) {
            // Serialises concurrent movements of this type, so the balance check below cannot be raced.
            $type = HsPpeType::query()->lockForUpdate()->findOrFail($type->id);

            if (! $type->is_active && $signedQuantity > 0 && $movementType !== HsPpeStockMovement::RETURN) {
                throw ValidationException::withMessages(['ppe_type_id' => 'This PPE type is deactivated.']);
            }

            $size = $this->normaliseSize($type, $size);

            $movement = HsPpeStockMovement::query()->create([
                'site_id' => $store->id,
                'ppe_type_id' => $type->id,
                'size' => $size,
                'movement_type' => $movementType,
                'quantity' => $signedQuantity,
                'reference' => $extra['reference'] ?? null,
                'issue_id' => $extra['issue_id'] ?? null,
                'notes' => $extra['notes'] ?? null,
                'created_by' => $actor->id,
                'occurred_on' => filled($extra['occurred_on'] ?? null) ? \Illuminate\Support\Carbon::parse($extra['occurred_on'])->toDateString() : today(),
            ]);

            if ($signedQuantity < 0) {
                $balance = $this->balance($store, $type, $size);

                if ($balance < 0) {
                    throw ValidationException::withMessages(['quantity' => sprintf(
                        'Only %d %s%s in stock at %s: that would take it below zero.',
                        $balance - $signedQuantity,
                        $type->name,
                        $size ? ' ('.$size.')' : '',
                        $store->name
                    )]);
                }
            }

            return $movement;
        });
    }

    // ------------------------------------------------------------------ balances

    /** The count of one type (and size, when it has them) in one store. */
    public function balance(HsSite $store, HsPpeType $type, ?string $size = null): int
    {
        return (int) HsPpeStockMovement::query()
            ->where('site_id', $store->id)
            ->where('ppe_type_id', $type->id)
            ->when($size === null, fn ($query) => $query->whereNull('size'), fn ($query) => $query->where('size', $size))
            ->sum('quantity');
    }

    /** The total of a type in a store across all sizes (what the reorder level is compared with). */
    public function total(HsSite $store, HsPpeType $type): int
    {
        return (int) HsPpeStockMovement::query()->where('site_id', $store->id)->where('ppe_type_id', $type->id)->sum('quantity');
    }

    /**
     * The stock matrix for the screen, for the stores the actor may see: one row per store and type, with the balance of
     * each size, the total, the reorder level and whether it is low (total at or below a level that exists).
     *
     * @return Collection<int, array{store: HsSite, type: HsPpeType, sizes: array<string, int>, total: int, level: int|null, low: bool}>
     */
    public function matrix(User $actor, ?int $storeId = null, ?int $typeId = null): Collection
    {
        $stores = $this->scope->ppeStores($actor)->when($storeId, fn ($query) => $query->whereKey($storeId))->orderBy('name')->get();

        if ($stores->isEmpty()) {
            return collect();
        }

        $balances = HsPpeStockMovement::query()
            ->select('site_id', 'ppe_type_id', 'size', DB::raw('SUM(quantity) as balance'))
            ->whereIn('site_id', $stores->pluck('id'))
            ->groupBy('site_id', 'ppe_type_id', 'size')
            ->get()
            ->groupBy(fn ($row) => $row->site_id.'|'.$row->ppe_type_id);

        $levels = HsPpeReorderLevel::query()->whereIn('site_id', $stores->pluck('id'))->get()->keyBy(fn ($level) => $level->site_id.'|'.$level->ppe_type_id);

        $types = HsPpeType::query()
            ->when($typeId, fn ($query) => $query->whereKey($typeId))
            ->orderBy('name')
            ->get()
            ->filter(fn (HsPpeType $type) => $type->is_active || $balances->keys()->contains(fn ($key) => str_ends_with($key, '|'.$type->id)));

        $rows = collect();

        foreach ($stores as $store) {
            foreach ($types as $type) {
                $key = $store->id.'|'.$type->id;
                $held = ($balances[$key] ?? collect())->mapWithKeys(fn ($row) => [(string) ($row->size ?? '') => (int) $row->balance]);
                $total = (int) $held->sum();
                $level = $levels[$key]->level ?? null;

                $sizes = [];

                foreach ($type->has_sizes ? $type->sizeList() : [''] as $size) {
                    $sizes[$size] = (int) ($held[$size] ?? 0);
                }

                // A size that is no longer on the type's list but still has stock stays visible.
                foreach ($held as $size => $count) {
                    $sizes[$size] ??= $count;
                }

                $rows->push([
                    'store' => $store,
                    'type' => $type,
                    'sizes' => $sizes,
                    'total' => $total,
                    'level' => $level,
                    'low' => $level !== null && $total <= $level,
                ]);
            }
        }

        return $rows;
    }

    /** The store/type combinations that are low, for the overview: the same rows the stock screen flags. */
    public function lowStock(User $actor): Collection
    {
        return $this->matrix($actor)->filter(fn (array $row) => $row['low'])->values();
    }

    // ------------------------------------------------------------------ guards

    /** A PPE store inside the actor's part of the register, with the right to post to it. */
    public function guardStore(User $actor, HsSite $store, string $field = 'store_id'): void
    {
        abort_unless($this->scope->can($actor, 'health_safety.manage_ppe'), 403, 'You may not change PPE stock.');
        abort_unless($this->scope->contains($actor, $store), 403, 'That store is outside your region.');

        if (! $store->is_active || ! $store->is_ppe_store) {
            throw ValidationException::withMessages([$field => 'Choose a PPE store. Mark a site as one under Sites first.']);
        }
    }

    /** Whether the size is right for the type: given and listed for a sized type, absent for one without sizes. */
    public function normaliseSize(HsPpeType $type, ?string $size): ?string
    {
        $size = $size === null ? null : trim($size);
        $size = $size === '' ? null : $size;

        if ($type->has_sizes) {
            if ($size === null || ! in_array($size, $type->sizeList(), true)) {
                throw ValidationException::withMessages(['size' => 'Choose one of the sizes listed for '.$type->name.'.']);
            }

            return $size;
        }

        if ($size !== null) {
            throw ValidationException::withMessages(['size' => $type->name.' does not come in sizes.']);
        }

        return null;
    }

    protected function guardSign(string $movementType, int $signedQuantity): void
    {
        $sign = HsPpeStockMovement::SIGNS[$movementType] ?? null;

        $valid = match ($sign) {
            1 => $signedQuantity > 0,
            -1 => $signedQuantity < 0,
            0 => $signedQuantity !== 0,
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['quantity' => 'A '.strtolower(HsPpeStockMovement::TYPES[$movementType] ?? $movementType).' line has the wrong sign or is zero.']);
        }
    }

    protected function guardQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw ValidationException::withMessages(['quantity' => 'Enter a whole number of at least 1.']);
        }
    }

    protected function requireReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return mb_substr($reason, 0, 1000);
    }

    protected function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    protected function audit(string $action, HsPpeStockMovement $movement): void
    {
        $movement->loadMissing(['site', 'type']);

        Audit::log($action, 'health_safety', 'hs_ppe_stock_movements', $movement->id, [
            'type' => $movement->type->name,
            'size' => $movement->size,
            'quantity' => $movement->quantity,
            'store' => $movement->site->name,
        ]);
    }
}
