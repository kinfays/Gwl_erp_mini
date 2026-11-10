<?php

namespace App\Services\Finance;

use App\Models\FinBatchEvent;
use App\Models\FinPaymentVoucher;
use App\Models\FinPvBatch;
use App\Models\FinPvEvent;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Who is told what about a voucher (in-app bell only, via GeneralDatabaseNotification). Recipients are people BASED AT THE
 * VOUCHER'S LOCATION (FinanceScope::usersAt): the Audit MANAGERS (audit.assign) hear that a voucher or batch is waiting to be
 * assigned; the Audit officer hears of an assignment and of a resubmission of a voucher assigned to them; Finance officers hear of a
 * query, a tick and a withdrawn tick. The person who did the action is never told about it.
 *
 * An action notice carries the PV number and the action ONLY, never the payee, the amount or a comment. A reminder (waiting())
 * carries the count of vouchers, the longest wait and the PV numbers, never the payee, amount or a comment.
 */
class PvNotificationService
{
    /** The most PV numbers one reminder names; the rest are counted ("and N more"). */
    public const REMINDER_NUMBERS = 10;

    public function __construct(protected FinanceScope $scope) {}

    public function actioned(int $pvId, string $action, int $actorId): void
    {
        $pv = FinPaymentVoucher::query()->find($pvId);

        if (! $pv) {
            return;
        }

        match ($action) {
            // Nobody in Audit can work on a voucher until the manager assigns it, so a new one is the MANAGERS' news.
            FinPvEvent::SUBMITTED => $this->toManagers($pv, $actorId, 'New voucher for Audit', "PV {$pv->pv_no} was sent to Audit and is waiting to be assigned.", 'pv_submitted'),
            // A corrected voucher stays with the officer who queried it; only if it somehow has none do the managers hear of it.
            FinPvEvent::RESUBMITTED => $pv->assigned_to_user_id
                ? $this->toOfficer($pv, $actorId, 'Voucher resubmitted', "PV {$pv->pv_no} was corrected and sent back to you.", 'pv_resubmitted')
                : $this->toManagers($pv, $actorId, 'Voucher resubmitted', "PV {$pv->pv_no} was corrected and sent to Audit again; it is waiting to be assigned.", 'pv_resubmitted'),
            FinPvEvent::ASSIGNED, FinPvEvent::REASSIGNED => $this->toOfficer($pv, $actorId, 'Voucher assigned to you', "PV {$pv->pv_no} was assigned to you.", 'pv_assigned'),
            FinPvEvent::QUERIED => $this->toFinance($pv, $actorId, 'Voucher queried', "PV {$pv->pv_no} was queried by Audit.", 'pv_queried'),
            FinPvEvent::AUDIT_ACKNOWLEDGED => $this->toFinance($pv, $actorId, 'Voucher acknowledged by Audit', "PV {$pv->pv_no} was acknowledged by Audit.", 'pv_audit_acknowledged'),
            FinPvEvent::TICK_WITHDRAWN => $this->toFinance($pv, $actorId, 'Audit tick withdrawn', "Audit withdrew its tick on PV {$pv->pv_no}.", 'pv_tick_withdrawn'),
            default => null,
        };
    }

