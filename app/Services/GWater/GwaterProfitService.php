<?php

namespace App\Services\GWater;

use App\Models\GwDamage;
use App\Models\GwExpense;
use App\Models\GwOpeningBalance;
use App\Models\GwProduct;
use App\Models\GwPurchase;
use App\Models\GwSale;
use App\Models\GwSaleReceipt;
use App\Models\GwStockMovement;
use App\Models\User;
use App\Models\VehicleExpense;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The ONE evaluator of G-Water's profit and loss (docs/17-module-g-water.md section 4): the Profit and loss screen, the
 * Overview, the charts and every export call report() and show what it returns; nothing else works a profit figure out.
 * (Like PpeComplianceService: do not add a second implementation, in SQL or in a screen.)
 *
 *   Revenue            = sum of the sales' amounts (frozen at the price in force on the day);
 *   Cost of water sold = packs sold x the ROLLING WEIGHTED-AVERAGE unit cost of that product on the day of the sale. The
 *                        average is carried over the whole history (opening stock at its frozen cost, every trip line at its
 *                        frozen cost, sales and damages at the running average), not restarted each period;
 *   Gross profit       = revenue - cost of water sold;
 *   Damages            = damaged packs x the cost frozen on the damage (written off at cost);
 *   Fuel               = the linked Transport expense of a trip (read live, so it follows a later change in Transport) or the
 *                        G-Water fuel expenses; labour = loading + offloading; other = other expenses;
 *   Net profit         = gross profit - damages - fuel - labour - other.
 * Revenue is recognised when the sale is made, paid at once or on credit. Next to it the report shows the cash side: credit sales
 * made in the period, cash collected (cash sales + money received on credit sales) and what customers still owe at its end.
 * No price is read from the price list for history: only the prices frozen on the lines. Voided rows are ignored. Money is
 * integer pesewas; ratios are recomputed from sums and are null on a zero denominator. The payload holds only arrays and
 * scalars so it can be cached; the cache key carries the user's scope, the location's books version (bumped on every write,
 * GwaterBooksVersion) and a fingerprint of the Transport fuel rows, so it is never stale.
 *
 * Fuel, loading, offloading and other costs belong to trips, not to products, so net profit is a combined figure; per product
 * the evaluation goes down to gross profit and damages.
 */
class GwaterProfitService
{
    public const CACHE_SECONDS = 300;

    /** Longest span the monthly series is drawn for. */
    public const MAX_MONTHS = 36;

    public function __construct(
        protected GwaterVisibility $visibility,
        protected GwaterSummaryService $summary,
    ) {}

    /**
     * @param  array{region_id: int|null, district_id: int|null}  $location  the books to evaluate (already authorised by the caller)
     * @return array<string, mixed>
     */
    public function report(array $location, CarbonInterface|string $from, CarbonInterface|string $to, User $actor): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $key = 'gwater:pnl:'.md5(json_encode([
            $this->visibility->keyFor($actor),
            GwaterBooksVersion::unitKey($location),
            GwaterBooksVersion::current($location),
            $from->toDateString(),
            $to->toDateString(),
            today()->toDateString(),
            $this->transportFingerprint($location),
        ]));

