<?php

namespace App\Services\Letters;

use App\Exceptions\Letters\RegisterTooLargeException;
use App\Models\Employee;
use App\Models\LetterDelivery;
use App\Models\LetterRemark;
use App\Models\LetterScan;
use App\Models\LetterStatusLog;
use App\Models\MailLetter;
use App\Models\RoutingHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A holder's register: the letters that came to their desk, one row per stay (one letter_status_logs row), so the
 * parallel Excel sheet secretaries keep can be retired. One query path feeds the Livewire preview, the Excel export
 * and the PDF, so they cannot disagree.
 *
 * A row is a letter the holder actually received: the creator's own intake, or a hand-over they confirmed. Hops that
 * are unconfirmed, recalled or rejected were never received and are not rows. The date range applies to the date
 * received (the hop's confirmed_at; the recording date for intake).
 *
 * Everything is loaded in a fixed number of queries (logs, letters, hops, remarks, deliveries) and matched in PHP.
 */
class LetterRegisterService
{
    public const SCOPES = [
        'all' => 'All',
        'with_me' => 'Still with me',
        'dispatched' => 'Dispatched',
        'closed' => 'Closed',
    ];

    /** @var array<string, string> row key => column heading, in order */
    public const COLUMNS = [
        'no' => 'No.',
        'date_received' => 'Date received',
        'sn_number' => 'SN',
        'ref_no' => 'Ref no.',
        'type' => 'Type',
        'date_on_letter' => 'Date on letter',
        'sender' => 'Sender',
        'received_from' => 'Received from',
        'subject' => 'Subject',
        'date_out' => 'Date out',
        'sent_to' => 'Sent to',
        'transmittal_no' => 'Transmittal no.',
        'status' => 'Status',
        'remarks' => 'Remarks I recorded',
    ];

    /** The columns in order; "Scanned" is added only when letter scans are enabled. */
    public function columns(): array
    {
        return config('gwl.letters_scans_enabled') ? self::COLUMNS + ['scanned' => 'Scanned'] : self::COLUMNS;
    }

    public function maxRows(): int
    {
        return max(1, (int) config('gwl.letters_register_max_rows', 5000));
    }

    public function normalizeScope(?string $scope): string
    {
        return array_key_exists((string) $scope, self::SCOPES) ? (string) $scope : 'all';
    }

    /**
     * @return Collection<int, array{
     *     no: int, log_id: int, letter_id: int, received_at: CarbonInterface, status_key: string,
     *     date_received: CarbonInterface, sn_number: string, ref_no: ?string, type: string,
     *     date_on_letter: ?CarbonInterface, sender: string, received_from: string, subject: string,
     *     date_out: ?CarbonInterface, sent_to: string, transmittal_no: string, status: string, remarks: string
     * }>
     *
     * @throws RegisterTooLargeException when more than letters_register_max_rows rows match
     */
    public function rows(Employee $holder, CarbonInterface $from, CarbonInterface $to, string $scope = 'all'): Collection
    {
        $scope = $this->normalizeScope($scope);
        $rangeStart = $from->copy()->startOfDay();
        $rangeEnd = $to->copy()->endOfDay();

        // A stay is received no earlier than its log was created, so later logs cannot fall in the range.
        $logs = LetterStatusLog::query()
            ->where('secretariat_id', $holder->id)
            ->where('created_at', '<=', $rangeEnd)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($logs->isEmpty()) {
            return collect();
        }

        $letterIds = $logs->pluck('letter_id')->unique()->values()->all();

        $letters = MailLetter::query()->whereIn('id', $letterIds)->with('memoSender')->get()->keyBy('id');

        $hops = RoutingHistory::query()
            ->whereIn('letter_id', $letterIds)
            ->where(fn ($query) => $query->where('to_secretariat_id', $holder->id)->orWhere('from_secretariat_id', $holder->id))
            ->with(['fromSecretariat:id,full_name', 'toSecretariat:id,full_name', 'batch:id,batch_no'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $remarks = LetterRemark::query()
            ->whereIn('letter_id', $letterIds)
            ->where('author_id', $holder->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('letter_id');

        $deliveries = Schema::hasTable('letter_deliveries')
            ? LetterDelivery::query()
                ->whereIn('letter_id', $letterIds)
                ->where('delivered_by_id', $holder->id)
                ->with('deliveredTo:id,full_name')
                ->orderBy('delivered_at')
                ->orderBy('id')
                ->get()
                ->groupBy('letter_id')
            : collect();

        $scanned = config('gwl.letters_scans_enabled')
            ? LetterScan::query()->active()->whereIn('letter_id', $letterIds)->distinct()->pluck('letter_id')->flip()
            : null;

        $incomingById = $hops->where('to_secretariat_id', $holder->id)->keyBy('id');
        $claimedIncoming = $logs->pluck('routing_history_id')->filter()->flip()->all();
        $claimedOutgoing = [];
        $hopsByLetter = $hops->groupBy('letter_id');

        $rows = collect();

        foreach ($logs->groupBy('letter_id') as $letterId => $stays) {
            /** @var MailLetter|null $letter */
            $letter = $letters->get($letterId);

            if (! $letter) {
                continue;
            }

            $stays = $stays->values();
            $letterHops = $hopsByLetter->get($letterId, collect());
            $outgoing = $letterHops->where('from_secretariat_id', $holder->id)->whereNull('resolution')->values();

            foreach ($stays as $index => $log) {
                $incoming = null;
                $isIntake = false;

                if ($log->routing_history_id) {
                    $incoming = $incomingById->get($log->routing_history_id);

                    // The hop that created this stay was never confirmed, or was taken back: never received.
                    if (! $incoming || ! $incoming->received_confirm || $incoming->isResolved()) {
                        continue;
                    }
                } else {
                    // Hops confirmed before the log-to-hop link existed: the latest unclaimed confirmed hop at or before the log.
                    $incoming = $letterHops
                        ->where('to_secretariat_id', $holder->id)
                        ->where('received_confirm', true)
                        ->whereNull('resolution')
                        ->reject(fn (RoutingHistory $hop) => isset($claimedIncoming[$hop->id]) || $hop->created_at->gt($log->created_at))
                        ->last();

                    if ($incoming) {
                        $claimedIncoming[$incoming->id] = true;
                    } elseif ($index === 0 && $letter->created_by_id === $holder->id) {
                        $isIntake = true; // the holder recorded the letter themselves
                    } else {
                        continue; // not a receipt (e.g. the creator's log added by an old reopen)
                    }
                }

                $receivedAt = $incoming ? ($incoming->confirmed_at ?? $incoming->updated_at) : $log->created_at;

                if ($receivedAt->lt($rangeStart) || $receivedAt->gt($rangeEnd)) {
                    continue;
                }

                // This stay ends with the first not-yet-claimed hand-over from the holder made during it.
                $nextStart = $stays->get($index + 1)?->created_at;
                $out = $outgoing->first(fn (RoutingHistory $hop) => ! isset($claimedOutgoing[$hop->id])
                    && $hop->created_at->gte($log->created_at)
                    && ($nextStart === null || $hop->created_at->lte($nextStart)));

                if ($out) {
                    $claimedOutgoing[$out->id] = true;
                }

                $delivery = $deliveries->get($letterId)?->last();
                [$statusKey, $status] = match (true) {
                    $out !== null => ['dispatched', 'Dispatched'],
                    $letter->isClosed() && $delivery !== null => ['closed', 'Delivered to '.$delivery->addresseeName()],
                    $letter->isClosed() => ['closed', 'Closed'],
                    default => ['with_me', 'With me'],
                };

                if ($scope !== 'all' && $scope !== $statusKey) {
                    continue;
                }

                $rows->push([
                    'no' => 0,
                    'log_id' => $log->id,
                    'letter_id' => $letterId,
                    'received_at' => $receivedAt,
                    'status_key' => $statusKey,
                    'date_received' => $receivedAt,
                    'sn_number' => $letter->sn_number,
                    'ref_no' => $letter->ref_no,
                    'type' => $letter->type,
                    'date_on_letter' => $letter->date_on_letter,
                    'sender' => $letter->sender_name,
                    'received_from' => $incoming ? (string) $incoming->fromSecretariat?->full_name : $letter->sender_name,
                    'subject' => $letter->subject,
                    'date_out' => $out ? ($log->out_date ?? $out->created_at) : null,
                    'sent_to' => $out ? (string) $out->toSecretariat?->full_name : '',
                    'transmittal_no' => $out ? (string) $out->batch?->batch_no : '',
                    'status' => $status,
                    'remarks' => $this->remarksDuringStay($remarks->get($letterId), $log->created_at, $out?->created_at),
                    'scanned' => $scanned?->has($letterId) ?? false,
                ]);

                if ($rows->count() > $this->maxRows()) {
                    // Stop early: the exact count does not matter, only that it is over the limit.
                    throw new RegisterTooLargeException($rows->count(), $this->maxRows());
                }
            }
        }

        return $rows
            ->sortBy([['received_at', 'asc'], ['log_id', 'asc']])
            ->values()
            ->map(function (array $row, int $index) {
                $row['no'] = $index + 1;

                return $row;
            });
    }

    /**
     * The row as display cells, keyed like COLUMNS. $remarksLimit is the most characters the remarks may take, ellipsis
     * included (the PDF keeps them short).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string|int>
     */
    public function cells(array $row, ?int $remarksLimit = null): array
    {
        $date = fn (?CarbonInterface $value) => $value?->format('d M Y') ?? '';

        $cells = [
            'no' => $row['no'],
            'date_received' => $date($row['date_received']),
            'sn_number' => (string) $row['sn_number'],
            'ref_no' => (string) ($row['ref_no'] ?? ''),
            'type' => (string) $row['type'],
            'date_on_letter' => $date($row['date_on_letter']),
            'sender' => (string) $row['sender'],
            'received_from' => (string) $row['received_from'],
            'subject' => (string) $row['subject'],
            'date_out' => $date($row['date_out']),
            'sent_to' => (string) $row['sent_to'],
            'transmittal_no' => (string) $row['transmittal_no'],
            'status' => (string) $row['status'],
            'remarks' => $remarksLimit ? Str::limit((string) $row['remarks'], $remarksLimit - 3) : (string) $row['remarks'],
        ];

        if (config('gwl.letters_scans_enabled')) {
            $cells['scanned'] = ! empty($row['scanned']) ? 'Yes' : '';
        }

        return $cells;
    }

    /**
     * What the holder recorded while they had the letter: manager remarks and "Secretary: ..." notes, joined. Remarks
     * belong to a stay by time, so a letter that comes back to the same desk keeps its two stays apart.
     *
     * @param  Collection<int, LetterRemark>|null  $remarks
     */
    protected function remarksDuringStay(?Collection $remarks, CarbonInterface $start, ?CarbonInterface $end): string
    {
        if (! $remarks) {
            return '';
        }

        return $remarks
            ->filter(fn (LetterRemark $remark) => $remark->created_at->gte($start) && ($end === null || $remark->created_at->lte($end)))
            ->map(fn (LetterRemark $remark) => collect([
                trim((string) $remark->remark_content),
                filled($remark->secretary_remark_content) ? 'Secretary: '.trim($remark->secretary_remark_content) : null,
            ])->filter()->join(' | '))
            ->filter()
            ->join(' / ');
    }
}
