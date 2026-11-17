<?php

namespace App\Services\Commercial;

use App\Models\CommercialBillingDetailDomesticBand;
use App\Models\CommercialImportBatch;
use App\Models\CommercialRoute;
use App\Models\District;
use App\Models\Permission;
use App\Services\Commercial\BillingDetails\BillingDetailsScan;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 8h (design 8.52): the Billing Summary Report built from the Detailed Billing Report, so a region that uploads the detailed report no longer
 * needs to upload the summary. When a billing_details batch is LIVE and not superseded, this class writes (or refreshes) ONE billing_summary batch
 * with `source = derived` and `derived_from_batch_id` = the detailed batch, from the stored rollups (commercial_billing_detail_rollups and the
 * domestic band table): the workbook is never read again and no customer is involved.
 *
 * The derived batch is a normal billing_summary batch for every reader (periods, scope, KPIs, Summary, Home, trends, exports, audit). It FOLLOWS its
 * source: reconcile() removes it when the source is voided, superseded or gone, builds one for a source that has none, and a re-match of a parked
 * route or `commercial:billing-summary:derive` refreshes it. Only one place decides which of an uploaded and a derived summary counts:
 * CommercialPeriods::select() (preference commercial_billing_summary_preference).
 *
 * The column mapping (summary column <= detailed source) and the status codes per column are in the design doc, 8.52.2. In short, per CANONICAL
 * route (district from the route master): volumes = Consume of REGULAR / ESTIMATED+ESTIMATE bills x commercial_details_consume_factor; opening =
 * previous balance; billing = month total; adjustment = adjusted; receivable = opening + billing + adjustment (recomputed); payments = paid this
 * month + previous-month payment + offset; closing = balance due; the closing = receivable - payments identity is checked PER ROUTE and a route that
 * breaks it is FLAGGED in the control totals, never forced to balance.
 */
class BillingSummaryFromDetails
{
    /** Account statuses that put an UNBILLED account in the "Suspense" columns. */
    public const SUSPENSE = ['SUSP'];

    /** Account statuses that put an UNBILLED account in the "Disconnected" columns (the application's own list: DISC, DISO and DISN). */
    public const DISCONNECTED = ['DISC', 'DISO', 'DISN'];

    /** Meter statuses of a METERED account (working or faulty); anything else (N, blank, an unknown code) is unmetered. */
    public const METERED = ['W', 'F'];

    protected const FLAG_LIST_CAP = 500;

    public function __construct(protected RouteResolver $routes) {}

    // ---------------------------------------------------------------- who is a source

    /** A detailed batch a summary can be derived from: live and not superseded by a newer upload that contains it. */
    public function isSource(CommercialImportBatch $batch): bool
    {
        return $batch->isBillingDetails() && $batch->status === CommercialImportBatch::STATUS_IMPORTED;
    }

