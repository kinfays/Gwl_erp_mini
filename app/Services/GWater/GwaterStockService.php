<?php

namespace App\Services\GWater;

use App\Models\GwProduct;
use App\Models\GwStockMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The stock ledger of G-Water, the same idea as the PPE ledger (docs/health-safety-module-design.md section 8.9): an
 * immutable, signed list of movements in packs per location and product. The balance is a sum of it and may never go
 * negative, at any date: a sale cannot be backdated to a day the shelf was empty either. A correction is an `adjustment`
 * row; a void posts the same movement with the opposite sign (a "reversal"), so every source sums to zero once voided.
 *
 * Writes happen only here, inside the caller's transaction, after the product rows are locked (lock()): that serialises
 * concurrent writers so the balance check cannot be raced. Who may write, and into which location, is the workflow's call.
 */
class GwaterStockService
{
    public function __construct(protected GwaterVisibility $visibility) {}

    /** Take the write lock. Call first inside the transaction that posts movements (or checks a waybill number). */
    public function lock(): void
    {
        GwProduct::query()->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Packs on hand of a product at a location (Head Office, or the region without it), optionally as of a date.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     */
    public function balance(array $location, int $productId, CarbonInterface|string|null $asOf = null): int
    {
        return (int) $this->movements($location, $productId)
            ->when($asOf, fn (Builder $query) => $query->whereDate('movement_date', '<=', Carbon::parse($asOf)->toDateString()))
            ->sum('packs');
    }

    /**
     * Post one signed movement. $reversal marks the undoing of an earlier movement of the same type (any sign is allowed
     * then); every other movement must carry the sign of its type.
     *
     * @param  array{region_id: int, district_id: int|null}  $location
     * @param  array{source_type?: string|null, source_id?: int|null, note?: string|null}  $extra
     */
    public function post(User $actor, array $location, GwProduct $product, string $type, int $packs, CarbonInterface|string $date, array $extra = [], bool $reversal = false): GwStockMovement
    {
        if (! in_array($type, GwStockMovement::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown stock movement type {$type}.");
        }

        if ($packs === 0) {
            throw ValidationException::withMessages(['packs' => 'A movement of zero packs is not recorded.']);
        }

        if (! $reversal) {
            $this->guardSign($type, $packs);
        }

        return DB::transaction(function () use ($actor, $location, $product, $type, $packs, $date, $extra) {
            $this->lock();

            $movement = GwStockMovement::query()->create([
                'region_id' => $location['region_id'],
                'district_id' => $location['district_id'] ?? null,
                'product_id' => $product->id,
                'movement_date' => Carbon::parse($date)->toDateString(),
                'type' => $type,
                'packs' => $packs,
                'source_type' => $extra['source_type'] ?? null,
                'source_id' => $extra['source_id'] ?? null,
                'note' => isset($extra['note']) ? mb_substr(trim((string) $extra['note']), 0, 255) : null,
                'created_by' => $actor->id,
            ]);

            // Only a movement that takes stock away can break the rule; check every day, not just today.
            $this->assertNeverNegative($location, $product);

            return $movement;
        });
    }

    /**
     * Undo every movement of a source (a sale, trip or opening balance being voided) with equal and opposite ones.
     *
     * @param  array{region_id: int, district_id: int|null}  $location
     * @return Collection<int, GwStockMovement>
     */
    public function reverseSource(User $actor, array $location, string $sourceType, int $sourceId, string $note): Collection
    {
        return DB::transaction(function () use ($actor, $location, $sourceType, $sourceId, $note) {
            $this->lock();

            return GwStockMovement::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->get()
                ->groupBy(fn (GwStockMovement $movement) => $movement->product_id.'|'.$movement->type.'|'.$movement->movement_date->toDateString())
                ->map(function (Collection $group) use ($actor, $location, $sourceType, $sourceId, $note) {
                    $net = (int) $group->sum('packs');
                    $first = $group->first();

                    // Already reversed (net zero): nothing left to undo.
                    return $net === 0 ? null : $this->post(
                        $actor,
                        $location,
                        GwProduct::query()->findOrFail($first->product_id),
                        $first->type,
                        -$net,
                        $first->movement_date,
                        ['source_type' => $sourceType, 'source_id' => $sourceId, 'note' => $note],
                        reversal: true
                    );
                })
                ->filter()
                ->values();
        });
    }

    /**
     * The ledger of a product at a location with the running balance, oldest first.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return Collection<int, array{movement: GwStockMovement, balance: int}>
     */
    public function ledger(array $location, int $productId, CarbonInterface|string|null $from = null, CarbonInterface|string|null $to = null): Collection
    {
        $running = 0;
        $before = $from ? $this->balance($location, $productId, Carbon::parse($from)->subDay()) : 0;
        $running = $before;

        return $this->movements($location, $productId)
            ->with('creator')
            ->when($from, fn (Builder $query) => $query->whereDate('movement_date', '>=', Carbon::parse($from)->toDateString()))
            ->when($to, fn (Builder $query) => $query->whereDate('movement_date', '<=', Carbon::parse($to)->toDateString()))
            ->orderByRaw('DATE(movement_date) asc')
            ->orderBy('id')
            ->get()
            ->map(function (GwStockMovement $movement) use (&$running) {
                $running += $movement->packs;

                return ['movement' => $movement, 'balance' => $running];
            });
    }

    // ------------------------------------------------------------------ rules

    /** @param array{region_id: int|null, district_id: int|null} $location */
    protected function movements(array $location, int $productId): Builder
    {
        return $this->visibility->atLocation(GwStockMovement::query()->where('gw_stock_movements.product_id', $productId), $location);
    }

    protected function guardSign(string $type, int $packs): void
    {
        $ok = match ($type) {
            GwStockMovement::OPENING, GwStockMovement::PURCHASE => $packs > 0,
            GwStockMovement::SALE, GwStockMovement::DAMAGE => $packs < 0,
            default => true, // an adjustment goes either way
        };

        if (! $ok) {
            throw ValidationException::withMessages(['packs' => "A {$type} movement has the wrong sign."]);
        }
    }

    /**
     * The balance at the end of every day must never be below zero. It is judged per day, not per line: what comes in and
     * goes out on the same day nets off (a voided sale's reversal sits on the day of the sale it undoes).
     */
    protected function assertNeverNegative(array $location, GwProduct $product): void
    {
        $perDay = $this->movements($location, $product->id)
            ->get(['packs', 'movement_date'])
            ->groupBy(fn (GwStockMovement $row) => $row->movement_date->toDateString())
            ->map(fn ($rows) => (int) $rows->sum('packs'))
            ->sortKeys();

        $running = 0;

        foreach ($perDay as $day => $net) {
            $running += $net;

            if ($running < 0) {
                throw ValidationException::withMessages([
                    'packs' => sprintf(
                        'Not enough %s in stock: this would leave %d pack(s) on %s.',
                        strtolower($product->code),
                        $running,
                        Carbon::parse($day)->format('d M Y')
                    ),
                ]);
            }
        }
    }
}
