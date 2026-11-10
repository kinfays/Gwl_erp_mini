<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvBatch;
use App\Models\FinPvEvent;
use App\Models\User;
use App\Services\Leave\LeaveLetterService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Printing of payment vouchers and batches (Dompdf directly, like the other modules). THIS CLASS HOLDS ALL THE WORDING of a print
 * (headings, labels, sentences, the copy label, the footer): the Blade view only lays out the arrays it returns.
 *
 * Who may print what is the screens' own reach, nothing wider (FinanceScope, the same lookups the lists and detail pages use):
 *  - Finance (copy "Finance copy"): the vouchers and batches of its location, drafts included (a draft prints with a DRAFT mark);
 *  - Audit (copy "Audit copy"): an officer only the vouchers they may open; the manager only vouchers an officer has worked on, and
 *    in a batch only the minimal columns of the rest (no payee). Anything outside reach is a 404, like on the screen.
 * A print writes ONE audit row (ids and counts only: never a payee, an amount, a title or a comment) and adds one to the print count
 * of every voucher and batch printed; the footer says "printed n times" counting this print. No attachment is ever included.
 */
class PvPrintService
{
    public const COPY_FINANCE = 'Finance copy';

    public const COPY_AUDIT = 'Audit copy';

    public function __construct(
        protected FinanceScope $scope,
        protected PvCatalogService $catalogue,
        protected LeaveLetterService $words,
    ) {}

    // ------------------------------------------------------------------ rights

    /** 403 unless the user may print as Finance (finance.view) or as Audit (any Audit reach). */
    public function authorise(User $actor, string $as): void
    {
        $allowed = $as === FinanceScope::AS_AUDIT
            ? $this->scope->auditReach($actor) !== FinanceScope::AUDIT_NONE
            : $this->scope->can($actor, 'finance.view');

        abort_unless($allowed, 403, 'You do not have permission to print this.');
    }

    public function cap(): int
    {
        return max(1, (int) config('gwl.fin_print_max_vouchers'));
    }

    // ------------------------------------------------------------------ documents

    /**
     * One voucher, or a selection (each on its own page). A single id outside the user's reach is a 404; in a selection the ones outside
     * it simply drop out (and nothing at all is a 404).
     *
     * @param  list<int|string>  $ids
     * @return array<string, mixed>
     */
    public function vouchers(User $actor, string $as, array $ids, ?Carbon $now = null): array
    {
        $this->authorise($actor, $as);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        abort_if($ids === [], 404);

        $rows = $this->scope->pvs($actor, $as)->whereIn('fin_pvs.id', $ids)->orderBy('fin_pvs.id')->get();
        abort_if($rows->isEmpty() || (count($ids) === 1 && $rows->count() !== 1), 404);
        $this->assertWithinCap($rows->count());
        $this->prepare($rows);

        $now ??= now();
        $copy = $this->copyLabel($as);
        $pages = $rows->map(fn (Pv $pv) => $this->voucherPage($pv, $copy, $now))->all();
        $single = $rows->count() === 1;

        return [
            'kind' => 'vouchers',
            'as' => $as,
            'module' => $as === FinanceScope::AS_AUDIT ? 'audit' : 'finance',
            'title' => $single ? 'Payment voucher '.$rows->first()->pv_no : 'Payment vouchers ('.$rows->count().')',
            'copy' => $copy,
            'cover' => null,
            'table' => null,
            'pages' => $pages,
            'footer' => $this->footer($actor, $now, $single ? $this->printedWords($rows->first()->print_count + 1) : null),
            'pv_ids' => $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'batch_id' => null,
            'own_pages' => true,
            'place' => $this->place($rows->first()),
            'file' => $single ? 'pv-'.(Str::slug($rows->first()->pv_no) ?: $rows->first()->id) : 'pvs-'.$now->format('Ymd-His'),
        ];
    }