        return Cache::remember($key, self::CACHE_SECONDS, fn () => $this->build($location, $from, $to));
    }

    /** The period presets of the screens: this month, last month, this quarter, last quarter. @return array{0: Carbon, 1: Carbon} */
    public static function preset(string $name, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy();

        return match ($name) {
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_quarter' => [$today->copy()->startOfQuarter(), $today->copy()->endOfQuarter()->startOfDay()],
            'last_quarter' => [$today->copy()->subQuarterNoOverflow()->startOfQuarter(), $today->copy()->subQuarterNoOverflow()->endOfQuarter()->startOfDay()],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()->startOfDay()],
        };
    }

    // ------------------------------------------------------------------ building

    /** @return array<string, mixed> */
    protected function build(array $location, Carbon $from, Carbon $to): array
    {
        $products = GwProduct::query()->active()->orderBy('id')->get();
        $data = $this->gather($location, $to);
        $costs = $this->costOfSales($products, $data);

        $inPeriod = fn (string $date) => $date >= $from->toDateString() && $date <= $to->toDateString();

        $perProduct = [];

        foreach ($products as $product) {
            $line = $this->productLine($product, $data, $costs, $inPeriod);
            $perProduct[$product->code] = ['name' => $product->name, ...$line, ...$this->ratios($line)];
        }

        $trips = $this->tripRows($data['trips'], $inPeriod);

        // Fuel: the Transport expense of a trip counts on the trip's date; G-Water fuel expenses count on their own date.
        $fuel = (int) $trips->where('fuel_source', 'Transport')->sum('fuel') + $this->expenseSum($data['expenses'], GwExpense::FUEL, $inPeriod);
        $labourLoading = $this->expenseSum($data['expenses'], GwExpense::LOADING, $inPeriod);
        $labourOffloading = $this->expenseSum($data['expenses'], GwExpense::OFFLOADING, $inPeriod);
        $other = $this->expenseSum($data['expenses'], GwExpense::OTHER, $inPeriod);

        $combined = $this->combine($perProduct, $fuel, $labourLoading, $labourOffloading, $other);
        $combined += [
            'credit_sales' => (int) $data['sales']->where('credit', true)->filter(fn (array $s) => $inPeriod($s['date']))->sum('amount'),
            'cash_collected' => (int) $data['sales']->where('credit', false)->filter(fn (array $s) => $inPeriod($s['date']))->sum('amount')
                + (int) $data['receipts']->filter(fn (array $r) => $inPeriod($r['date']))->sum('amount'),
            'owed_by_customers' => $this->summary->owedByCustomers($location, $to)['total'],
        ];

        $today = today();
        $inProgress = $from->lte($today) && $to->gte($today);

        return [
            'location' => $location,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $from->format('d M Y').' to '.$to->format('d M Y'),
                'in_progress' => $inProgress,
                'status' => $inProgress ? 'In progress: figures to date, the period is not over' : 'Complete',
            ],
            'products' => $perProduct,
            'combined' => $combined,
            'trips' => $trips->all(),
            'months' => $this->months($products, $data, $costs, $trips, $from, $to),
            'owed' => $this->summary->owedToGwater($location, $to),
            'notes' => $this->notes($data, $perProduct),
        ];
    }

    /**
     * Everything dated up to the end of the period, as plain arrays: the rolling average needs the whole history.
     *
     * @return array{openings: Collection, lines: Collection, adjustments: Collection, sales: Collection, receipts: Collection, damages: Collection, expenses: Collection, trips: Collection}
     */
    protected function gather(array $location, Carbon $to): array
    {
        $day = $to->toDateString();
        $at = fn ($query) => $this->visibility->atLocation($query, $location);

        $openings = $at(GwOpeningBalance::query()->posted())->whereDate('as_of_date', '<=', $day)->with('lines')->get()
            ->flatMap(fn (GwOpeningBalance $opening) => $opening->lines->where('packs', '>', 0)->map(fn ($line) => [
                'date' => $opening->as_of_date->toDateString(), 'order' => 0, 'id' => $line->id, 'product_id' => $line->product_id, 'packs' => $line->packs, 'cost' => $line->packs * $line->unit_cost,
            ]));

        $trips = $at(GwPurchase::query()->posted())->whereDate('purchase_date', '<=', $day)
            ->with(['lines', 'expenses', 'vehicleExpense', 'mileageLog', 'vehicle'])->orderByRaw('DATE(purchase_date) asc')->orderBy('id')->get();

        $lines = $trips->flatMap(fn (GwPurchase $trip) => $trip->lines->map(fn ($line) => [
            'date' => $trip->purchase_date->toDateString(), 'order' => 1, 'id' => $line->id, 'trip_id' => $trip->id, 'product_id' => $line->product_id, 'packs' => $line->packs, 'cost' => $line->packs * $line->unit_cost,
        ]));

        $adjustments = $at(GwStockMovement::query()->where('type', GwStockMovement::ADJUSTMENT))->whereDate('movement_date', '<=', $day)->get()
            ->map(fn (GwStockMovement $movement) => ['date' => $movement->movement_date->toDateString(), 'order' => 2, 'id' => $movement->id, 'product_id' => $movement->product_id, 'packs' => $movement->packs]);

        $sales = $at(GwSale::query()->posted())->whereDate('sale_date', '<=', $day)->get()
            ->map(fn (GwSale $sale) => ['date' => $sale->sale_date->toDateString(), 'order' => 3, 'id' => $sale->id, 'product_id' => $sale->product_id, 'packs' => $sale->packs, 'amount' => $sale->amount, 'credit' => $sale->isCredit()]);

        $receipts = $at(GwSaleReceipt::query()->posted())->whereDate('received_on', '<=', $day)->get()
            ->map(fn (GwSaleReceipt $receipt) => ['date' => $receipt->received_on->toDateString(), 'amount' => $receipt->amount]);

        $damages = $at(GwDamage::query()->posted())->whereDate('damage_date', '<=', $day)->get()
            ->map(fn (GwDamage $damage) => ['date' => $damage->damage_date->toDateString(), 'order' => 4, 'id' => $damage->id, 'product_id' => $damage->product_id, 'packs' => $damage->packs, 'write_off' => $damage->packs * $damage->unit_cost]);

        $expenses = $at(GwExpense::query()->posted())->whereDate('expense_date', '<=', $day)->get()
            ->map(fn (GwExpense $expense) => ['date' => $expense->expense_date->toDateString(), 'id' => $expense->id, 'category' => $expense->category, 'amount' => $expense->amount, 'purchase_id' => $expense->purchase_id]);

        return compact('openings', 'lines', 'adjustments', 'sales', 'receipts', 'damages', 'expenses', 'trips');
    }

    /**
     * The rolling weighted-average cost walk, per product, oldest event first (a day's opening, trips, corrections, sales and
     * damages in that order). Returns, per sale id, the cost of water sold in pesewas.
     *
     * @return array<int, int> sale id => cost of water sold
     */
    protected function costOfSales(Collection $products, array $data): array
    {
        $cost = [];

        foreach ($products as $product) {
            $events = collect()
                ->concat($data['openings']->where('product_id', $product->id)->map(fn ($e) => $e + ['kind' => 'in']))
                ->concat($data['lines']->where('product_id', $product->id)->map(fn ($e) => $e + ['kind' => 'in']))
                ->concat($data['adjustments']->where('product_id', $product->id)->map(fn ($e) => $e + ['kind' => 'adjust']))
                ->concat($data['sales']->where('product_id', $product->id)->map(fn ($e) => $e + ['kind' => 'sale']))
                ->concat($data['damages']->where('product_id', $product->id)->map(fn ($e) => $e + ['kind' => 'damage']))
                ->sortBy([['date', 'asc'], ['order', 'asc'], ['id', 'asc']]);

            $units = 0;
            $value = 0.0;

            foreach ($events as $event) {
                $average = $units > 0 ? $value / $units : 0.0;

                switch ($event['kind']) {
                    case 'in':
                        $units += $event['packs'];
                        $value += $event['cost'];
                        break;
                    case 'adjust':
                        // A correction moves packs, not the average.
                        $moved = $event['packs'] > 0 ? $event['packs'] : -min($units, -$event['packs']);
                        $units += $moved;
                        $value += $moved * $average;
                        break;
                    case 'sale':
                        $cost[$event['id']] = (int) round($event['packs'] * $average);
                        $units -= $event['packs'];
                        $value -= $event['packs'] * $average;
                        break;
                    case 'damage':
                        $units -= $event['packs'];
                        $value -= $event['packs'] * $average;
                        break;
                }

                if ($units <= 0) {
                    $units = max($units, 0);
                    $value = 0.0;
                }
            }
        }

        return $cost;
    }

    /** @return array<string, mixed> */
    protected function productLine(GwProduct $product, array $data, array $costs, \Closure $inPeriod): array
    {
        $sales = $data['sales']->where('product_id', $product->id)->filter(fn (array $s) => $inPeriod($s['date']));
        $damages = $data['damages']->where('product_id', $product->id)->filter(fn (array $d) => $inPeriod($d['date']));
        $received = $data['lines']->where('product_id', $product->id)->filter(fn (array $l) => $inPeriod($l['date']));

        $revenue = (int) $sales->sum('amount');
        $costOfSales = (int) $sales->sum(fn (array $s) => $costs[$s['id']] ?? 0);

        return [
            'packs_sold' => (int) $sales->sum('packs'),
            'revenue' => $revenue,
            'cost_of_sales' => $costOfSales,
            'gross_profit' => $revenue - $costOfSales,
            'damaged_packs' => (int) $damages->sum('packs'),
            'damages' => (int) $damages->sum('write_off'),
            'received_packs' => (int) $received->sum('packs'),
            'purchase_cost' => (int) $received->sum('cost'),
        ];
    }

    /**
     * Adds the products up and puts the trip-level costs under them, then works every ratio out from the sums.
     *
     * @param  array<string, array<string, mixed>>  $perProduct
     * @return array<string, mixed>
     */
    protected function combine(array $perProduct, int $fuel, int $loading, int $offloading, int $other): array
    {
        $sum = fn (string $key) => (int) collect($perProduct)->sum($key);

        $line = [
            'packs_sold' => $sum('packs_sold'),
            'revenue' => $sum('revenue'),
            'cost_of_sales' => $sum('cost_of_sales'),
            'gross_profit' => $sum('gross_profit'),
            'damaged_packs' => $sum('damaged_packs'),
            'damages' => $sum('damages'),
            'received_packs' => $sum('received_packs'),
            'purchase_cost' => $sum('purchase_cost'),
            'fuel' => $fuel,
            'loading' => $loading,
            'offloading' => $offloading,
            'labour' => $loading + $offloading,
            'other' => $other,
        ];
        $line['net_profit'] = $line['gross_profit'] - $line['damages'] - $line['fuel'] - $line['labour'] - $line['other'];

        return $line + $this->ratios($line);
    }

    /**
     * Every ratio of a line, from its own sums. Null when the denominator is zero (never 0, never a guess).
     *
     * @return array<string, float|int|null>
     */
    public function ratios(array $line): array
    {
        $pct = fn (?int $part, int $whole) => $whole > 0 && $part !== null ? round($part / $whole * 100, 1) : null;
        $fixed = ($line['damages'] ?? 0) + ($line['fuel'] ?? 0) + ($line['labour'] ?? 0) + ($line['other'] ?? 0);
        $unitGross = $line['packs_sold'] > 0 ? $line['gross_profit'] / $line['packs_sold'] : null;

        return [
            'gross_margin_pct' => $pct($line['gross_profit'], $line['revenue']),
            'net_margin_pct' => isset($line['net_profit']) ? $pct($line['net_profit'], $line['revenue']) : null,
            'damage_rate_pct' => $pct($line['damaged_packs'], $line['received_packs']),
            'average_price' => $line['packs_sold'] > 0 ? (int) round($line['revenue'] / $line['packs_sold']) : null,
            'average_cost' => $line['packs_sold'] > 0 ? (int) round($line['cost_of_sales'] / $line['packs_sold']) : null,
            // Packs that must be sold, at the average profit per pack, to cover damages and the trip costs.
            'break_even_packs' => isset($line['net_profit'])
                ? ($fixed === 0 ? 0 : ($unitGross !== null && $unitGross > 0 ? (int) ceil($fixed / $unitGross) : null))
                : null,
        ];
    }

    /**
     * One row per trip in the period with its landed cost, the cost of getting it here per pack and, where a Mileage entry is
     * linked, per km. Fuel is the Transport expense (read live) or the G-Water fuel expense: never both.
     */
    protected function tripRows(Collection $trips, \Closure $inPeriod): Collection
    {
        return $trips->filter(fn (GwPurchase $trip) => $inPeriod($trip->purchase_date->toDateString()))->values()->map(function (GwPurchase $trip) {
            $posted = $trip->expenses->reject(fn (GwExpense $expense) => $expense->isVoided());
            $packs = $trip->totalPacks();
            $purchase = $trip->totalCost();
            $fuel = $trip->vehicle_expense_id
                ? (int) round(((float) ($trip->vehicleExpense?->amount ?? 0)) * 100)
                : (int) $posted->where('category', GwExpense::FUEL)->sum('amount');
            $loading = (int) $posted->where('category', GwExpense::LOADING)->sum('amount');
            $offloading = (int) $posted->where('category', GwExpense::OFFLOADING)->sum('amount');
            $running = $fuel + $loading + $offloading;
            $km = (int) ($trip->mileageLog?->distance_driven ?? 0);

            return [
                'id' => $trip->id,
                'date' => $trip->purchase_date->toDateString(),
                'waybill' => $trip->waybill_no,
                'vehicle' => $trip->vehicle?->number_plate,
                'packs' => $packs,
                'purchase_cost' => $purchase,
                'fuel' => $fuel,
                'fuel_source' => $trip->vehicle_expense_id ? 'Transport' : ($fuel > 0 ? 'G-Water' : 'none'),
                'changed_in_transport' => $this->summary->changedInTransport($trip),
                'loading' => $loading,
                'offloading' => $offloading,
                'trip_cost' => $running,
                'landed_cost_per_pack' => $packs > 0 ? (int) round(($purchase + $running) / $packs) : null,
                'trip_cost_per_pack' => $packs > 0 ? (int) round($running / $packs) : null,
                'km' => $km > 0 ? $km : null,
                'fuel_per_km' => $km > 0 ? (int) round($fuel / $km) : null,
                'trip_cost_per_km' => $km > 0 ? (int) round($running / $km) : null,
            ];
        });
    }

    /** One bucket per calendar month of the period, for the chart. @return array<string, list<mixed>> */
    protected function months(Collection $products, array $data, array $costs, Collection $trips, Carbon $from, Carbon $to): array
    {
        $series = ['labels' => [], 'revenue' => [], 'cost_of_sales' => [], 'costs' => [], 'net_profit' => []];
        $month = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();

        for ($i = 0; $month->lte($end) && $i < self::MAX_MONTHS; $i++, $month->addMonth()) {
            $start = max($month->toDateString(), $from->toDateString());
            $stop = min($month->copy()->endOfMonth()->toDateString(), $to->toDateString());
            $in = fn (string $date) => $date >= $start && $date <= $stop;

            $sales = $data['sales']->filter(fn (array $s) => $in($s['date']));
            $revenue = (int) $sales->sum('amount');
            $cos = (int) $sales->sum(fn (array $s) => $costs[$s['id']] ?? 0);
            $damages = (int) $data['damages']->filter(fn (array $d) => $in($d['date']))->sum('write_off');
            // Same attribution as the period total: G-Water expenses on their date, Transport fuel on the trip's date.
            $transportFuel = (int) $trips->where('fuel_source', 'Transport')->filter(fn (array $t) => $in($t['date']))->sum('fuel');
            $expenses = (int) $data['expenses']->filter(fn (array $e) => $in($e['date']))->sum('amount');
            $running = $damages + $expenses + $transportFuel;

            $series['labels'][] = $month->format('M Y');
            $series['revenue'][] = round($revenue / 100, 2);
            $series['cost_of_sales'][] = round($cos / 100, 2);
            $series['costs'][] = round($running / 100, 2);
            $series['net_profit'][] = round(($revenue - $cos - $running) / 100, 2);
        }

        return $series;
    }

    /** @return list<string> */
    protected function notes(array $data, array $perProduct): array
    {
        $notes = [
            'Cost of water sold is the rolling weighted-average cost of each product on the day of the sale.',
            'Damages are written off at the cost frozen on each damage. No price is read from the price list for history.',
            'Fuel, loading, offloading and other costs belong to trips, not products, so net profit is a combined figure.',
            'Fuel linked from Transport follows that expense if it is changed there.',
            'Stock corrections move packs but not the average cost.',
        ];

        return $notes;
    }

    protected function expenseSum(Collection $expenses, string $category, \Closure $inPeriod): int
    {
        return (int) $expenses->filter(fn (array $e) => $e['category'] === $category && $inPeriod($e['date']))->sum('amount');
    }

    /** The newest change to any Transport fuel row a trip of this location reads, so a change in Transport is never cached over. */
    protected function transportFingerprint(array $location): string
    {
        $ids = $this->visibility->atLocation(GwPurchase::query()->posted(), $location)->whereNotNull('vehicle_expense_id')->pluck('vehicle_expense_id');

        return $ids->isEmpty() ? '-' : (string) VehicleExpense::query()->whereIn('id', $ids)->max('updated_at').'|'.$ids->count();
    }
}
