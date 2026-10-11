<?php

namespace App\Services\GWater;

use App\Models\GwDamage;
use App\Models\GwExpense;
use App\Models\GwProduct;
use App\Models\GwSupplierPayment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns the G-Water data into plain, titled tables. One report array feeds the Excel workbook AND the PDF (the Commercial
 * export classes, which take exactly this shape), so they cannot disagree with each other; and it is built from the same
 * services and scoped queries as the screens (GwaterProfitService, GwaterStockService, GwaterSummaryService and the books
 * scoped through GwaterVisibility), so it cannot disagree with them either.
 *
 * A report is: key, title, file (location and period, for the file name), meta (label => value), notes, tables (title,
 * headings, rows) and row_count. Cells are plain numbers or strings, never null; money is in GH¢ with two decimals (a float),
 * packs are integers. The location is chosen and authorised by the caller.
 */
class GwaterExportService
{
    /** What each export is. All need gwater.export AND gwater.view_reports (the controller checks both). */
    public const REPORTS = [
        'pnl' => ['title' => 'Profit and loss', 'formats' => ['excel', 'pdf']],
        'stock' => ['title' => 'Stock ledger', 'formats' => ['excel', 'pdf']],
        'damages' => ['title' => 'Damages', 'formats' => ['excel', 'pdf']],
        'expenses' => ['title' => 'Expenses', 'formats' => ['excel', 'pdf']],
        'reimbursements' => ['title' => 'Fuel reimbursements', 'formats' => ['excel', 'pdf']],
        'payments' => ['title' => 'Payments to G Water', 'formats' => ['excel', 'pdf']],
    ];

    public function __construct(
        protected GwaterVisibility $visibility,
        protected GwaterProfitService $profit,
        protected GwaterStockService $stock,
        protected GwaterSummaryService $summary,
    ) {}

    /**
     * @param  array{region_id: int|null, district_id: int|null}  $location
     * @return array<string, mixed>
     */
    public function build(string $key, array $location, CarbonInterface|string $from, CarbonInterface|string $to, User $actor, string $locationLabel): array
    {
        abort_unless(isset(self::REPORTS[$key]), 404);

        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $meta = ['Location' => $locationLabel, 'Period' => $from->format('d M Y').' to '.$to->format('d M Y')];

        return match ($key) {
            'pnl' => $this->pnl($location, $from, $to, $actor, $meta),
            'stock' => $this->stockLedger($location, $from, $to, $meta),
            'damages' => $this->damages($location, $from, $to, $meta),
            'expenses' => $this->expenses($location, $from, $to, $meta),
            'reimbursements' => $this->reimbursements($location, $from, $to, $meta),
            'payments' => $this->payments($location, $from, $to, $meta),
        };
    }

    // ------------------------------------------------------------------ the reports

    protected function pnl(array $location, Carbon $from, Carbon $to, User $actor, array $meta): array
    {
        $report = $this->profit->report($location, $from, $to, $actor);
        $columns = ['Combined' => $report['combined']] + collect($report['products'])->mapWithKeys(fn (array $line) => [$line['name'] => $line])->all();

        $row = fn (string $label, string $key, bool $money = true, bool $combinedOnly = false) => [
            $label,
            ...collect($columns)->map(fn (array $line, string $name) => $combinedOnly && $name !== 'Combined' ? '' : $this->cell($line[$key] ?? null, $money))->values()->all(),
        ];

        $lines = [
            $row('Packs sold', 'packs_sold', false),
            $row('Revenue (GH¢)', 'revenue'),
            $row('Cost of water sold (GH¢)', 'cost_of_sales'),
            $row('Gross profit (GH¢)', 'gross_profit'),
            $row('Damages written off at cost (GH¢)', 'damages'),
            $row('Fuel (GH¢)', 'fuel', true, true),
            $row('Loading (GH¢)', 'loading', true, true),
            $row('Offloading (GH¢)', 'offloading', true, true),
            $row('Other expenses (GH¢)', 'other', true, true),
            $row('Net profit (GH¢)', 'net_profit', true, true),
        ];

        $measures = [
            $row('Gross margin (%)', 'gross_margin_pct', false),
            $row('Net margin (%)', 'net_margin_pct', false, true),
            $row('Packs received', 'received_packs', false),
            $row('Packs damaged', 'damaged_packs', false),
            $row('Damage rate (% of packs received)', 'damage_rate_pct', false),
            $row('Average selling price per pack (GH¢)', 'average_price'),
            $row('Average cost per pack sold (GH¢)', 'average_cost'),
            $row('Break-even packs', 'break_even_packs', false, true),
        ];

        $trips = collect($report['trips'])->map(fn (array $trip) => [
            Carbon::parse($trip['date'])->format('d M Y'), $trip['waybill'], $trip['vehicle'] ?? '', $trip['packs'], $this->cell($trip['purchase_cost']),
            $this->cell($trip['fuel']), $trip['fuel_source'], $this->cell($trip['loading']), $this->cell($trip['offloading']), $this->cell($trip['trip_cost']),
            $this->cell($trip['landed_cost_per_pack']), $this->cell($trip['trip_cost_per_pack']), $trip['km'] ?? '', $this->cell($trip['trip_cost_per_km']),
        ])->all();

        $notes = [...$report['notes'], 'Period status: '.$report['period']['status'].'.'];

        if ($report['period']['in_progress']) {
            $notes[] = 'The period is still running, so these figures are to date.';
        }

        return $this->report('pnl', $meta, $notes, [
            ['title' => 'Profit and loss', 'headings' => ['Line', ...array_keys($columns)], 'rows' => $lines],
            ['title' => 'Measures', 'headings' => ['Measure', ...array_keys($columns)], 'rows' => $measures],
            ['title' => 'Trip costs', 'headings' => ['Date', 'Waybill', 'Vehicle', 'Packs', 'Bought (GH¢)', 'Fuel (GH¢)', 'Fuel from', 'Loading (GH¢)', 'Offloading (GH¢)', 'Trip cost (GH¢)', 'Landed cost per pack (GH¢)', 'Trip cost per pack (GH¢)', 'Km', 'Trip cost per km (GH¢)'], 'rows' => $trips],
            ['title' => 'Owed to G Water', 'headings' => ['Measure', 'GH¢'], 'rows' => [['Owed at the end of the period', $this->cell($report['owed'])]]],
        ], $from, $to);
    }