    /**
     * A batch: a cover sheet, a table of every voucher the user may list, and (optionally) each voucher the user may open on its own page.
     *
     * @return array<string, mixed>
     */
    public function batch(User $actor, string $as, int $batchId, bool $ownPages = false, ?Carbon $now = null): array
    {
        $this->authorise($actor, $as);
        $batch = $this->scope->findBatch($actor, $batchId, $as);
        $now ??= now();
        $copy = $this->copyLabel($as);
        $whole = $as === FinanceScope::AS_FINANCE || $this->scope->seesAll($actor) || $this->scope->can($actor, 'audit.assign');

        [$rows, $openable] = $this->batchMembers($actor, $as, $batch, $whole);
        $this->assertWithinCap($rows->count());
        $this->prepare($rows);

        $pageRows = $ownPages ? $rows->filter(fn (Pv $pv) => $openable->contains($pv->id))->values() : collect();
        $pages = $pageRows->map(fn (Pv $pv) => $this->voucherPage($pv, $copy, $now))->all();
        $officers = $whole ? User::query()->whereIn('id', $rows->pluck('assigned_to_user_id')->filter()->unique())->orderBy('full_name')->pluck('full_name')->all() : [];

        return [
            'kind' => 'batch',
            'as' => $as,
            'module' => $as === FinanceScope::AS_AUDIT ? 'audit' : 'finance',
            'title' => 'Batch '.$batch->batch_no,
            'copy' => $copy,
            'cover' => $this->cover($batch, $rows, $whole, $officers, $openable, $ownPages, $now),
            'table' => $this->table($rows, $openable),
            'pages' => $pages,
            'footer' => $this->footer($actor, $now, $this->printedWords($batch->print_count + 1, 'batch')),
            'pv_ids' => $pageRows->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'batch_id' => $batch->id,
            'own_pages' => $ownPages,
            'place' => ['region_id' => (int) $batch->region_id, 'district_id' => $batch->district_id !== null ? (int) $batch->district_id : null],
            'file' => 'batch-'.(Str::slug($batch->batch_no) ?: $batch->id),
        ];
    }

    // ------------------------------------------------------------------ the wording

    public function copyLabel(string $as): string
    {
        return $as === FinanceScope::AS_AUDIT ? self::COPY_AUDIT : self::COPY_FINANCE;
    }

    /** @return array{left: string, right: string|null} */
    protected function footer(User $actor, Carbon $now, ?string $printed): array
    {
        return [
            'left' => 'Printed by '.$actor->full_name.' on '.$now->format('d M Y').' at '.$now->format('H:i'),
            'right' => $printed,
        ];
    }

    protected function printedWords(int $times, string $what = 'voucher'): string
    {
        return ucfirst($what).' printed '.$times.' '.($times === 1 ? 'time' : 'times');
    }

    /** "Ghana cedis ... and ... pesewas only": the whole-number words are LeaveLetterService::numberToWords()'s, group by group. */
    public function amountInWords(int $pesewas): string
    {
        $cedis = intdiv($pesewas, 100);
        $pesewa = $pesewas % 100;
        $parts = [];

        if ($cedis === 0 && $pesewa > 0) {
            return ucfirst($this->words->numberToWords($pesewa)).' '.($pesewa === 1 ? 'pesewa' : 'pesewas').' only';
        }

        foreach ([[1_000_000, 'million'], [1_000, 'thousand']] as [$size, $name]) {
            if ($cedis >= $size) {
                $parts[] = $this->words->numberToWords(intdiv($cedis, $size)).' '.$name;
                $cedis %= $size;
            }
        }

        if ($cedis > 0 || $parts === []) {
            $parts[] = (count($parts) > 0 && $cedis < 100 ? 'and ' : '').$this->words->numberToWords($cedis);
        }

        $text = ucfirst(implode(' ', $parts)).' Ghana '.(intdiv($pesewas, 100) === 1 ? 'cedi' : 'cedis');

        if ($pesewa > 0) {
            $text .= ' and '.$this->words->numberToWords($pesewa).' '.($pesewa === 1 ? 'pesewa' : 'pesewas');
        }

        return $text.' only';
    }

    /** @return array<string, mixed> one voucher's page: only strings and lists, ready to be laid out */
    protected function voucherPage(Pv $pv, string $copy, Carbon $now): array
    {
        $marks = $pv->marks();
        $mark = match (true) {
            $pv->isDraft() => 'DRAFT - not yet sent to Audit',
            $pv->isVoided() => 'VOIDED',
            default => null,
        };

        $rows = [
            ['Voucher no.', $pv->pv_no.($pv->round > 1 ? ' (round '.$pv->round.')' : '')],
            ['PV date', $pv->pv_date->format('d M Y')],
            ['Location', $this->place($pv)['label']],
            ['Status', $pv->statusLabel()],
        ];

        if ($pv->batch) {
            $rows[] = ['Batch', $pv->batch->batch_no.(filled($pv->batch->title) ? ' ('.$pv->batch->title.')' : '')];
        }

        $rows[] = ['Payee', $pv->payee_name.' ('.$pv->payeeKindLabel().')'];
        $rows[] = ['Category', $pv->category_label];
        $rows[] = ['Type', $pv->type_label];

        if ($pv->reachedAudit()) {
            $rows[] = ['Audit officer', $pv->assignedTo?->full_name ?? ($pv->status === Pv::WITH_AUDIT ? 'Awaiting assignment' : '-')];
        }

        $rows[] = ['Prepared by', $pv->creator?->full_name ?? '-'];

        return [
            'copy' => $copy,
            'heading' => 'Payment voucher',
            'mark' => $mark,
            'rows' => $rows,
            'amount_label' => 'Amount (GH¢)',
            'amount' => $pv->amountFormatted(),
            'words_label' => 'In words',
            'words' => $this->amountInWords((int) $pv->amount),
            'details_label' => 'Details',
            'details' => (string) $pv->details,
            'remarks_label' => 'Finance remarks',
            'remarks' => filled($pv->remarks) ? (string) $pv->remarks : null,
            'marks_label' => 'Audit: '.$marks['audit']['word'].($marks['audit']['by'] ? ' ('.$marks['audit']['by'].')' : '').'     Finance: '.$marks['finance']['word'].($marks['finance']['by'] ? ' ('.$marks['finance']['by'].')' : ''),
            'timeline_label' => 'Timeline',
            'timeline_empty' => 'Nothing has happened to this voucher yet.',
            'timeline' => $this->timeline($pv),
            'printed' => $this->printedWords($pv->print_count + 1),
            'pv_id' => $pv->id,
        ];
    }

