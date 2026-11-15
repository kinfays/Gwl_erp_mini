<?php

namespace App\Services\Commercial\BillingDetails;

use App\Models\CommercialBillingDetailBand;
use App\Models\CommercialBillingDetailIssue;
use App\Models\CommercialRoute;
use App\Models\District;
use App\Services\Commercial\Customers\XlsxStreamReader;
use App\Services\Commercial\RouteResolver;
use Illuminate\Support\Facades\DB;

/**
 * One streamed pass over a Detailed Billing Report (design 8.39). It reads the rows through BillingDetailsParser, adds every customer
 * into a few thousand in-memory aggregates and keeps nothing else about a customer:
 *
 *   rollups        (route heading, category, account status, meter status, bill status): sums and counts, in whole PESEWAS (and thousandths of
 *                  a unit of consumption) so a sum has no floating-point drift. The columns are the packed arrays below, not associative
 *                  arrays, so a few thousand keys cost a few megabytes.
 *   bands          billed customers and consumption per consumption band, per (district, category)
 *   issues         the capped exception lists (largest figure first); an account number, its route and the figure, never a name
 *   routes         per route heading: rows against its own totals row (the reconciliation)
 *   concentration  the owing balances per (district, category) as plain integers, reduced to "the share held by the top 10 percent of
 *                  owing accounts" at the end (B7)
 *
 * Duplicate accounts are found through the staging table (commercial_billing_detail_accounts: batch, account, route heading), filled one
 * chunk of rows at a time, so no PHP array of accounts exists. Memory therefore depends on the number of routes and distinct keys, never
 * on the number of customers; the measured figure is in the design doc.
 *
 * It writes only to the staging table. Writing the rollups, bands and issues is the import service's job, after the reconciliation says
 * the file may go ahead.
 */
class BillingDetailsScan
{
    // ---- the packed columns of one rollup (the index is the position; COLUMNS says what it is called in the table) ----
    public const C_CUSTOMERS = 0;

    public const C_CONSUME = 1;          // thousandths

    public const C_WATER = 2;            // pesewas from here to C_BALANCE

    public const C_RURAL = 3;

    public const C_FIRE = 4;

    public const C_SEWAGE = 5;

    public const C_SERVICE = 6;

    public const C_MONTH_TOTAL = 7;

    public const C_PREVIOUS = 8;

    public const C_PAID = 9;

    public const C_PREV_PAY = 10;

    public const C_OFFSET = 11;

    public const C_ADJUSTED = 12;

    public const C_BALANCE = 13;

    public const C_ZERO = 14;

    public const C_BILLED_UNPAID = 15;

    public const C_BILLED_UNPAID_AMT = 16;   // pesewas

    public const C_OWING = 17;

    public const C_CREDIT = 18;

    public const C_OWING_AMT = 19;           // pesewas

    public const C_CREDIT_AMT = 20;          // pesewas

    public const C_ADJ_COUNT = 21;

    public const C_TARIFF_CHECKED = 22;

    public const C_TARIFF_MISMATCH = 23;

    public const C_READ_0_30 = 24;

    public const C_READ_31_60 = 25;

    public const C_READ_61_90 = 26;

    public const C_READ_OVER_90 = 27;

    public const C_READ_NEVER = 28;

    public const C_OUTSIDE = 29;

    public const C_COMPONENTS = 30;

    public const C_NEGATIVE = 31;

    /** Column names, in index order. */
    public const COLUMNS = [
        'customers', 'consume', 'water_charge', 'rural_water', 'fire_fighting', 'sewage', 'service_charge', 'month_total', 'previous_balance',
        'paid_this_month', 'prev_month_payment', 'offset_payment', 'adjusted', 'balance_due', 'zero_consume', 'billed_unpaid', 'billed_unpaid_amount',
        'owing', 'credit', 'owing_amount', 'credit_amount', 'adjusted_count', 'tariff_checked', 'tariff_mismatch', 'read_days_0_30', 'read_days_31_60',
        'read_days_61_90', 'read_days_over_90', 'read_never', 'bill_month_outside', 'components_off', 'negative_consume',
    ];