    protected function stockLedger(array $location, Carbon $from, Carbon $to, array $meta): array
    {
        $tables = [];

        foreach (GwProduct::query()->active()->orderBy('id')->get() as $product) {
            $rows = $this->stock->ledger($location, $product->id, $from, $to)->map(fn (array $line) => [
                $line['movement']->movement_date->format('d M Y'), ucfirst($line['movement']->type), $line['movement']->packs, $line['balance'], (string) $line['movement']->note,
            ])->all();

            $tables[] = ['title' => $product->name, 'headings' => ['Date', 'Type', 'Packs', 'Balance (packs)', 'Note'], 'rows' => $rows];
        }

        return $this->report('stock', $meta, ['The ledger is never edited: a void or a correction is a line of its own. The balance runs on from before the period.'], $tables, $from, $to);
    }

    protected function damages(array $location, Carbon $from, Carbon $to, array $meta): array
    {
        $rows = $this->visibility->atLocation(GwDamage::query(), $location)->with(['product', 'purchase'])
            ->whereDate('damage_date', '>=', $from->toDateString())->whereDate('damage_date', '<=', $to->toDateString())
            ->orderByRaw('DATE(damage_date) asc')->orderBy('id')->get()
            ->map(fn (GwDamage $d) => [
                $d->damage_date->format('d M Y'), $d->product->name, $d->packs, ucfirst($d->stage), $this->cell($d->unit_cost), $this->cell($d->packs * $d->unit_cost),
                (string) ($d->purchase?->waybill_no ?? ''), $d->reason, $d->isVoided() ? 'Voided: '.$d->void_reason : 'Posted',
            ])->all();

        return $this->report('damages', $meta, ['Voided damages are listed and marked; they do not count anywhere else.'],
            [['title' => 'Damages', 'headings' => ['Date', 'Product', 'Packs', 'Stage', 'Cost per pack (GH¢)', 'Written off (GH¢)', 'Waybill', 'Reason', 'Status'], 'rows' => $rows]], $from, $to);
    }

    protected function expenses(array $location, Carbon $from, Carbon $to, array $meta): array
    {
        $rows = $this->visibility->atLocation(GwExpense::query(), $location)->with(['purchase', 'paidBy', 'vehicle'])
            ->whereDate('expense_date', '>=', $from->toDateString())->whereDate('expense_date', '<=', $to->toDateString())
            ->orderByRaw('DATE(expense_date) asc')->orderBy('id')->get()
            ->map(fn (GwExpense $e) => [
                $e->expense_date->format('d M Y'), ucfirst($e->category), $this->cell($e->amount), (string) ($e->purchase?->waybill_no ?? ''), (string) ($e->vehicle?->number_plate ?? ''),
                (string) ($e->payee ?? ''), (string) ($e->receipt_ref ?? ''), (string) ($e->paidBy?->full_name ?? ''), str_replace('_', ' ', $e->reimbursement_status),
                $e->isVoided() ? 'Voided: '.$e->void_reason : 'Posted',
            ])->all();

        return $this->report('expenses', $meta, [
            'Fuel that a trip takes from Transport is not here: it is read from Transport and shown in the trip costs of the profit and loss.',
            'Only fuel is paid back; loading and offloading are paid in cash.',
        ], [['title' => 'Expenses', 'headings' => ['Date', 'Category', 'Amount (GH¢)', 'Waybill', 'Vehicle', 'Paid to', 'Receipt', 'Paid out of pocket by', 'Pay-back', 'Status'], 'rows' => $rows]], $from, $to);
    }