    /** @return list<array{round: string, lines: list<array<string, string|null>>}> */
    protected function timeline(Pv $pv): array
    {
        $rounds = [];

        foreach ($pv->events->groupBy('round') as $round => $events) {
            $rounds[] = [
                'round' => 'Round '.$round,
                'lines' => $events->map(function (FinPvEvent $event) {
                    $changed = collect($event->changes ?? [])->pluck('field')->implode(', ');

                    return [
                        'when' => $event->created_at->format('d M Y H:i'),
                        'what' => $event->label().($event->toUser ? ' to '.$event->toUser->full_name : ''),
                        'by' => $event->actorName(),
                        'reason' => $event->reasonLabel() ? 'Reason: '.$event->reasonLabel() : null,
                        'comment' => filled($event->comment) ? (string) $event->comment : null,
                        'changed' => $changed !== '' ? 'Changed: '.$changed : null,
                    ];
                })->all(),
            ];
        }

        return $rounds;
    }

    /**
     * @param  Collection<int, Pv>  $rows
     * @param  list<string>  $officers
     * @param  Collection<int, int>  $openable
     * @return array<string, mixed>
     */
    protected function cover(FinPvBatch $batch, Collection $rows, bool $whole, array $officers, Collection $openable, bool $ownPages, Carbon $now): array
    {
        $state = FinPvBatch::computeState((string) $batch->status, $rows);
        $counts = $rows->countBy('status');
        $statusCounts = collect(Pv::STATUSES)->map(fn (string $label, string $key) => $counts->get($key) ? [$label, (int) $counts->get($key)] : null)->filter()->values()->all();
        $skipped = $ownPages ? $rows->count() - $openable->intersect($rows->pluck('id'))->count() : 0;

        $facts = [
            ['Batch number', $batch->batch_no],
            ['Title', filled($batch->title) ? $batch->title : '-'],
            ['Location', $this->place($batch)['label']],
            ['Date sent', $batch->sent_at ? $batch->sent_at->format('d M Y H:i') : 'Not sent to Audit yet'],
            ['State', $state['label']],
        ];

        if ($whole) {
            $facts[] = ['Assigned to', $officers === [] ? 'Nobody yet' : implode(', ', $officers)];
        }

        $facts[] = [$whole ? 'Vouchers' : 'Your vouchers', (string) $rows->count()];
        $facts[] = ['Total (GH¢)', \App\Services\GWater\Money::format((int) $rows->sum('amount'))];

        return [
            'heading' => 'Batch '.$batch->batch_no,
            'facts' => $facts,
            'counts_label' => 'Vouchers by status',
            'status_counts' => array_map(fn (array $pair) => $pair[0].': '.$pair[1], $statusCounts),
            'notes' => array_values(array_filter([
                $batch->isCancelled() ? 'This batch was cancelled.' : null,
                $whole ? null : 'Only the vouchers given to you are listed.',
                $skipped > 0 ? $skipped.' '.($skipped === 1 ? 'voucher is' : 'vouchers are').' not printed on their own page: nobody in Audit has acted on them yet.' : null,
            ])),
            'own_pages_note' => $ownPages ? 'Each voucher follows on its own page.' : null,
        ];
    }

    /**
     * @param  Collection<int, Pv>  $rows
     * @param  Collection<int, int>  $openable
     * @return array<string, mixed>
     */
    protected function table(Collection $rows, Collection $openable): array
    {
        $lines = $rows->map(function (Pv $pv) use ($openable) {
            $marks = $pv->marks();
            $mark = fn (array $m) => $m['word'].($m['by'] ? ' - '.$m['by'] : '');

            return [
                $pv->pv_no,
                $pv->pv_date?->format('d M Y') ?? '-',   // not among the manager's minimal columns
                $openable->contains($pv->id) ? $pv->payee_name : '(opens after an officer has acted)',
                $pv->category_label.' / '.$pv->type_label,
                $pv->amountFormatted(),
                $mark($marks['audit']),
                $mark($marks['finance']),
            ];
        })->all();

        return [
            'heading' => 'Vouchers',
            'head' => ['PV no.', 'PV date', 'Payee', 'Category / type', 'Amount (GH¢)', 'Audit', 'Finance'],
            'rows' => $lines,
            'total_label' => 'Total (GH¢)',
            'total' => \App\Services\GWater\Money::format((int) $rows->sum('amount')),
            'empty' => 'This batch has no vouchers you may list.',
        ];
    }