    /** The columns held in whole pesewas (the rest are counts, and C_CONSUME is in thousandths). */
    public const MONEY = [
        self::C_WATER, self::C_RURAL, self::C_FIRE, self::C_SEWAGE, self::C_SERVICE, self::C_MONTH_TOTAL, self::C_PREVIOUS, self::C_PAID, self::C_PREV_PAY, self::C_OFFSET,
        self::C_ADJUSTED, self::C_BALANCE, self::C_BILLED_UNPAID_AMT, self::C_OWING_AMT, self::C_CREDIT_AMT,
    ];

    /** Bill statuses that are a BILL (the customer was billed). ESTIMATED and ESTIMATE are both estimated bills (confirmed by the Commercial team). */
    public const BILLED = ['REGULAR', 'ESTIMATED', 'ESTIMATE'];

    public const ESTIMATED = ['ESTIMATED', 'ESTIMATE'];

    /** Month total against the five charges added: more than this (GH¢) apart is "components not adding up" (rounding of five cells). */
    public const COMPONENT_TOLERANCE_PESEWAS = 5;

    /** A route's totals row against its rows: this many pesewas, or 0.01% of the figure, whichever is more (the source may add unrounded values). */
    public const ROUTE_MONEY_TOLERANCE_PESEWAS = 10;

    /** Rows held before they are checked for duplicates and added (never more than a route's worth: a heading or a totals row flushes too). */
    protected const CHUNK = 1000;

    /** A progress callback runs every this many rows. */
    protected const PROGRESS_EVERY = 2000;

    protected int $batchId = 0;

    protected ?int $regionId = null;

    protected int $periodToDay = 0;

    protected string $periodFrom = '';

    protected string $periodTo = '';

    /** @var list<int>|null */
    protected ?array $districtIds = null;

    protected int $cap = 200;

    protected float $tolPct = 1.0;

    protected float $tolAmount = 1.0;

    /** @var array<string, array{active: bool, billing: bool}> */
    protected array $statusFlags = [];

    /** @var array<string, true> */
    protected array $knownCategories = [];

    /** @var array<string, District|null> */
    protected array $districtCache = [];

    // ---- state ----
    /** @var array<int, string> heading seq => label */
    protected array $labels = [];

    /** @var array<int, array{code: string|null, district_label: string|null, district_id: int|null}> */
    protected array $placed = [];

    /** @var array<int, string> heading seq => district id as text, or "l:<label>" while the district is unknown */
    protected array $dKeys = [];

    /** @var array<int, bool> */
    protected array $skip = [];

    /** @var array<int, int|null> heading seq => the CANONICAL route of the route master (null while the district is unknown, or the route is another district's) */
    protected array $routeIds = [];

    /** @var array<int, District|null> */
    protected array $districtModels = [];

    /** @var array<int, array<string, mixed>> */
    protected array $blocks = [];

    protected ?int $current = null;

    /** @var list<array{seq: int, v: array<string, mixed>}> */
    protected array $buffer = [];

    /** @var array<string, list<int>> key => the packed rollup columns */
    protected array $rollups = [];

    /** @var array<string, array<string, array{0: int, 1: int}>> "district\x1fcategory" => band => [customers, consume thousandths] */
    protected array $bands = [];

    /** @var array<string, array<string, list<array{0: int, 1: string, 2: int, 3: int}>>> kind => district key => [abs amount, account, seq, signed amount in pesewas] */
    protected array $issues = [];

    /** @var array<string, list<int>> "district\x1fcategory" => the owing balances (pesewas) */
    protected array $owing = [];

    /** @var array<string, array<string, int>> */
    protected array $counts = ['bill' => [], 'status' => [], 'meter' => [], 'category' => []];

    protected int $rowsSeen = 0;