    /**
     * ONE reminder notice per recipient for a group of vouchers of ONE location and ONE kind of wait that are overdue (the reminder
     * services group them). It carries the COUNT of vouchers, how long the longest has waited and the PV NUMBERS (the longest
     * waiting first, at most ten, then "and N more"), never a payee, an amount or a comment; it links to the list. Recipients are the people based at that location holding the permission of the
     * kind: Audit officers (audit.review) for With Audit; Finance officers who can prepare vouchers (finance.create_pv) for a query and
     * a draft; those who can acknowledge (finance.acknowledge) for a voucher Audit has ticked. Returns how many people were told.
     *
     * @param  Collection<int, array{pv: FinPaymentVoucher, kind: string, days: int, threshold: int}>  $items
     */
    public function waiting(Collection $items, string $kind): int
    {
        if ($items->isEmpty()) {
            return 0;
        }

        $place = $this->placeOf($items->first()['pv']);
        $count = $items->count();
        $longest = (int) $items->max('days');
        $things = $count === 1 ? 'voucher' : 'vouchers';
        $numbers = $items->sortByDesc('days')->pluck('pv.pv_no')->values();
        $listed = $numbers->take(self::REMINDER_NUMBERS);
        $more = $numbers->count() - $listed->count();
        $which = ' PV '.$listed->implode(', ').($more > 0 ? " and {$more} more" : '').'.';
        $audit = in_array($kind, [PvBacklog::AUDIT, PvBacklog::UNASSIGNED, PvBacklog::OFFICER], true);

        $message = match ($kind) {
            PvBacklog::AUDIT => "{$count} {$things} with Audit waiting for a tick or a query. The longest has waited {$longest} days.{$which}",
            PvBacklog::UNASSIGNED => "{$count} {$things} sent to Audit and still waiting to be given to an officer. The longest has waited {$longest} days.{$which}",
            PvBacklog::OFFICER => "{$count} {$things} given to you and still waiting for a tick or a query. The longest has waited {$longest} days.{$which}",
            PvBacklog::QUERIED => "{$count} queried {$things} waiting for Finance to correct and resubmit. The longest has waited {$longest} days.{$which}",
            PvBacklog::ACKNOWLEDGE => "{$count} {$things} ticked by Audit and waiting for Finance to acknowledge. The longest has waited {$longest} days.{$which}",
            default => "{$count} draft {$things} not yet sent to Audit. The oldest is {$longest} days old.{$which}",
        };

        $permission = match ($kind) {
            PvBacklog::AUDIT, PvBacklog::OFFICER => 'audit.review',
            PvBacklog::UNASSIGNED => 'audit.assign',
            PvBacklog::ACKNOWLEDGE => 'finance.acknowledge',
            default => 'finance.create_pv',
        };

        $recipients = $this->scope->usersAt($place, $permission)
            // One officer's wait goes to that officer alone (the group is already one officer's).
            ->when($kind === PvBacklog::OFFICER, fn ($query) => $query->whereKey((int) ($items->first()['recipient'] ?? 0)))
            ->get();

        foreach ($recipients as $user) {
            $user->notify(new GeneralDatabaseNotification(
                'Vouchers waiting',
                $message,
                route($kind === PvBacklog::UNASSIGNED ? 'audit.assignments' : ($audit ? 'audit.home' : 'finance.home')),
                $audit ? Permission::MODULE_AUDIT : Permission::MODULE_FINANCE,
                ['type' => 'pv_waiting', 'kind' => $kind, 'count' => $count, 'longest_days' => $longest, 'pv_numbers' => $listed->all()]
            ));
        }

        return $recipients->count();
    }

    /**
     * A batch was sent to Audit (the location's Audit managers are told, with the count and the batch reference) or given to an
     * Audit officer (that officer is told, with the count). Never a payee, amount or comment.
     */
    public function batchActioned(int $batchId, string $action, int $actorId, ?int $officerId = null, int $count = 0): void
    {
        $batch = FinPvBatch::query()->find($batchId);

        if (! $batch) {
            return;
        }

        $place = ['region_id' => (int) $batch->region_id, 'district_id' => $batch->district_id !== null ? (int) $batch->district_id : null];
        $things = $count === 1 ? 'voucher' : 'vouchers';

        if ($action === FinBatchEvent::SENT) {
            foreach ($this->scope->usersAt($place, 'audit.assign')->get()->reject(fn (User $user) => $user->id === $actorId) as $user) {
                $user->notify(new GeneralDatabaseNotification(
                    'New batch for Audit',
                    "Batch {$batch->batch_no} with {$count} {$things} was sent to Audit and is waiting to be assigned.",
                    route('audit.assignments'),
                    Permission::MODULE_AUDIT,
                    ['type' => 'batch_sent', 'batch_id' => $batch->id, 'count' => $count]
                ));
            }

            return;
        }

        if (in_array($action, [FinBatchEvent::ASSIGNED, FinBatchEvent::REASSIGNED], true) && $officerId !== null && $officerId !== $actorId) {
            $officer = User::query()->where('is_active', true)->find($officerId);

            $officer?->notify(new GeneralDatabaseNotification(
                'Vouchers assigned to you',
                "{$count} {$things} of batch {$batch->batch_no} ".($count === 1 ? 'was' : 'were').' assigned to you.',
                route('audit.batches.show', $batch->id),
                Permission::MODULE_AUDIT,
                ['type' => 'batch_assigned', 'batch_id' => $batch->id, 'count' => $count]
            ));
        }
    }

