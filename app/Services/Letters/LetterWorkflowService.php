<?php

namespace App\Services\Letters;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterNotification;
use App\Models\LetterRemark;
use App\Models\LetterStatusLog;
use App\Models\MailLetter;
use App\Models\Region;
use App\Models\RoutingHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LetterWorkflowService
{
    public function create(Employee $creator, array $data): MailLetter
    {
        return DB::transaction(function () use ($creator, $data) {
            $letter = MailLetter::create([
                'sn_number' => $this->nextSnNumber((int) $data['region_id']),
                'subject' => $data['subject'],
                'ref_no' => $data['ref_no'] ?? null,
                'type' => $data['type'],
                'memo_sender_id' => $data['type'] === 'Internal' ? ($data['memo_sender_id'] ?? null) : null,
                'company_sender' => $data['type'] === 'External' ? ($data['company_sender'] ?? null) : null,
                'date_on_letter' => $data['date_on_letter'],
                'region_id' => $data['region_id'],
                'created_by_id' => $creator->id,
            ]);

            LetterStatusLog::create([
                'letter_id' => $letter->id,
                'secretariat_id' => $creator->id,
                'status' => 'Received',
            ]);

            AuditLog::record('create_letter', 'letters', 'mail_letters', $letter->id, null, $letter->toArray());

            return $letter;
        });
    }

    public function markInReview(MailLetter $letter, Employee $actor): void
    {
        $log = $this->currentLog($letter, $actor);

        if ($log && $log->status === 'Received' && ! $log->is_closed && ! $this->pendingIncomingRoute($letter, $actor)) {
            $log->update(['status' => 'In Review']);
        }
    }

    public function dispatch(MailLetter $letter, Employee $from, Employee $to): void
    {
        if (! $this->canDispatch($letter, $from)) {
            throw new \RuntimeException('Confirm hardcopy receipt before dispatching this letter.');
        }

        if ($from->id === $to->id) {
            throw new \RuntimeException('Dispatch recipient must be different from the current secretariat.');
        }

        DB::transaction(function () use ($letter, $from, $to) {
            RoutingHistory::create([
                'letter_id' => $letter->id,
                'from_secretariat_id' => $from->id,
                'to_secretariat_id' => $to->id,
                'received_confirm' => false,
            ]);

            LetterStatusLog::create([
                'letter_id' => $letter->id,
                'secretariat_id' => $to->id,
                'status' => 'Received',
            ]);

            LetterNotification::create([
                'title' => 'Letter dispatched to you',
                'message' => $letter->sn_number . ' needs hardcopy receipt confirmation.',
                'secretariat_id' => $to->id,
                'letter_id' => $letter->id,
            ]);

            $this->currentLog($letter, $from)?->update([
                'status' => 'Dispatched',
                'out_date' => today(),
            ]);

            AuditLog::record('dispatch_letter', 'letters', 'mail_letters', $letter->id, null, [
                'from_secretariat_id' => $from->id,
                'to_secretariat_id' => $to->id,
            ]);
        });
    }

    public function confirmHardcopy(MailLetter $letter, Employee $actor): void
    {
        $route = $this->pendingIncomingRoute($letter, $actor);

        if (! $route) {
            throw new \RuntimeException('No pending hardcopy receipt confirmation was found.');
        }

        $route->update(['received_confirm' => true]);
        $this->markInReview($letter, $actor);

        AuditLog::record('confirm_letter_hardcopy', 'letters', 'mail_letters', $letter->id, null, [
            'routing_history_id' => $route->id,
        ]);
    }

    public function close(MailLetter $letter, Employee $actor): void
    {
        if ($letter->created_by_id !== $actor->id) {
            throw new \RuntimeException('Only the creator can close this letter.');
        }

        $letter->statusLogs()->update([
            'status' => 'Closed',
            'is_closed' => true,
        ]);

        AuditLog::record('close_letter', 'letters', 'mail_letters', $letter->id);
    }

    public function reopen(MailLetter $letter, Employee $actor): void
    {
        if ($letter->created_by_id !== $actor->id) {
            throw new \RuntimeException('Only the creator can reopen this letter.');
        }

        DB::transaction(function () use ($letter, $actor) {
            $letter->statusLogs()->update(['is_closed' => false]);

            LetterStatusLog::create([
                'letter_id' => $letter->id,
                'secretariat_id' => $actor->id,
                'status' => 'In Review',
                'is_closed' => false,
            ]);

            AuditLog::record('reopen_letter', 'letters', 'mail_letters', $letter->id);
        });
    }

    public function updateLetter(MailLetter $letter, Employee $actor, array $data): void
    {
        if ($letter->created_by_id !== $actor->id) {
            throw new \RuntimeException('Only the creator can edit this letter.');
        }

        $old = $letter->only(['subject', 'ref_no', 'date_on_letter', 'memo_sender_id', 'company_sender', 'type']);

        $letter->update([
            'subject' => $data['subject'],
            'ref_no' => $data['ref_no'] ?? null,
            'date_on_letter' => $data['date_on_letter'],
            'type' => $data['type'],
            'memo_sender_id' => $data['type'] === 'Internal' ? ($data['memo_sender_id'] ?? null) : null,
            'company_sender' => $data['type'] === 'External' ? ($data['company_sender'] ?? null) : null,
        ]);

        AuditLog::record('update_letter', 'letters', 'mail_letters', $letter->id, $old, $letter->fresh()->toArray());
    }

    public function addRemark(MailLetter $letter, Employee $actor, string $content): LetterRemark
    {
        $remark = LetterRemark::create([
            'letter_id' => $letter->id,
            'author_id' => $actor->id,
            'remark_secretariat_id' => $actor->id,
            'remark_content' => $content,
            'created_by_id' => $actor->id,
        ]);

        AuditLog::record('add_letter_remark', 'letters', 'mail_letters', $letter->id, null, [
            'remark_id' => $remark->id,
        ]);

        return $remark;
    }

    public function updateRemark(LetterRemark $remark, Employee $actor, string $content): void
    {
        if ($remark->author_id !== $actor->id) {
            throw new \RuntimeException('Only the remark creator can edit it.');
        }

        $remark->update(['remark_content' => $content]);
    }

    public function canDispatch(MailLetter $letter, Employee $actor): bool
    {
        $log = $this->currentLog($letter, $actor);

        return $log
            && in_array($log->status, ['Received', 'In Review'], true)
            && ! $log->is_closed
            && ! $this->pendingIncomingRoute($letter, $actor);
    }

    public function currentLog(MailLetter $letter, Employee $actor): ?LetterStatusLog
    {
        return LetterStatusLog::query()
            ->where('letter_id', $letter->id)
            ->where('secretariat_id', $actor->id)
            ->latest()
            ->first();
    }

    public function pendingIncomingRoute(MailLetter $letter, Employee $actor): ?RoutingHistory
    {
        return RoutingHistory::query()
            ->where('letter_id', $letter->id)
            ->where('to_secretariat_id', $actor->id)
            ->where('received_confirm', false)
            ->latest()
            ->first();
    }

    public function visibleLettersQuery(Employee $actor): Builder
    {
        return MailLetter::query()
            ->whereHas('statusLogs', fn (Builder $query) => $query->where('secretariat_id', $actor->id));
    }

    public function secretaryQuery(?string $search = null): Builder
    {
        return Employee::query()
            ->active()
            ->visibleInErp()
            ->where(function (Builder $query) {
                $query
                    ->whereHas('user.roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'secretary'))
                    ->orWhereHas('userByStaffId.roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'secretary'));
            })
            ->when($search, function (Builder $query) use ($search) {
                $query->where(function (Builder $searchQuery) use ($search) {
                    $searchQuery
                        ->where('full_name', 'like', '%' . $search . '%')
                        ->orWhere('staff_id', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            })
            ->orderBy('full_name');
    }

    protected function nextSnNumber(int $regionId): string
    {
        $region = Region::findOrFail($regionId);
        $prefix = $this->regionPrefix($region->region_name);
        $year = now()->year;
        $base = $prefix . '-' . $year . '-';

        $last = MailLetter::query()
            ->where('region_id', $regionId)
            ->where('sn_number', 'like', $base . '%')
            ->orderByDesc('sn_number')
            ->value('sn_number');

        $next = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return $base . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    protected function regionPrefix(string $regionName): string
    {
        $words = collect(preg_split('/\s+/', trim($regionName)) ?: [])
            ->filter()
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->join('');

        return $words ?: 'REG';
    }
}