    protected int $rowsKept = 0;

    protected int $skippedRows = 0;

    protected int $malformed = 0;

    protected int $duplicates = 0;

    protected int $orphanRows = 0;

    protected int $orphanTotals = 0;

    /** @var array<string, mixed> */
    protected array $filters = ['period' => null, 'region' => null];

    /** @var list<string> */
    protected array $missingColumns = [];

    /** @var callable|null */
    protected $progress = null;

    public function __construct(protected BillingDetailsParser $parser, protected RouteResolver $routes) {}

    /**
     * @param  array{sheet: string, batch_id: int, region_id: int|null, period_from: string, period_to: string, district_ids?: list<int>|null, issue_cap?: int, tariff_pct?: float, tariff_amount?: float}  $options
     * @param  callable(array<string, int>): void|null  $progress  told how many rows and routes were read, every few thousand rows
     * @return array<string, mixed>
     */
    public function run(string $path, array $options, ?callable $progress = null): array
    {
        $this->reset($options, $progress);

        $reader = new XlsxStreamReader($path);

        try {
            foreach ($this->parser->parse($reader->rows($options['sheet'])) as $event) {
                match ($event['kind']) {
                    'filters' => $this->filters = ['period' => $this->filters['period'] ?? $event['period'], 'region' => $this->filters['region'] ?? $event['region']],
                    'missing_columns' => $this->missingColumns = array_values(array_unique([...$this->missingColumns, ...$event['columns']])),
                    'heading' => $this->heading($event['label']),
                    'row' => $this->row($event),
                    'totals' => $this->totals($event),
                    'malformed' => $this->malformed++,
                    default => null,
                };
            }

            $this->flush();
        } finally {
            $reader->close();
        }

        return $this->result();
    }

    // ---------------------------------------------------------------- setup

    protected function reset(array $options, ?callable $progress): void
    {
        $this->batchId = (int) $options['batch_id'];
        $this->regionId = $options['region_id'] ?? null;
        $this->periodFrom = (string) $options['period_from'];
        $this->periodTo = (string) $options['period_to'];
        $this->periodToDay = intdiv((int) strtotime($this->periodTo.' 00:00:00 UTC'), 86400);
        $this->districtIds = $options['district_ids'] ?? null;
        $this->cap = max(1, (int) ($options['issue_cap'] ?? config('gwl.commercial_billing_detail_issue_cap', 200)));
        $this->tolPct = (float) ($options['tariff_pct'] ?? config('gwl.commercial_tariff_tolerance_pct', 1.0));
        $this->tolAmount = (float) ($options['tariff_amount'] ?? config('gwl.commercial_tariff_tolerance_amount', 1.0));
        $this->progress = $progress;

        $this->statusFlags = [];

        foreach (DB::table('commercial_customer_statuses')->get(['code', 'is_active', 'is_billing']) as $row) {
            $this->statusFlags[strtoupper((string) $row->code)] = ['active' => (bool) $row->is_active, 'billing' => (bool) $row->is_billing];
        }

        $this->knownCategories = [];

        foreach (DB::table('commercial_customer_categories')->whereNotNull('code')->pluck('code') as $code) {
            $this->knownCategories[(string) $code] = true;
        }

        $this->districtCache = [];
        $this->labels = $this->placed = $this->dKeys = $this->skip = $this->routeIds = $this->districtModels = $this->blocks = $this->buffer = [];
        $this->rollups = $this->bands = $this->issues = $this->owing = [];
        $this->counts = ['bill' => [], 'status' => [], 'meter' => [], 'category' => []];
        $this->current = null;
        $this->rowsSeen = $this->rowsKept = $this->skippedRows = $this->malformed = $this->duplicates = $this->orphanRows = $this->orphanTotals = 0;
        $this->filters = ['period' => null, 'region' => null];
        $this->missingColumns = [];
    }

    // ---------------------------------------------------------------- events

    protected function heading(string $label): void
    {
        $this->flush();
        $this->openBlock($label);
    }