    /**
     * Several loose vouchers were given to one officer at once: ONE notice with the count and the PV numbers (ten at most).
     *
     * @param  array<int, int>  $pvIds
     */
    public function vouchersAssigned(int $officerId, array $pvIds, int $actorId): void
    {
        $officer = $officerId !== $actorId ? User::query()->where('is_active', true)->find($officerId) : null;
        $numbers = FinPaymentVoucher::query()->whereIn('id', $pvIds)->orderBy('id')->pluck('pv_no');

        if (! $officer || $numbers->isEmpty()) {
            return;
        }

        $listed = $numbers->take(self::REMINDER_NUMBERS);
        $more = $numbers->count() - $listed->count();

        $officer->notify(new GeneralDatabaseNotification(
            'Vouchers assigned to you',
            $numbers->count().' vouchers were assigned to you. PV '.$listed->implode(', ').($more > 0 ? " and {$more} more" : '').'.',
            route('audit.home'),
            Permission::MODULE_AUDIT,
            ['type' => 'pvs_assigned', 'count' => $numbers->count(), 'pv_numbers' => $listed->all()]
        ));
    }

    /** The location's Audit managers (audit.assign holders): who hears of a voucher that is waiting to be assigned. */
    private function toManagers(FinPaymentVoucher $pv, int $actorId, string $title, string $message, string $type): void
    {
        // The link is the assignment queue: the manager cannot open a voucher nobody has worked on.
        foreach ($this->scope->usersAt($this->placeOf($pv), 'audit.assign')->get()->reject(fn (User $user) => $user->id === $actorId) as $user) {
            $user->notify(new GeneralDatabaseNotification($title, $message, route('audit.assignments'), Permission::MODULE_AUDIT, ['type' => $type, 'pv_id' => $pv->id]));
        }
    }

    /** The officer the voucher is assigned to, and nobody else in Audit. */
    private function toOfficer(FinPaymentVoucher $pv, int $actorId, string $title, string $message, string $type): void
    {
        $officer = $pv->assigned_to_user_id ? User::query()->where('is_active', true)->find($pv->assigned_to_user_id) : null;

        if ($officer && $officer->id !== $actorId) {
            $this->send($officer, $title, $message, $pv, $type, 'audit');
        }
    }

    private function toFinance(FinPaymentVoucher $pv, int $actorId, string $title, string $message, string $type): void
    {
        foreach ($this->financeOfficers($pv)->reject(fn (User $user) => $user->id === $actorId) as $user) {
            $this->send($user, $title, $message, $pv, $type, 'finance');
        }
    }

    /** @return Collection<int, User> */
    private function financeOfficers(FinPaymentVoucher $pv): Collection
    {
        return $this->scope->usersAt($this->placeOf($pv), 'finance.create_pv')->get();
    }

    private function send(User $user, string $title, string $message, FinPaymentVoucher $pv, string $type, string $screen): void
    {
        $route = $screen === 'audit' ? 'audit.pvs.show' : 'finance.pvs.show';
        $module = $screen === 'audit' ? Permission::MODULE_AUDIT : Permission::MODULE_FINANCE;

        $user->notify(new GeneralDatabaseNotification($title, $message, route($route, $pv->id), $module, ['type' => $type, 'pv_id' => $pv->id]));
    }

    /** @return array{region_id: int, district_id: int|null} */
    private function placeOf(FinPaymentVoucher $pv): array
    {
        return ['region_id' => (int) $pv->region_id, 'district_id' => $pv->district_id !== null ? (int) $pv->district_id : null];
    }
}
