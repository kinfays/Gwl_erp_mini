<?php

namespace App\Services\Finance;

use App\Models\FinPaymentVoucher as Pv;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns the payment-voucher data into plain, titled tables. One report array feeds the Excel workbook AND the PDF (the Commercial
 * export classes, which take exactly this shape), so they cannot disagree with each other; the summary comes from PvAnalyticsService
 * (the only evaluator) and the lists are read through FinanceScope (as Finance, or as Audit, which never sees a draft), so a file
 * can only hold what the screen it comes from holds. ONE location per file, authorised by the caller.
 *
 * A report is: key, title, file (location and period, for the file name), meta (label => value), notes, tables (title, headings,
 * rows) and row_count. Cells are plain numbers or strings, never null (text from users stays text in the sheet); money is GH¢ with
 * two decimals. When a register would be over `$cap` rows nothing is built: the row count is returned so the caller can refuse.
 */
class FinanceExportService
{
    /** What each export is. A file needs {module}.export AND {module}.view_reports (the controller checks both). */
    public const REPORTS = [
        'register' => ['title' => 'Payment voucher register', 'formats' => ['excel', 'pdf']],
        'summary' => ['title' => 'Payment voucher summary', 'formats' => ['excel', 'pdf']],
        'waiting' => ['title' => 'Vouchers waiting', 'formats' => ['excel', 'pdf']],
    ];

    public function __construct(
        protected FinanceScope $scope,
        protected PvAnalyticsService $analytics,
        protected PvBacklog $backlog,
        protected PvCatalogService $catalogue,
    ) {}

    /**
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return array<string, mixed>
     */
    public function build(string $key, User $actor, string $as, array $location, CarbonInterface|string $from, CarbonInterface|string $to, string $locationLabel, ?int $cap = null): array
    {
        abort_unless(isset(self::REPORTS[$key]), 404);

        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $meta = ['Location' => $locationLabel, 'Period' => $from->format('d M Y').' to '.$to->format('d M Y'), 'Seen as' => $as === FinanceScope::AS_AUDIT ? 'Audit' : 'Finance'];

        return match ($key) {
            'register' => $this->register($actor, $as, $location, $from, $to, $meta, $cap),
            'summary' => $this->summary($actor, $as, $location, $from, $to, $meta),
            'waiting' => $this->waiting($actor, $as, $location, $from, $to, $meta),
        };
    }

    // ------------------------------------------------------------------ the reports

    protected function register(User $actor, string $as, array $location, Carbon $from, Carbon $to, array $meta, ?int $cap): array
    {
        $query = $this->scope->atLocation($this->scope->pvs($actor, $as), $location)
            ->whereDate('fin_pvs.pv_date', '>=', $from->toDateString())->whereDate('fin_pvs.pv_date', '<=', $to->toDateString());

        $count = (clone $query)->count();

        if ($cap !== null && $count > $cap) {
            return $this->report('register', $meta, [], [], $from, $to, $count);
        }

        $vouchers = $query->with(['creator', 'events.actor'])
            ->orderByRaw('DATE(fin_pvs.pv_date) asc')->orderBy('fin_pvs.id')->get();
        $this->catalogue->labelPvs($vouchers);

        $rows = $vouchers
            ->map(function (Pv $pv) {
                $marks = $pv->marks();

                return [
                    $pv->pv_date->format('d M Y'), $pv->pv_no, $pv->payee_name, $pv->payeeKindLabel(), $pv->category_label, $pv->type_label, $pv->details,
                    $this->money($pv->amount), $pv->statusLabel(), $pv->round, $marks['audit']['word'], $marks['finance']['word'], (string) $pv->creator?->full_name,
                    $pv->submitted_at?->format('d M Y') ?? '', $pv->completed_at?->format('d M Y') ?? '', $pv->isVoided() ? 'Voided: '.$pv->void_reason : '',
                ];
            })->all();

        $notes = ['Dates are the PV date. A voided voucher is listed and marked; it is not counted as spend anywhere else.'];

        if ($as === FinanceScope::AS_AUDIT) {
            $notes[] = 'Audit sees a voucher only once Finance has sent it to Audit: drafts are not listed.';
        }

        return $this->report('register', $meta, $notes, [[
            'title' => 'Vouchers',
            'headings' => ['PV date', 'PV number', 'Payee', 'Payee type', 'Category', 'Type', 'Details', 'Amount (GH¢)', 'Status', 'Round', 'Audit', 'Finance', 'Prepared by', 'Sent to Audit', 'Completed', 'Note'],
            'rows' => $rows,
        ]], $from, $to);
    }