    protected function openBlock(string $label): int
    {
        $seq = count($this->labels) + 1;
        $placed = $this->routes->place($label, $this->regionId, $this->districtCache);
        $routeId = null;

        // a district user keeps only their own district's routes; a route whose district nobody can place is not theirs
        $skip = $this->districtIds !== null && ! in_array($placed['district_id'], $this->districtIds, true);

        // the heading is resolved to its CANONICAL route (an unseen route is created pending, as every report does), so the rollups, the
        // bands and the lists all use the one district the route master gives it; a skipped route (another district's) creates nothing
        if (! $skip && $placed['district_id'] !== null && $placed['code'] !== null) {
            $district = ($this->districtModels[$placed['district_id']] ??= District::query()->find($placed['district_id']));

            if ($district) {
                $route = $this->routes->resolveIn($district, (string) $placed['code'], CommercialRoute::SOURCE_BILLING);
                $routeId = (int) $route->id;
                $placed['district_id'] = (int) $route->district_id;
                $skip = $this->districtIds !== null && ! in_array($placed['district_id'], $this->districtIds, true);
            }
        }

        $this->labels[$seq] = $label;
        $this->placed[$seq] = $placed;
        $this->routeIds[$seq] = $skip ? null : $routeId;
        $this->dKeys[$seq] = $placed['district_id'] !== null ? (string) $placed['district_id'] : 'l:'.($placed['district_label'] ?? '');
        $this->skip[$seq] = $skip;
        $this->blocks[$seq] = ['count' => 0, 'month_total' => 0, 'paid' => 0, 'balance_due' => 0, 'totals' => null, 'diffs' => [], 'failed' => false, 'skipped_rows' => 0];
        $this->current = $seq;

        return $seq;
    }

    /** @param  array{row_no: int, v: array<string, mixed>}  $event */
    protected function row(array $event): void
    {
        $this->rowsSeen++;

        if ($this->current === null) {
            $this->orphanRows++;
            $this->openBlock('(no route heading)');
        }

        $seq = $this->current;

        if ($this->skip[$seq]) {
            $this->skippedRows++;
            $this->blocks[$seq]['skipped_rows']++;

            return;
        }

        $this->buffer[] = ['seq' => $seq, 'v' => $event['v']];

        if (count($this->buffer) >= self::CHUNK) {
            $this->flush();
        }

        if ($this->progress && $this->rowsSeen % self::PROGRESS_EVERY === 0) {
            ($this->progress)(['rows' => $this->rowsSeen, 'routes' => count($this->labels)]);
        }
    }

    /** @param  array{row_no: int, count: ?int, sums: array<string, float>}  $event */
    protected function totals(array $event): void
    {
        $this->flush();

        if ($this->current === null) {
            $this->orphanTotals++;

            return;
        }

        $block = &$this->blocks[$this->current];

        if ($this->skip[$this->current] || $block['totals'] !== null) {
            return;   // another district's route, or a second totals row under one heading (the first one is the route's)
        }

        $reported = ['count' => $event['count']];
        $diffs = [];

        if ($event['count'] !== null && $event['count'] !== $block['count']) {
            $diffs['count'] = [$block['count'], $event['count']];
        }

        foreach (['month_total' => 'month_total', 'paid' => 'paid', 'balance_due' => 'balance_due'] as $key => $field) {
            if (! isset($event['sums'][$field])) {
                continue;
            }

            $theirs = $this->pesewas($event['sums'][$field]);
            $reported[$key] = $theirs;

            if (abs($block[$key] - $theirs) > max(self::ROUTE_MONEY_TOLERANCE_PESEWAS, (int) round(abs($theirs) * 0.0001))) {
                $diffs[$key] = [$block[$key], $theirs];
            }
        }

        $block['totals'] = $reported;
        $block['diffs'] = $diffs;
        $block['failed'] = $diffs !== [];
    }

