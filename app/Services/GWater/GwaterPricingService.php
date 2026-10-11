<?php

namespace App\Services\GWater;

use App\Models\GwDamage;
use App\Models\GwPrice;
use App\Models\GwProduct;
use App\Models\GwPurchaseLine;
use App\Models\GwSale;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The ONE place a price is looked up (docs/17-module-g-water.md section 3, `gw_prices`). Selling and cost prices live in a
 * single dated list; a location's own latest row dated on or before the day wins, otherwise the system placeholder default
 * (a row with no location). A sale, a purchase line and a damage freeze the price they were priced at, so a later row never
 * rewrites history, and a row dated in the past is refused if entries it would re-price already exist.
 *
 * Money is integer pesewas. This class reads only; GwaterWorkflowService writes (a price row is immutable once added).
 */
class GwaterPricingService
{
    public function __construct(protected GwaterVisibility $visibility) {}

    /**
     * The row in force for a product on a day at a location: the location's own, else the default. Null only when nothing
     * is on the list for that date at all (e.g. before the first placeholder row).
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     */
    public function lookup(int $productId, CarbonInterface|string $date, array $location): ?GwPrice
    {
        $day = Carbon::parse($date)->toDateString();

        return $this->latest($this->ownRows($productId, $location), $day)
            ?? $this->latest($this->defaultRows($productId), $day);
    }

    public function sellingPrice(int $productId, CarbonInterface|string $date, array $location): ?int
    {
        return $this->lookup($productId, $date, $location)?->selling_price;
    }

    public function costPrice(int $productId, CarbonInterface|string $date, array $location): ?int
    {
        return $this->lookup($productId, $date, $location)?->cost_price;
    }

    /**
     * What is in force today for every active product, with whether it is the placeholder default.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return Collection<int, array{product: GwProduct, price: GwPrice|null}>
     */
    public function inForce(array $location, CarbonInterface|string|null $date = null): Collection
    {
        $day = $date ?? today();

        return GwProduct::query()->active()->orderBy('id')->get()->map(fn (GwProduct $product) => [
            'product' => $product,
            'price' => $this->lookup($product->id, $day, $location),
        ]);
    }

    /**
     * The location's own rows and the default rows of a product, newest first, each tagged with where it comes from.
     *
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return Collection<int, GwPrice>
     */
    public function history(array $location, int $productId): Collection
    {
        $own = $this->ownRows($productId, $location)->with('creator')->get();
        $default = $this->defaultRows($productId)->with('creator')->get();

        return $own->merge($default)
            ->sortByDesc(fn (GwPrice $row) => $row->effective_from->toDateString().str_pad((string) $row->id, 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * Entries already posted that a new price row would re-price: they sit from its date until the next row of the same list,
     * were priced differently, and so would no longer match the list. Empty = the row may be added.
     *
     * @param  array{region_id: int|null, district_id: int|null}|null  $location  null = the placeholder default
     * @return Collection<int, array{kind: string, date: string, packs: int, price: int}>
     */
    public function affectedEntries(?array $location, int $productId, CarbonInterface|string $effectiveFrom, int $sellingPrice, int $costPrice): Collection
    {
        $from = Carbon::parse($effectiveFrom)->toDateString();

        $rows = $location === null ? $this->defaultRows($productId) : $this->ownRows($productId, $location);
        $until = $rows->clone()->whereDate('effective_from', '>', $from)->orderByRaw('DATE(effective_from) asc')->first()?->effective_from?->toDateString();

        $window = fn (Builder $query, string $column) => $query
            ->whereDate($column, '>=', $from)
            ->when($until, fn (Builder $inner) => $inner->whereDate($column, '<', $until));

        $sales = $window(GwSale::query()->posted()->where('product_id', $productId)->where('unit_price', '!=', $sellingPrice), 'sale_date')->get()
            ->map(fn (GwSale $sale) => ['kind' => 'sale', 'date' => $sale->sale_date->toDateString(), 'packs' => $sale->packs, 'price' => $sale->unit_price, 'location' => $this->place($sale)]);

        $lines = GwPurchaseLine::query()
            ->with('purchase')
            ->where('product_id', $productId)
            ->where('unit_cost', '!=', $costPrice)
            ->whereHas('purchase', fn (Builder $purchase) => $window($purchase->posted(), 'purchase_date'))
            ->get()
            ->map(fn (GwPurchaseLine $line) => ['kind' => 'purchase', 'date' => $line->purchase->purchase_date->toDateString(), 'packs' => $line->packs, 'price' => $line->unit_cost, 'location' => $this->place($line->purchase)]);

        $damages = $window(GwDamage::query()->posted()->where('product_id', $productId)->where('unit_cost', '!=', $costPrice), 'damage_date')->get()
            ->map(fn (GwDamage $damage) => ['kind' => 'damage', 'date' => $damage->damage_date->toDateString(), 'packs' => $damage->packs, 'price' => $damage->unit_cost, 'location' => $this->place($damage)]);

        $entries = $sales->concat($lines)->concat($damages);

        if ($location !== null) {
            $entries = $entries->filter(fn (array $entry) => $this->visibility->sameLocation($entry['location'], $location));
        } else {
            // A default row only moves entries of locations that have no price list of their own for that day.
            $entries = $entries->reject(fn (array $entry) => $this->ownRows($productId, $entry['location'])->whereDate('effective_from', '<=', $entry['date'])->exists());
        }

        return $entries->sortBy('date')->values()->map(fn (array $entry) => collect($entry)->except('location')->all());
    }

    /** @throws ValidationException listing (at most five of) the entries the new row would re-price */
    public function assertCanAdd(?array $location, int $productId, CarbonInterface|string $effectiveFrom, int $sellingPrice, int $costPrice): void
    {
        $affected = $this->affectedEntries($location, $productId, $effectiveFrom, $sellingPrice, $costPrice);

        if ($affected->isEmpty()) {
            return;
        }

        $listed = $affected->take(5)->map(fn (array $entry) => sprintf(
            '%s of %d pack(s) on %s priced at %s',
            $entry['kind'],
            $entry['packs'],
            Carbon::parse($entry['date'])->format('d M Y'),
            Money::format($entry['price'])
        ))->implode('; ');

        throw ValidationException::withMessages([
            'effective_from' => 'Entries already posted would be re-priced by a price dated '.Carbon::parse($effectiveFrom)->format('d M Y')
                .' ('.$affected->count().': '.$listed.($affected->count() > 5 ? '; ...' : '').'). Void them first, or date the new price after them.',
        ]);
    }

    // ------------------------------------------------------------------ the rows

    /** The location's own rows for a product (a location is Head Office, or the region without it). */
    protected function ownRows(int $productId, array $location): Builder
    {
        return $this->visibility->atLocation(GwPrice::query()->where('gw_prices.product_id', $productId), $location)
            ->whereNotNull('gw_prices.region_id');
    }

    protected function defaultRows(int $productId): Builder
    {
        return GwPrice::query()->where('product_id', $productId)->whereNull('region_id')->whereNull('district_id');
    }

    protected function latest(Builder $rows, string $day): ?GwPrice
    {
        return $rows->whereDate('effective_from', '<=', $day)
            ->orderByRaw('DATE(effective_from) desc')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array{region_id: int, district_id: int|null} */
    protected function place(object $row): array
    {
        return ['region_id' => (int) $row->region_id, 'district_id' => $row->district_id !== null ? (int) $row->district_id : null];
    }
}