    protected function reimbursements(array $location, Carbon $from, Carbon $to, array $meta): array
    {
        $inRange = fn ($date) => $date->toDateString() >= $from->toDateString() && $date->toDateString() <= $to->toDateString();
        $pending = $this->summary->pendingReimbursements($location)->filter(fn (array $item) => $inRange($item['date']));
        $paid = $this->summary->reimbursed($location, 5000)->filter(fn (array $item) => $item['paid_on'] && $inRange($item['paid_on']));

        $row = fn (array $i) => [$i['person'], $i['date']->format('d M Y'), (string) ($i['waybill'] ?? ''), $this->cell($i['amount']), $i['kind'] === 'trip' ? 'From Transport' : 'G-Water expense', (string) ($i['reference'] ?? ''), $i['paid_on']?->format('d M Y') ?? ''];
        $headings = ['Person', 'Fuel date', 'Waybill', 'Amount (GH¢)', 'Source', 'Payment reference', 'Paid on'];

        return $this->report('reimbursements', $meta, [
            'Pending items are those dated in the period; paid items are those paid in the period.',
            'Fuel linked from Transport is read from the Transport expense, with the pay-back state held on the trip.',
        ], [
            ['title' => 'Waiting to be paid back', 'headings' => $headings, 'rows' => $pending->map($row)->all()],
            ['title' => 'Paid back', 'headings' => $headings, 'rows' => $paid->map($row)->all()],
        ], $from, $to);
    }

    protected function payments(array $location, Carbon $from, Carbon $to, array $meta): array
    {
        $payments = $this->visibility->atLocation(GwSupplierPayment::query(), $location)->with(['lines.product'])
            ->whereDate('request_date', '>=', $from->toDateString())->whereDate('request_date', '<=', $to->toDateString())
            ->orderByRaw('DATE(request_date) asc')->orderBy('id')->get();

        $rows = $payments->map(fn (GwSupplierPayment $p) => [
            $p->request_date->format('d M Y'), $p->reference, $p->lines->map(fn ($l) => $l->packs.' × '.$l->product->name)->implode('; '), $this->cell($p->amount),
            $p->paid_on?->format('d M Y') ?? '', $p->isVoided() ? 'Voided: '.$p->void_reason : ucfirst($p->status),
        ])->all();

        $statement = $this->summary->owedStatement($location, $to);
        $lines = collect($statement['lines'])->filter(fn (array $l) => $l['date'] >= $from->toDateString())->map(fn (array $l) => [
            Carbon::parse($l['date'])->format('d M Y'), $l['label'], $l['charge'] ? $this->cell($l['charge']) : '', $l['payment'] ? $this->cell($l['payment']) : '', $this->cell($l['balance']),
        ])->values()->all();

        return $this->report('payments', $meta, [
            'Owed = opening amount owed + trips at the cost they were bought at - payments marked paid.',
            'The statement starts with the balance carried from before the period: its first balance includes earlier lines.',
        ], [
            ['title' => 'Payment requests and payments', 'headings' => ['Requested', 'Reference', 'Packs', 'Amount (GH¢)', 'Paid on', 'Status'], 'rows' => $rows],
            ['title' => 'Statement of the account', 'headings' => ['Date', 'Entry', 'Owed (GH¢)', 'Paid (GH¢)', 'Balance (GH¢)'], 'rows' => $lines],
            ['title' => 'Owed to G Water at the end of the period', 'headings' => ['Measure', 'GH¢'], 'rows' => [['Owed', $this->cell($statement['owed'])]]],
        ], $from, $to);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    protected function report(string $key, array $meta, array $notes, array $tables, Carbon $from, Carbon $to): array
    {
        return [
            'key' => $key,
            'file' => ['region' => (string) $meta['Location'], 'period' => $from->format('Ymd').'-'.$to->format('Ymd')],
            'title' => self::REPORTS[$key]['title'],
            'meta' => $meta,
            'notes' => $notes,
            'tables' => $tables,
            'row_count' => array_sum(array_map(fn (array $table) => count($table['rows']), $tables)),
        ];
    }

    /** A cell for a figure that may be null. Money (pesewas) becomes cedis as a float so the PDF and Excel show two decimals. */
    protected function cell(mixed $value, bool $money = true): mixed
    {
        if ($value === null) {
            return '';
        }

        return $money ? round($value / 100, 2) : $value;
    }
}