    // ---------------------------------------------------------------- one chunk of customers

    /** Looks the chunk's accounts up in the staging table, stages the new ones, then adds every row (a second occurrence is a duplicate). */
    protected function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $accounts = array_values(array_unique(array_map(fn (array $r) => $r['v']['account'], $this->buffer)));
        $existing = [];

        foreach (array_chunk($accounts, 500) as $chunk) {
            foreach (DB::table('commercial_billing_detail_accounts')->where('batch_id', $this->batchId)->whereIn('account_no', $chunk)->pluck('account_no') as $account) {
                $existing[$account] = true;
            }
        }

        $stage = [];
        $here = [];

        foreach ($this->buffer as $r) {
            $account = $r['v']['account'];
            $duplicate = isset($existing[$account]) || isset($here[$account]);

            if (! $duplicate) {
                $here[$account] = true;
                $stage[] = ['batch_id' => $this->batchId, 'account_no' => $account, 'route_seq' => $r['seq']];
            }

            $this->add($r['seq'], $r['v'], $duplicate);
        }

        foreach (array_chunk($stage, 500) as $chunk) {
            DB::table('commercial_billing_detail_accounts')->insert($chunk);
        }

        $this->buffer = [];
    }

    /** @param  array<string, mixed>  $v the mapped cells of one customer */
    protected function add(int $seq, array $v, bool $duplicate): void
    {
        $this->rowsKept++;

        $account = $v['account'];
        $dKey = $this->dKeys[$seq];
        $category = $this->category($v['category'] ?? null);
        $status = $this->code($v['status'] ?? null);
        $meter = $this->code($v['meter_status'] ?? null);
        $bill = $this->code($v['bill_status'] ?? null);

        $consume = BillingDetailsParser::number($v['consume'] ?? null) ?? 0.0;
        $consumeK = (int) round($consume * 1000);
        $water = $this->pesewas($v['water'] ?? null);
        $rural = $this->pesewas($v['rural'] ?? null);
        $fire = $this->pesewas($v['fire'] ?? null);
        $sewage = $this->pesewas($v['sewage'] ?? null);
        $service = $this->pesewas($v['service'] ?? null);
        $monthTotal = $this->pesewas($v['month_total'] ?? null);
        $previous = $this->pesewas($v['previous_balance'] ?? null);
        $paid = $this->pesewas($v['paid'] ?? null);
        $prevPay = $this->pesewas($v['prev_payment'] ?? null);
        $offset = $this->pesewas($v['offset'] ?? null);
        $adjusted = $this->pesewas($v['adjusted'] ?? null);
        $balance = $this->pesewas($v['balance_due'] ?? null);

        $billed = in_array($bill, self::BILLED, true);

        // ---- the route's own totals row is compared with what its rows add up to (duplicates included: the totals row counts them)
        $block = &$this->blocks[$seq];
        $block['count']++;
        $block['month_total'] += $monthTotal;
        $block['paid'] += $paid;
        $block['balance_due'] += $balance;
        unset($block);

        // ---- the rollup
        $key = $this->labels[$seq]."\x1f".$category."\x1f".$status."\x1f".$meter."\x1f".$bill;
        $a = &$this->rollups[$key];
        $a ??= array_fill(0, count(self::COLUMNS), 0);

        $a[self::C_CUSTOMERS]++;
        $a[self::C_CONSUME] += $consumeK;
        $a[self::C_WATER] += $water;
        $a[self::C_RURAL] += $rural;
        $a[self::C_FIRE] += $fire;
        $a[self::C_SEWAGE] += $sewage;
        $a[self::C_SERVICE] += $service;
        $a[self::C_MONTH_TOTAL] += $monthTotal;
        $a[self::C_PREVIOUS] += $previous;
        $a[self::C_PAID] += $paid;
        $a[self::C_PREV_PAY] += $prevPay;
        $a[self::C_OFFSET] += $offset;
        $a[self::C_ADJUSTED] += $adjusted;
        $a[self::C_BALANCE] += $balance;

        $zero = $consumeK === 0;

        if ($zero) {
            $a[self::C_ZERO]++;
        }

        if ($consumeK < 0) {
            $a[self::C_NEGATIVE]++;
            $this->offer(CommercialBillingDetailIssue::NEGATIVE_CONSUME, $dKey, $account, $seq, (int) round($consumeK / 10));   // the list stores hundredths: the consumption in units to two places
        }

        if ($monthTotal > 0 && $paid === 0) {
            $a[self::C_BILLED_UNPAID]++;
            $a[self::C_BILLED_UNPAID_AMT] += $monthTotal;
            $this->offer(CommercialBillingDetailIssue::BILLED_UNPAID, $dKey, $account, $seq, $monthTotal);
        }

        if ($balance > 0) {
            $a[self::C_OWING]++;
            $a[self::C_OWING_AMT] += $balance;
            $this->owing[$dKey."\x1f".$category][] = $balance;
            $this->offer(CommercialBillingDetailIssue::TOP_DEBTOR, $dKey, $account, $seq, $balance);
        } elseif ($balance < 0) {
            $a[self::C_CREDIT]++;
            $a[self::C_CREDIT_AMT] += -$balance;
        }

        if ($adjusted !== 0) {
            $a[self::C_ADJ_COUNT]++;
            $this->offer(CommercialBillingDetailIssue::LARGE_ADJUSTMENT, $dKey, $account, $seq, $adjusted);
        }

        // the billing system's own arithmetic: rate x consume against the water charge (it does not test the tariff itself)
        $rate = BillingDetailsParser::number($v['rate'] ?? null);

        if ($rate !== null && $rate > 0 && $consume > 0) {
            $a[self::C_TARIFF_CHECKED]++;
            $charged = $water / 100;
            $expected = round($rate * $consume, 2);
            $difference = abs($charged - $expected);
            $base = abs($charged) > 0 ? abs($charged) : abs($expected);

            if ($difference >= $this->tolAmount - 1e-9 && $difference > $base * $this->tolPct / 100) {
                $a[self::C_TARIFF_MISMATCH]++;
                $this->offer(CommercialBillingDetailIssue::TARIFF_MISMATCH, $dKey, $account, $seq, (int) round(($charged - $expected) * 100));
            }
        }

        // the month total is the five charges added, within the rounding of five cells
        if (abs(($water + $rural + $fire + $sewage + $service) - $monthTotal) > self::COMPONENT_TOLERANCE_PESEWAS) {
            $a[self::C_COMPONENTS]++;
        }

        // days from the last read to the END of the bill period (2000-01-01 and a blank mean "never")
        $lastRead = BillingDetailsParser::date($v['last_read'] ?? null);
        $days = null;

        if ($lastRead === null) {
            $a[self::C_READ_NEVER]++;
        } else {
            $days = max(0, $this->periodToDay - intdiv((int) strtotime($lastRead.' 00:00:00 UTC'), 86400));
            $a[match (true) {
                $days <= 30 => self::C_READ_0_30,
                $days <= 60 => self::C_READ_31_60,
                $days <= 90 => self::C_READ_61_90,
                default => self::C_READ_OVER_90,
            }]++;
        }

        // the Bill Month of the row against the period of the file
        $month = BillingDetailsParser::billMonth($v['bill_month'] ?? null);

        if ($month !== null && ($month[1] < $this->periodFrom || $month[0] > $this->periodTo)) {
            $a[self::C_OUTSIDE]++;
        }

        unset($a);

        // ---- the exception lists that depend on what the account is
        $flags = $this->statusFlags[$status] ?? null;

        if ($bill === 'UNBILLED' && $flags !== null && $flags['billing']) {
            $this->offer(CommercialBillingDetailIssue::ACTIVE_UNBILLED, $dKey, $account, $seq, $balance);
        }

        if ($billed && $flags !== null && ! $flags['active']) {
            $this->offer(CommercialBillingDetailIssue::BILLED_NOT_ACTIVE, $dKey, $account, $seq, $monthTotal);
        }

        if ($bill === 'REGULAR' && $zero) {
            $this->offer(CommercialBillingDetailIssue::ZERO_CONSUME_REGULAR, $dKey, $account, $seq, $monthTotal);
        }

        if ($bill === 'REGULAR' && ($lastRead === null || $days > 90)) {
            $this->offer(CommercialBillingDetailIssue::STALE_READ, $dKey, $account, $seq, ($lastRead === null ? CommercialBillingDetailIssue::NEVER_READ_DAYS : $days) * 100);
        }

        if ($duplicate) {
            $this->duplicates++;
            $this->offer(CommercialBillingDetailIssue::DUPLICATE_ACCOUNT, $dKey, $account, $seq, $monthTotal);
        }

        // ---- the consumption bands: billed customers, not a negative consumption
        if ($billed && $consumeK >= 0) {
            $band = CommercialBillingDetailBand::bandOf($consumeK / 1000);
            $b = &$this->bands[$dKey."\x1f".$category][$band];
            $b ??= [0, 0];
            $b[0]++;
            $b[1] += $consumeK;
            unset($b);
        }

        // ---- what the preview says about the file
        $this->counts['bill'][$bill] = ($this->counts['bill'][$bill] ?? 0) + 1;
        $this->counts['status'][$status] = ($this->counts['status'][$status] ?? 0) + 1;
        $this->counts['meter'][$meter] = ($this->counts['meter'][$meter] ?? 0) + 1;
        $this->counts['category'][$category] = ($this->counts['category'][$category] ?? 0) + 1;
    }

    // ---------------------------------------------------------------- the capped lists

    /** Offers an account for an exception list. Each list keeps the cap's worth of LARGEST figures (largest absolute value first; ties by account). */
    protected function offer(string $kind, string $dKey, string $account, int $seq, int $amount): void
    {
        $list = &$this->issues[$kind][$dKey];
        $list[] = [abs($amount), $account, $seq, $amount];

        if (count($list) >= $this->cap * 2) {
            $this->trim($list);
        }
    }

    /** @param  list<array{0: int, 1: string, 2: int, 3: int}>  $list */
    protected function trim(array &$list): void
    {
        usort($list, fn (array $x, array $y) => [$y[0], $x[1]] <=> [$x[0], $y[1]]);
        $list = array_slice($list, 0, $this->cap);
    }

    // ---------------------------------------------------------------- the result

    /** @return array<string, mixed> */
    protected function result(): array
    {
        foreach ($this->issues as $kind => &$byDistrict) {
            foreach ($byDistrict as &$list) {
                $this->trim($list);
            }

            unset($list);
        }

        unset($byDistrict);

        $routes = [];
        $grand = ['rows' => ['count' => 0, 'month_total' => 0, 'paid' => 0, 'balance_due' => 0], 'reported' => ['count' => 0, 'month_total' => 0, 'paid' => 0, 'balance_due' => 0], 'checked_routes' => 0, 'failed_routes' => 0];

        foreach ($this->labels as $seq => $label) {
            $b = $this->blocks[$seq];
            $routes[$seq] = [
                'seq' => $seq, 'label' => $label, 'route_id' => $this->routeIds[$seq], 'code' => $this->placed[$seq]['code'], 'district_label' => $this->placed[$seq]['district_label'], 'district_id' => $this->placed[$seq]['district_id'],
                'skipped' => $this->skip[$seq], 'skipped_rows' => $b['skipped_rows'], 'customers' => $b['count'], 'month_total' => $b['month_total'], 'paid' => $b['paid'], 'balance_due' => $b['balance_due'],
                'totals' => $b['totals'], 'diffs' => $b['diffs'], 'failed' => $b['failed'],
            ];

            if ($this->skip[$seq] || $b['totals'] === null) {
                continue;
            }

            $grand['checked_routes']++;
            $grand['failed_routes'] += $b['failed'] ? 1 : 0;

            foreach (['count', 'month_total', 'paid', 'balance_due'] as $measure) {
                $grand['rows'][$measure] += $b[$measure];
                $grand['reported'][$measure] += $b['totals'][$measure] ?? $b[$measure];
            }
        }

        return [
            'filters' => $this->filters,
            'missing_columns' => $this->missingColumns,
            'rows_seen' => $this->rowsSeen,
            'rows' => $this->rowsKept,
            'skipped_rows' => $this->skippedRows,
            'skipped_routes' => count(array_filter($this->skip)),
            'malformed' => $this->malformed,
            'duplicates' => $this->duplicates,
            'orphan_rows' => $this->orphanRows,
            'orphan_totals' => $this->orphanTotals,
            'routes' => $routes,
            'grand' => $grand,
            'rollups' => $this->rollups,
            'bands' => $this->bands,
            'issues' => $this->issues,
            'counts' => $this->counts,
            'concentration' => $this->concentration(),
            'unknown_categories' => array_values(array_diff(array_keys($this->counts['category']), array_keys($this->knownCategories), [''])),
            'unknown_statuses' => array_values(array_diff(array_keys($this->counts['status']), array_keys($this->statusFlags), [''])),
            'district_keys' => array_values(array_unique(array_filter($this->dKeys, fn ($key, $seq) => ! $this->skip[$seq], ARRAY_FILTER_USE_BOTH))),
        ];
    }

    /**
     * B7: how much of the owed balance the top 10 percent of owing accounts hold, for the whole file, per category, per district and per
     * (district, category). "Top 10 percent" is by NUMBER of owing accounts, rounded up (at least one), taking the largest balances.
     *
     * @return list<array{district: string, category: string, owing: int, amount: int, top_n: int, top_amount: int}>
     */
    protected function concentration(): array
    {
        $merged = [];   // every grain's list ('*' = all), built from the (district, category) lists

        foreach ($this->owing as $key => $balances) {
            [$district, $category] = explode("\x1f", $key, 2);

            foreach ([[$district, $category], [$district, '*'], ['*', $category], ['*', '*']] as [$d, $c]) {
                $merged[$d."\x1f".$c] = array_merge($merged[$d."\x1f".$c] ?? [], $balances);
            }
        }

        $out = [];

        foreach ($merged as $key => $balances) {
            [$district, $category] = explode("\x1f", $key, 2);
            rsort($balances);
            $n = count($balances);
            $top = max(1, (int) ceil($n / 10));
            $out[] = ['district' => $district, 'category' => $category, 'owing' => $n, 'amount' => array_sum($balances), 'top_n' => $top, 'top_amount' => array_sum(array_slice($balances, 0, $top))];
        }

        return $out;
    }

    // ---------------------------------------------------------------- cell helpers

    /** Whole pesewas from a money cell (blank = 0). */
    protected function pesewas(mixed $cell): int
    {
        $number = is_float($cell) || is_int($cell) ? (float) $cell : BillingDetailsParser::number($cell);

        return $number === null ? 0 : (int) round($number * 100);
    }

    /** An account status / meter status / bill status as printed, upper case, at most 12 characters ('' when blank). */
    protected function code(mixed $cell): string
    {
        return is_scalar($cell) ? mb_substr(mb_strtoupper(trim((string) $cell)), 0, 12) : '';
    }

    /** A service category as its code ("611", or "611 - DOMESTIC METERED" read as 611). */
    protected function category(mixed $cell): string
    {
        if (is_int($cell) || is_float($cell)) {
            return (string) (int) $cell;
        }

        if (! is_string($cell)) {
            return '';
        }

        $text = trim($cell);

        return preg_match('/^(\d{1,6})\b/', $text, $m) ? $m[1] : mb_substr(mb_strtoupper($text), 0, 12);
    }
}
