<?php

namespace App\Console\Commands\Letters;

use App\Models\Employee;
use App\Models\LetterDelivery;
use App\Models\LetterDispatchBatch;
use App\Models\LetterNotification;
use App\Models\LetterRemark;
use App\Models\LetterSnCounter;
use App\Models\LetterStatusLog;
use App\Models\MailLetter;
use App\Models\RoutingHistory;
use App\Services\Letters\LetterWorkflowService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills Letters with demo letters in every state (on a desk, in an unconfirmed transmittal, overdue, confirmed,
 * recalled, rejected, delivered, closed). Everything goes THROUGH LetterWorkflowService with the clock set back, so
 * serial numbers, status logs, hops, notices and audit rows are the real ones. Demo letters carry " (Demo)" at the end
 * of the subject and demo transmittals "(Demo)" at the start of the note: that is the only thing --remove deletes.
 */
class LettersDemoData extends Command
{
    protected $signature = 'letters:demo-data {--remove : Delete the demo letters (and nothing else)}';

    protected $description = 'Add (or with --remove, delete) demo letters for the Letters module';

    private const MARK = ' (Demo)';

    private const NOTE = '(Demo) ';

    /** Accra West (region 1) staff as found in the dev database; checked by id and role before use. */
    private const REGION_ID = 1;

    private LetterWorkflowService $flow;

    public function handle(LetterWorkflowService $flow): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $this->flow = $flow;