    public function derivedFor(CommercialImportBatch $source): ?CommercialImportBatch
    {
        return CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_SUMMARY)->where('source', CommercialImportBatch::SOURCE_DERIVED)
            ->where('derived_from_batch_id', $source->id)->orderBy('id')->first();
    }

    // ---------------------------------------------------------------- which one counts

    /**
     * Why a billing summary is NOT counted in the analyses, or null when it is: an uploaded summary and a derived one for the same region, customer
     * segment and a period one of them covers are never counted together, and commercial_billing_summary_preference says which stays
     * (CommercialPeriods::select() decides; this only names it for the Uploads page and the batch page).
     */
    public function notUsedReason(CommercialImportBatch $batch): ?string
    {
        if (! $batch->isBilling() || $batch->isVoided()) {
            return null;
        }

        $units = CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_SUMMARY)->notVoided()->where('region_id', $batch->region_id)
            ->where('customer_segment', $batch->customer_segment)->get()->map(fn (CommercialImportBatch $b) => CommercialPeriods::unitOf($b))->all();

        foreach ((new CommercialPeriods)->select($units)['shadowed'] as $shadow) {
            if ($shadow['id'] === (int) $batch->id && $shadow['reason'] === 'source') {
                return $batch->isDerived()
                    ? 'not used: an uploaded summary covers this period (batch #'.$shadow['by'].')'
                    : 'not used: the detailed report covers this period (batch #'.$shadow['by'].')';
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- following the source

    /**
     * Makes the derived summaries of the region (or of every region) agree with their sources: one that has lost its source (voided, superseded,
     * discarded) is removed, a live source without one gets one. Safe to run again. A failure is logged (ids only) and never stops the caller.
     *
     * @return array{built: int, removed: int, failed: int}
     */
    public function reconcile(?int $regionId = null): array
    {
        $out = ['built' => 0, 'removed' => 0, 'failed' => 0];

        try {
            $derived = CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_SUMMARY)->where('source', CommercialImportBatch::SOURCE_DERIVED)
                ->when($regionId !== null, fn ($q) => $q->where('region_id', $regionId))->get();

            foreach ($derived as $batch) {
                $source = $batch->derived_from_batch_id ? CommercialImportBatch::query()->find($batch->derived_from_batch_id) : null;

                if ($source === null || ! $this->isSource($source)) {
                    $this->remove($batch, $source === null ? 'source_gone' : 'source_'.$source->status);
                    $out['removed']++;
                }
            }

            $sources = CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_DETAILS)->where('status', CommercialImportBatch::STATUS_IMPORTED)
                ->when($regionId !== null, fn ($q) => $q->where('region_id', $regionId))->get();

            foreach ($sources as $source) {
                if ($this->derivedFor($source) === null) {
                    $this->build($source);
                    $out['built']++;
                }
            }
        } catch (Throwable $e) {
            $out['failed']++;
            Log::warning('Billing summary derivation failed', ['region_id' => $regionId, 'exception' => $e::class]);
        }

        return $out;
    }

    /** Removes a derived batch with its routes and bands (they cascade); one audit row of ids. */
    public function remove(CommercialImportBatch $derived, string $why): void
    {
        if (! $derived->isDerived()) {
            return;
        }

        DB::transaction(function () use ($derived, $why): void {
            Audit::log(
                action: 'commercial.billing_summary_derived_removed',
                module: Permission::MODULE_COMMERCIAL,
                targetType: CommercialImportBatch::class,
                targetId: $derived->id,
                metadata: ['source_batch_id' => $derived->derived_from_batch_id, 'region_id' => $derived->region_id, 'why' => $why, 'routes' => $derived->routes()->count()]
            );

            $region = $derived->region_id;
            $derived->delete();
            app(BatchLifecycleService::class)->refreshStatuses($region, CommercialImportBatch::TYPE_BILLING_SUMMARY);
        });
    }

    // ---------------------------------------------------------------- the derivation

    /**
     * Builds, or rebuilds in place (the id stays), the derived summary of a detailed batch. A batch that is not a live source has none: its derived
     * summary, if any, is removed and null is returned.
     */
    public function build(CommercialImportBatch $source): ?CommercialImportBatch
    {
        if (! $this->isSource($source)) {
            if ($existing = $source->isBillingDetails() ? $this->derivedFor($source) : null) {
                $this->remove($existing, 'source_'.$source->status);
            }

            return null;
        }

        return DB::transaction(function () use ($source): CommercialImportBatch {
            $calc = $this->compute($source);
            $from = $source->period_from;
            $to = $source->period_to;

            $attributes = [
                'report_type' => CommercialImportBatch::TYPE_BILLING_SUMMARY, 'source' => CommercialImportBatch::SOURCE_DERIVED, 'derived_from_batch_id' => $source->id,
                'region_id' => $source->region_id, 'region_label_raw' => $source->region_label_raw, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(),
                'granularity' => CommercialPeriods::label($from, $to) === CommercialPeriods::MONTH ? CommercialImportBatch::GRANULARITY_MONTHLY : CommercialImportBatch::GRANULARITY_MULTI_MONTH,
                'billing_status_raw' => null, 'customer_segment' => CommercialImportBatch::SEGMENT_ALL,
                'source_filename' => 'Derived from the detailed billing report (batch '.$source->id.')', 'file_path' => null,
                'file_hash' => hash('sha256', 'derived:'.$source->id), 'status' => CommercialImportBatch::STATUS_IMPORTED,
                'row_count' => count($calc['routes']), 'matched_count' => count($calc['routes']), 'warning_count' => count($calc['identity_failures']) + ($calc['parked_routes'] > 0 ? 1 : 0),
                'control_totals' => $calc['control'], 'reconciliation_passed' => $calc['identity_failures'] === [],
                'imported_by' => $source->imported_by, 'imported_at' => $source->imported_at ?? now(),   // the time of its source: lateness and reminders are the detailed report's
                 'notes' => 'Derived from the detailed billing report (batch '.$source->id.').',
            ];

            $derived = $this->derivedFor($source);

            if ($derived) {
                $derived->routes()->delete();
                $derived->bands()->delete();
                $derived->forceFill($attributes)->save();
            } else {
                $derived = CommercialImportBatch::query()->forceCreate($attributes + ['created_at' => now(), 'updated_at' => now()]);
            }

            foreach (array_chunk($calc['routes'], 100) as $chunk) {
                $derived->routes()->createMany($chunk);
            }

            foreach ($calc['bands'] as $band) {
                $derived->bands()->create($band);
            }

            // a LABEL only (imported / superseded) for this region's billing summaries, as after any import
            app(BatchLifecycleService::class)->refreshStatuses($derived->region_id, CommercialImportBatch::TYPE_BILLING_SUMMARY, $derived->id);

            Audit::log(
                action: 'commercial.billing_summary_derived',
                module: Permission::MODULE_COMMERCIAL,
                targetType: CommercialImportBatch::class,
                targetId: $derived->id,
                metadata: [
                    'source_batch_id' => $source->id, 'region_id' => $source->region_id, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(),
                    'routes' => count($calc['routes']), 'parked_routes' => $calc['parked_routes'], 'identity_flagged' => count($calc['identity_failures']), 'band_rows' => count($calc['bands']),
                ]
            );

            return $derived->refresh();
        });
    }

    /** Rebuilds the derived summary of the detailed batch behind an id (a detailed batch or its derived one). Null when the id is neither. */
    public function refreshBatch(int $batchId): ?CommercialImportBatch
    {
        $batch = CommercialImportBatch::query()->find($batchId);

        if ($batch === null) {
            return null;
        }

        if ($batch->isDerived()) {
            $batch = $batch->derivedFrom;
        }

        return $batch && $batch->isBillingDetails() ? $this->build($batch) : null;
    }

    /**
     * Rebuilds every derived summary and reconciles the rest. Reports counts only.
     *
     * @return array{rebuilt: int, removed: int, built: int}
     */
    public function refreshAll(): array
    {
        $rebuilt = 0;

        foreach (CommercialImportBatch::query()->ofType(CommercialImportBatch::TYPE_BILLING_DETAILS)->where('status', CommercialImportBatch::STATUS_IMPORTED)->orderBy('id')->get() as $source) {
            $this->build($source);
            $rebuilt++;
        }

        $rest = $this->reconcile();

        return ['rebuilt' => $rebuilt, 'removed' => $rest['removed'], 'built' => $rest['built']];
    }

    // ---------------------------------------------------------------- the maths

    /**
     * Everything the derived batch holds, worked out from the stored rollups. Pure of side effects (it reads only).
     *
     * @return array{routes: list<array<string, mixed>>, bands: list<array<string, mixed>>, identity_failures: list<array<string, mixed>>, parked_routes: int, control: array<string, mixed>}
     */
    public function compute(CommercialImportBatch $source): array
    {
        $factor = (float) config('gwl.commercial_details_consume_factor', 1.0);
        $agg = [];
        $parked = [];
        $unclassified = 0;
        $unknownMeter = 0;

        foreach (DB::table('commercial_billing_detail_rollups')->where('batch_id', $source->id)->orderBy('id')->cursor() as $r) {
            if ($r->route_id === null) {
                $parked[(string) $r->route_label_raw] = true;

                continue;
            }

            $route = $this->canonicalOf((int) $r->route_id);

            if ($route === null || $route->district_id === null) {
                $parked[(string) $r->route_label_raw] = true;

                continue;
            }

            $a = &$agg[$route->id];
            $a ??= ['route' => $route, 'cons_actual' => 0, 'cons_avg' => 0] + array_fill_keys(['previous', 'month_total', 'adjusted', 'paid', 'prev_pay', 'offset', 'balance', 'customers', 'regular', 'est_metered', 'est_unmetered', 'susp_m', 'susp_u', 'disc_m', 'disc_u', 'unb_other'], 0);

            $bill = (string) $r->bill_status;
            $status = (string) $r->customer_status;
            $meter = (string) $r->meter_status;
            $n = (int) $r->customers;
            $metered = in_array($meter, self::METERED, true);

            if (! in_array($meter, [...self::METERED, 'N', ''], true)) {
                $unknownMeter += $n;
            }

            $a['customers'] += $n;
            $a['previous'] += $this->pesewas($r->previous_balance);
            $a['month_total'] += $this->pesewas($r->month_total);
            $a['adjusted'] += $this->pesewas($r->adjusted);
            $a['paid'] += $this->pesewas($r->paid_this_month);
            $a['prev_pay'] += $this->pesewas($r->prev_month_payment);
            $a['offset'] += $this->pesewas($r->offset_payment);
            $a['balance'] += $this->pesewas($r->balance_due);
            $consumeK = (int) round((float) $r->consume * 1000);

            if ($bill === 'REGULAR') {
                $a['regular'] += $n;
                $a['cons_actual'] += $consumeK;
            } elseif (in_array($bill, BillingDetailsScan::ESTIMATED, true)) {
                $a[$metered ? 'est_metered' : 'est_unmetered'] += $n;
                $a['cons_avg'] += $consumeK;
            } elseif ($bill === 'UNBILLED') {
                if (in_array($status, self::SUSPENSE, true)) {
                    $a[$metered ? 'susp_m' : 'susp_u'] += $n;
                } elseif (in_array($status, self::DISCONNECTED, true)) {
                    $a[$metered ? 'disc_m' : 'disc_u'] += $n;
                } else {
                    $a['unb_other'] += $n;
                }
            } else {
                $unclassified += $n;   // neither billed nor unbilled: it is in "number of customers" and in no column beside it
            }

            unset($a);
        }

        $districts = District::query()->whereIn('id', collect($agg)->map(fn (array $a) => $a['route']->district_id)->unique()->all())->pluck('district_name', 'id');
        $money = fn (int $pesewas): string => number_format($pesewas / 100, 2, '.', '');
        $routes = [];
        $failures = [];
        $totals = array_fill_keys(['opening', 'billing', 'adjustment', 'payments', 'closing'], 0);

        foreach ($agg as $a) {
            $route = $a['route'];
            $name = (string) ($districts[$route->district_id] ?? 'District '.$route->district_id);
            $receivable = $a['previous'] + $a['month_total'] + $a['adjusted'];
            $payments = $a['paid'] + $a['prev_pay'] + $a['offset'];
            $expected = $receivable - $payments;
            $difference = $a['balance'] - $expected;
            $tolerance = max(BillingDetailsScan::ROUTE_MONEY_TOLERANCE_PESEWAS, (int) ceil(max(abs($a['balance']), abs($expected)) * 0.0001));

            if (abs($difference) > $tolerance) {
                $failures[] = ['district_id' => (int) $route->district_id, 'district' => $name, 'route' => (string) $route->code, 'closing' => round($a['balance'] / 100, 2), 'receivable_less_payments' => round($expected / 100, 2), 'difference' => round($difference / 100, 2)];
            }

            $billed = $a['regular'] + $a['est_metered'] + $a['est_unmetered'];
            $unbilled = $a['susp_m'] + $a['susp_u'] + $a['disc_m'] + $a['disc_u'] + $a['unb_other'];
            $totals['opening'] += $a['previous'];
            $totals['billing'] += $a['month_total'];
            $totals['adjustment'] += $a['adjusted'];
            $totals['payments'] += $payments;
            $totals['closing'] += $a['balance'];

            $routes[] = [
                'district_id' => (int) $route->district_id, 'district_label_raw' => $name, 'route_code' => (string) $route->code,
                'volume_actual' => round($a['cons_actual'] / 1000 * $factor, 2), 'volume_average' => round($a['cons_avg'] / 1000 * $factor, 2),
                'volume_total' => round(($a['cons_actual'] + $a['cons_avg']) / 1000 * $factor, 2),
                'opening_balance' => $money($a['previous']), 'billing_for_period' => $money($a['month_total']), 'total_receivable' => $money($receivable),
                'revenue_adjustment' => $money($a['adjusted']), 'payment_for_month' => $money($a['paid']), 'prev_month_payment' => $money($a['prev_pay']),
                'offset_payments' => $money($a['offset']), 'total_payments' => $money($payments), 'closing_balance' => $money($a['balance']),
                'customers_count' => $a['customers'],
                'billed_average_metered' => $a['est_metered'], 'billed_average_unmetered' => $a['est_unmetered'], 'billed_actual_reading' => $a['regular'], 'billed_total' => $billed,
                'unbilled_suspense_metered' => $a['susp_m'], 'unbilled_suspense_unmetered' => $a['susp_u'], 'unbilled_disconn_metered' => $a['disc_m'], 'unbilled_disconn_unmetered' => $a['disc_u'],
                'unbilled_other' => $a['unb_other'], 'unbilled_total' => $unbilled,
            ];
        }

        usort($routes, fn (array $x, array $y) => [$x['district_label_raw'], $x['route_code']] <=> [$y['district_label_raw'], $y['route_code']]);

        [$bands, $bandNote] = $this->bands($source, $factor);
        $parkedLabels = array_keys($parked);
        sort($parkedLabels);

        return [
            'routes' => $routes, 'bands' => $bands, 'identity_failures' => array_slice($failures, 0, self::FLAG_LIST_CAP), 'parked_routes' => count($parkedLabels),
            'control' => [
                'derived' => true, 'source_batch_id' => $source->id, 'built_at' => now()->toDateTimeString(), 'consume_factor' => $factor,
                'routes' => count($routes), 'parked_routes' => count($parkedLabels), 'parked_labels' => array_slice($parkedLabels, 0, 50),
                'identity_checked' => count($routes), 'identity_flagged' => count($failures), 'identity_failures' => array_slice($failures, 0, self::FLAG_LIST_CAP),
                'customers_in_neither_column' => $unclassified, 'unknown_meter_status_customers' => $unknownMeter, 'bands' => $bandNote,
                'totals' => array_map(fn (int $p) => round($p / 100, 2), $totals),
            ],
        ];
    }

    /**
     * The category 611 band table, from the detailed batch's own band rows (districts it could place only). A batch loaded before Phase 8h has none,
     * and one counted with another Consume factor than the setting now holds cannot be scaled again: both say so instead of guessing.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    protected function bands(CommercialImportBatch $source, float $factor): array
    {
        $recorded = $source->control_totals['domestic_factor'] ?? null;

        if ($recorded === null) {
            return [[], 'none: this detailed upload was loaded before the band table existed; upload it again'];
        }

        if (abs((float) $recorded - $factor) > 1e-9) {
            return [[], 'none: the bands were counted with a Consume factor of '.$recorded.' and the setting is now '.$factor.'; upload the report again'];
        }

        $rows = DB::table('commercial_billing_detail_domestic_bands')->where('batch_id', $source->id)->whereNotNull('district_id')
            ->selectRaw('band, SUM(customers) as customers, SUM(consume) as volume, SUM(amount) as amount')->groupBy('band')->get()->keyBy('band');

        $out = [];

        foreach ([CommercialBillingDetailDomesticBand::UNDER, CommercialBillingDetailDomesticBand::OVER] as $band) {
            $r = $rows[$band] ?? null;
            $out[] = ['category_code' => CommercialBillingDetailDomesticBand::CATEGORY, 'band' => $band, 'customers' => (int) ($r->customers ?? 0), 'volume' => round((float) ($r->volume ?? 0), 2), 'amount' => round((float) ($r->amount ?? 0), 2)];
        }

        return [$out, 'built'];
    }

    /** @var array<int, CommercialRoute|null> */
    protected array $canonicalCache = [];

    protected function canonicalOf(int $routeId): ?CommercialRoute
    {
        if (! array_key_exists($routeId, $this->canonicalCache)) {
            $route = CommercialRoute::query()->find($routeId);
            $this->canonicalCache[$routeId] = $route ? $this->routes->canonical($route) : null;
        }

        return $this->canonicalCache[$routeId];
    }

    /** Whole pesewas of a decimal(15,2) cedis value. */
    protected function pesewas(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