    // ------------------------------------------------------------------ what a user may list

    /**
     * The batch's vouchers the user may list, and which of them they may open. Finance: all, all openable. Audit: the manager lists
     * every voucher (minimal columns) but opens only what an officer has worked on; an officer lists and opens only their own.
     *
     * @return array{0: Collection<int, Pv>, 1: Collection<int, int>}
     */
    protected function batchMembers(User $actor, string $as, FinPvBatch $batch, bool $whole): array
    {
        $opened = $this->scope->pvs($actor, $as)->where('fin_pvs.batch_id', $batch->id)->orderBy('fin_pvs.id')->get();
        $openable = $opened->pluck('id')->map(fn ($id) => (int) $id);

        if ($as === FinanceScope::AS_FINANCE || ! $whole) {
            return [$opened, $openable];
        }

        // The manager's whole list: minimal columns, replaced by the full voucher where they may open it.
        $minimal = $this->scope->assignmentQueue($actor)->where('fin_pvs.batch_id', $batch->id)->orderBy('fin_pvs.id')->get();
        $full = $opened->keyBy('id');

        return [$minimal->map(fn (Pv $pv) => $full->get($pv->id) ?? $pv)->values(), $openable];
    }

    /** Loads what the pages and the table need and the effective category / type names (never `category->name`). */
    protected function prepare(Collection $rows): void
    {
        $rows->load(['events.actor', 'events.toUser:id,full_name', 'assignedTo:id,full_name', 'creator:id,full_name', 'batch:id,batch_no,title', 'region:id,region_name', 'district:id,district_name']);
        $this->catalogue->labelPvs($rows->all());
    }

    /** @return array{region_id: int, district_id: int|null, label: string} */
    protected function place(Pv|FinPvBatch $row): array
    {
        $place = ['region_id' => (int) $row->region_id, 'district_id' => $row->district_id !== null ? (int) $row->district_id : null];
        $row->loadMissing(['region:id,region_name', 'district:id,district_name']);

        return $place + ['label' => $this->scope->isHeadOffice($place) ? 'Head Office' : ($row->region?->region_name ?? 'Location')];
    }

    protected function assertWithinCap(int $count): void
    {
        if ($count > $this->cap()) {
            throw new PrintLimitException($count, $this->cap());
        }
    }

    // ------------------------------------------------------------------ the file, and the record of it

    /** The PDF bytes (A4 portrait, page numbers in the corner). */
    public function pdf(array $document): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('finance.print.document', ['doc' => $document])->render());
        $pdf->setPaper('a4', 'portrait');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 96, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.3, 0.3, 0.3]);

        return (string) $pdf->output();
    }

    public function filename(array $document): string
    {
        return $document['module'].'-'.$document['file'].'.pdf';
    }

    /**
     * After the PDF exists: one audit row (ids and counts only) and one more on the print count of every voucher and batch printed.
     * A voucher listed only in a batch's table is not "printed"; one that has its own page is.
     */
    public function record(User $actor, array $document): void
    {
        DB::transaction(function () use ($actor, $document) {
            if ($document['pv_ids'] !== []) {
                Pv::query()->whereIn('id', $document['pv_ids'])->increment('print_count');
            }

            if ($document['batch_id'] !== null) {
                FinPvBatch::query()->whereKey($document['batch_id'])->increment('print_count');
            }

            $action = $document['module'].'.'.match (true) {
                $document['kind'] === 'batch' => 'batch_printed',
                count($document['pv_ids']) === 1 => 'pv_printed',
                default => 'pvs_printed',
            };

            AuditLog::record($action, $document['module'], $document['batch_id'] ? 'fin_pv_batches' : 'fin_pvs', $document['batch_id'] ?? (count($document['pv_ids']) === 1 ? $document['pv_ids'][0] : null), null, null, [
                'copy' => $document['copy'],
                'vouchers' => count($document['pv_ids']),
                'listed' => $document['kind'] === 'batch' ? count($document['table']['rows']) : count($document['pv_ids']),
                'own_pages' => (bool) $document['own_pages'],
                'batch_id' => $document['batch_id'],
                'region_id' => $document['place']['region_id'],
                'district_id' => $document['place']['district_id'],
            ]);
        });
    }
}