        return $this->option('remove') ? $this->remove() : $this->seed();
    }

    private function seed(): int
    {
        if (MailLetter::query()->where('subject', 'like', '%'.self::MARK)->exists()) {
            $this->warn('Demo letters already exist. Run with --remove first if you want a fresh set.');

            return self::SUCCESS;
        }

        $e = Employee::query()->whereIn('id', [15, 20, 29, 30, 37, 40, 42, 46, 53, 61, 62, 76, 77, 100])->get()->keyBy('id');

        foreach ([15, 20, 29, 30, 37, 40, 42, 46, 53, 61, 62, 76, 77, 100] as $id) {
            if (! $e->has($id)) {
                $this->error("Employee {$id} is missing; this set expects the Accra West dev staff.");

                return self::FAILURE;
            }
        }

        $steps = [
            'Letters waiting on a secretary\'s desk' => fn () => $this->onTheDesk($e),
            'Unconfirmed transmittal, overdue' => fn () => $this->overdueTransmittal($e),
            'Part-confirmed transmittal with a remark' => fn () => $this->partConfirmed($e),
            'Single hand-over awaiting confirmation' => fn () => $this->singleHandOver($e),
            'Delivered letters (closed)' => fn () => $this->delivered($e),
            'Closed by creator' => fn () => $this->closedByCreator($e),
            'Recalled hand-over' => fn () => $this->recalled($e),
            'Rejected hand-over' => fn () => $this->rejected($e),
            'Chief manager review and return' => fn () => $this->chiefReview($e),
        ];

        foreach ($steps as $label => $step) {
            try {
                $step();
                $this->info("OK   {$label}");
            } catch (\Throwable $exception) {
                $this->warn("SKIP {$label}: ".$exception->getMessage());
            } finally {
                Carbon::setTestNow();
            }
        }

        $this->line('Done. Remove with: php artisan letters:demo-data --remove');

        return self::SUCCESS;
    }

    // ---- Scenarios ----------------------------------------------------------------------------------------

    private function onTheDesk($e): void
    {
        $this->record($e[30], 'Request for duplicate water bills – Kaneshie customers', 'External', 0, 'Kaneshie Traders Association', 'DEMO/KAN/011');
        $this->record($e[30], 'Quarterly staff welfare committee meeting', 'Internal', 1, $e[29]);
        $in = $this->record($e[30], 'Invitation to regional stakeholder durbar', 'External', 3, 'Accra West Regional Coordinating Council', 'RCC/AW/044');
        $this->at(3, fn () => $this->flow->markInReview($in, $e[30]));
    }

    private function overdueTransmittal($e): void
    {
        $letters = collect([
            $this->record($e[30], 'Complaint on low water pressure – Dansoman Estates', 'External', 7, 'Dansoman Estates Residents Association', 'DEMO/DAN/101'),
            $this->record($e[30], 'Budget reallocation request – distribution maintenance', 'Internal', 7, $e[42]),
            $this->record($e[30], 'Notice of road construction affecting mains – Mallam Junction', 'External', 7, 'Ghana Highway Authority', 'GHA/AW/2026/220'),
            $this->record($e[30], 'Quotation for pipe fittings (batch 14)', 'External', 7, 'Allied Plumbing Supplies Ltd', 'APS/Q/0914'),
        ]);

        $this->at(6, fn () => $this->flow->dispatchBatch($e[30], $e[29], $letters->pluck('id')->all(), self::NOTE.'Admin matters for action'));
    }

    private function partConfirmed($e): void
    {
        $letters = collect([
            $this->record($e[30], 'Request for new connection – Santa Maria Heights', 'External', 5, 'Santa Maria Heights Developers', 'SMH/NC/07'),
            $this->record($e[30], 'Overtime claims for August – Operations', 'Internal', 5, $e[40]),
            $this->record($e[30], 'Report on non-revenue water – Odorkor zone', 'Internal', 5, $e[40]),
        ]);

        $batch = $this->at(4, fn () => $this->flow->dispatchBatch($e[30], $e[40], $letters->pluck('id')->all(), self::NOTE.'For operations review'));

        $this->at(3, fn () => $this->flow->confirmHardcopies($e[40], [$letters[0]->id, $letters[1]->id], $batch));

        $this->at(3, fn () => $this->flow->addRemark($letters[0], $e[40], [
            'manager_id' => $e[40]->id,
            'remark_content' => 'Site survey to be scheduled this week. Please advise the developer on the connection fees.',
        ]));

        // The third letter stays unconfirmed; the first goes on to the chief manager.
        $this->at(2, fn () => $this->flow->dispatch($letters[1], $e[40], $e[100]));
    }

    private function singleHandOver($e): void
    {
        $letter = $this->record($e[46], 'Meter replacement programme – Darkuman', 'Internal', 3, $e[53]);

        $this->at(2, fn () => $this->flow->dispatch($letter, $e[46], $e[53]));
    }

    private function delivered($e): void
    {
        $one = $this->record($e[15], 'Reply to Ga West Municipal Assembly on supply line', 'External', 12, 'Ga West Municipal Assembly', 'GWMA/WS/32');
        $this->at(11, fn () => $this->flow->dispatch($one, $e[15], $e[42]));
        $this->at(11, fn () => $this->flow->confirmHardcopy($one, $e[42]));
        $this->at(10, fn () => $this->flow->addRemark($one, $e[42], [
            'manager_id' => $e[42]->id,
            'remark_content' => 'Reply approved. Hand over to the Assembly\'s representative.',
        ]));
        $this->at(9, fn () => $this->flow->deliver($one, $e[42], [
            'delivered_to_name' => 'Mr. Kwesi Boateng (Ga West Municipal Assembly)',
            'delivered_at' => now(),
            'note' => 'Signed for on the dispatch book.',
        ]));

        $two = $this->record($e[15], 'Annual leave schedule – Administration', 'Internal', 9, $e[29]);
        $this->at(8, fn () => $this->flow->dispatch($two, $e[15], $e[29]));
        $this->at(8, fn () => $this->flow->confirmHardcopy($two, $e[29]));
        $this->at(7, fn () => $this->flow->deliver($two, $e[29], [
            'delivered_to_employee_id' => $e[37]->id,
            'delivered_at' => now(),
        ]));
    }

    private function closedByCreator($e): void
    {
        $letter = $this->record($e[20], 'Acknowledgement of receipt – tender documents', 'External', 10, 'Tema Pipe Works Ltd', 'TPW/ACK/18');

        $this->at(9, fn () => $this->flow->close($letter, $e[20]));
    }

    private function recalled($e): void
    {
        $letter = $this->record($e[62], 'Disconnection list – Amasaman zone B', 'Internal', 3, $e[61]);

        $this->at(3, fn () => $this->flow->dispatch($letter, $e[62], $e[61]));

        $hop = RoutingHistory::query()->where('letter_id', $letter->id)->latest('id')->firstOrFail();
        $this->at(2, fn () => $this->flow->recall($hop, $e[62], 'Sent to the wrong district manager.'));
    }

    private function rejected($e): void
    {
        $letter = $this->record($e[77], 'Request for transfer of vehicle between districts', 'Internal', 4, $e[76]);

        $this->at(4, fn () => $this->flow->dispatch($letter, $e[77], $e[76]));

        $hop = RoutingHistory::query()->where('letter_id', $letter->id)->latest('id')->firstOrFail();
        $this->at(3, fn () => $this->flow->reject($hop, $e[76], 'This belongs to Transport, not to the district office.'));
    }

    private function chiefReview($e): void
    {
        $letter = $this->record($e[15], 'Proposal for customer service week activities', 'Internal', 6, $e[37]);

        $this->at(6, fn () => $this->flow->dispatch($letter, $e[15], $e[100]));
        $this->at(5, fn () => $this->flow->confirmHardcopy($letter, $e[100]));
        $this->at(5, fn () => $this->flow->addRemark($letter, $e[100], [
            'chief_manager_id' => $e[100]->id,
            'remark_content' => 'Supported. Commercial to cost the activities and revert.',
        ]));
        $this->at(4, fn () => $this->flow->dispatch($letter, $e[100], $e[29]));
    }

    // ---- Helpers ------------------------------------------------------------------------------------------

    /** Run $callback with the clock set $daysAgo days back (the real clock is restored by seed()). */
    private function at(int $daysAgo, \Closure $callback): mixed
    {
        Carbon::setTestNow(Carbon::now()->subDays($daysAgo)->setTime(9, 30));

        try {
            return $callback();
        } finally {
            Carbon::setTestNow();
        }
    }

    /** A letter recorded by a secretary $daysAgo days ago; $sender is an Employee (Internal) or a company name (External). */
    private function record(Employee $secretary, string $subject, string $type, int $daysAgo, Employee|string $sender, ?string $refNo = null): MailLetter
    {
        return $this->at($daysAgo, fn () => $this->flow->create($secretary, [
            'subject' => $subject.self::MARK,
            'ref_no' => $refNo,
            'type' => $type,
            'memo_sender_id' => $sender instanceof Employee ? $sender->id : null,
            'company_sender' => is_string($sender) ? $sender : null,
            'date_on_letter' => now()->subDay()->toDateString(), // the clock is already set $daysAgo back
            'region_id' => self::REGION_ID,
        ]));
    }

    // ---- Removal ------------------------------------------------------------------------------------------

    private function remove(): int
    {
        $letterIds = MailLetter::query()->where('subject', 'like', '%'.self::MARK)->pluck('id');
        $batchIds = LetterDispatchBatch::query()->where('note', 'like', self::NOTE.'%')->pluck('id');

        if ($letterIds->isEmpty() && $batchIds->isEmpty()) {
            $this->line('No demo letters found.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($letterIds, $batchIds) {
            LetterNotification::query()
                ->where(fn ($q) => $q->whereIn('letter_id', $letterIds)->orWhereIn('batch_id', $batchIds))
                ->delete();
            LetterDelivery::query()->whereIn('letter_id', $letterIds)->delete();
            LetterRemark::query()->whereIn('letter_id', $letterIds)->delete();
            LetterStatusLog::query()->whereIn('letter_id', $letterIds)->delete();
            RoutingHistory::query()->whereIn('letter_id', $letterIds)->delete();
            LetterDispatchBatch::query()->whereIn('id', $batchIds)->delete();
            MailLetter::query()->whereIn('id', $letterIds)->delete();

            // Give the demo serial numbers back so the next real letter carries on from the last real one.
            foreach (LetterSnCounter::query()->get() as $counter) {
                $pattern = '/^'.preg_quote($counter->prefix, '/').'-'.$counter->year.'-(\d+)$/';
                $highest = MailLetter::query()
                    ->where('sn_number', 'like', $counter->prefix.'-'.$counter->year.'-%')
                    ->pluck('sn_number')
                    ->map(fn (string $sn) => preg_match($pattern, $sn, $m) ? (int) $m[1] : 0)
                    ->max() ?? 0;

                if ($highest < $counter->last_number) {
                    $counter->update(['last_number' => $highest]);
                }
            }
        });

        $this->info("Removed {$letterIds->count()} demo letters and {$batchIds->count()} demo transmittals. Audit log rows are kept.");

        return self::SUCCESS;
    }
}