    protected function summary(User $actor, string $as, array $location, Carbon $from, Carbon $to, array $meta): array
    {
        $r = $this->analytics->report($actor, $as, $location, $from, $to);
        $t = $r['tiles'];
        $a = $r['audit'];
        $pct = fn (?float $value) => $value === null ? 'n/a' : number_format($value, 1).'%';
        $days = fn (array $sample) => $sample['days'] === null ? 'n/a' : number_format($sample['days'], 1).' days (from '.$sample['count'].')';

        $headline = [
            ['Vouchers that went ahead', $t['vouchers']],
            ['Total amount (GH¢)', $this->money($t['amount'])],
            ['Average voucher (GH¢)', $t['average_amount'] === null ? 'n/a' : $this->money($t['average_amount'])],
            ['Completed vouchers', $t['completed_vouchers']],
            ['Completed amount (GH¢)', $this->money($t['completed_amount'])],
            ['Still open', $t['open_vouchers']],
            ['Still open amount (GH¢)', $this->money($t['open_amount'])],
            ['Voided vouchers', $t['voided']],
            ...($as === FinanceScope::AS_FINANCE ? [['Drafts (not sent to Audit)', $t['drafts']]] : []),
            ['Vouchers Audit queried', $a['queried_vouchers']],
            ['Share of vouchers queried', $pct($a['queried_rate'])],
            ['Average rounds per voucher', $a['average_rounds'] === null ? 'n/a' : number_format($a['average_rounds'], 2)],
            ['Vouchers sent back more than once', $a['multi_round_vouchers']],
        ];

        $turnaround = [
            ['Audit: sent to tick or query', $days($a['turnaround']['audit_response'])],
            ['Finance: query to resubmission', $days($a['turnaround']['finance_response'])],
            ['Finance: Audit tick to acknowledgement', $days($a['turnaround']['acknowledge'])],
            ['First sent to Finance acknowledged', $days($a['turnaround']['end_to_end'])],
        ];

        $waiting = collect($r['waiting']['kinds'])->map(fn (array $k) => [$k['label'], $k['count'], $k['overdue'], $k['oldest_days'] ?? '', $k['threshold'] ?? ''])->values()->all();
        $spend = fn (array $rows) => array_map(fn (array $row) => [$row['name'] ?? $row['label'], $row['count'], $this->money($row['amount'])], $rows);

        return $this->report('summary', $meta, [
            'Spend is the amount of vouchers that went ahead (not a draft, not voided), by the PV date.',
            'Turnaround times are averages over every such step in the period.',
            'Waiting right now is as at today whatever the period; "overdue" uses the location\'s own reminder settings.',
        ], [
            ['title' => 'Headline', 'headings' => ['Measure', 'Value'], 'rows' => $headline],
            ['title' => 'Spend by category', 'headings' => ['Category', 'Vouchers', 'Amount (GH¢)'], 'rows' => $spend($r['categories'])],
            ['title' => 'Spend by type', 'headings' => ['Type', 'Vouchers', 'Amount (GH¢)'], 'rows' => $spend($r['types'])],
            ['title' => 'Spend by payee type', 'headings' => ['Payee type', 'Vouchers', 'Amount (GH¢)'], 'rows' => $spend($r['payee_kinds'])],
            ['title' => 'By month', 'headings' => ['Month', 'Vouchers', 'Amount (GH¢)'], 'rows' => array_map(fn (array $row) => [$row['label'], $row['count'], $this->money($row['amount'])], $r['months']['rows'])],
            ['title' => 'Top suppliers', 'headings' => ['Supplier', 'Vouchers', 'Amount (GH¢)'], 'rows' => $spend($r['suppliers'])],
            ['title' => 'Why Audit queried', 'headings' => ['Reason', 'Queries'], 'rows' => array_map(fn (array $row) => [$row['label'], $row['count']], $r['reasons'])],
            ['title' => 'Turnaround', 'headings' => ['Step', 'Average'], 'rows' => $turnaround],
            ['title' => 'Waiting right now', 'headings' => ['Waiting for', 'Vouchers', 'Overdue', 'Longest wait (days)', 'Remind after (days)'], 'rows' => $waiting],
        ], $from, $to);
    }

    protected function waiting(User $actor, string $as, array $location, Carbon $from, Carbon $to, array $meta): array
    {
        $items = $this->backlog->items($this->scope->atLocation($this->scope->pvs($actor, $as), $location));
        $rows = $items->map(fn (array $item) => [
            $item['pv']->pv_no, $item['pv']->payee_name, $this->money($item['pv']->amount), PvBacklog::kindLabel($item['kind']),
            $item['since']->format('d M Y'), $item['days'], $item['threshold'], $item['overdue'] ? 'Yes' : 'No',
        ])->all();

        return $this->report('waiting', ['Location' => $meta['Location'], 'As at' => now()->format('d M Y'), 'Seen as' => $meta['Seen as']], [
            'Every voucher waiting right now, the longest wait first, whatever its PV date.',
            'Overdue means the wait has reached the days the location set on its Finance settings.',
        ], [[
            'title' => 'Waiting',
            'headings' => ['PV number', 'Payee', 'Amount (GH¢)', 'Waiting for', 'Since', 'Days waiting', 'Remind after (days)', 'Overdue'],
            'rows' => $rows,
        ]], $to->copy()->startOfMonth(), $to);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    protected function report(string $key, array $meta, array $notes, array $tables, Carbon $from, Carbon $to, ?int $rowCount = null): array
    {
        return [
            'key' => $key,
            'file' => ['region' => (string) $meta['Location'], 'period' => $from->format('Ymd').'-'.$to->format('Ymd')],
            'title' => self::REPORTS[$key]['title'],
            'meta' => $meta,
            'notes' => $notes,
            'tables' => $tables,
            'row_count' => $rowCount ?? array_sum(array_map(fn (array $table) => count($table['rows']), $tables)),
        ];
    }

    /** Pesewas to a cedis figure with two decimals, so the PDF and Excel show it the same way. */
    protected function money(int $pesewas): float
    {
        return round($pesewas / 100, 2);
    }
}
