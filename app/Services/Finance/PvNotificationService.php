<?php

namespace App\Services\Finance;

use App\Models\FinPaymentVoucher;
use App\Models\FinPvEvent;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Who is told what about a voucher (in-app bell only, via GeneralDatabaseNotification). Recipients are people BASED AT THE
 * VOUCHER'S LOCATION (FinanceScope::usersAt): Audit officers hear of a submission or resubmission; Finance officers hear of a
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
            FinPvEvent::SUBMITTED => $this->toAudit($pv, $actorId, 'New voucher for Audit', "PV {$pv->pv_no} was sent to Audit.", 'pv_submitted'),
            FinPvEvent::RESUBMITTED => $this->toAudit($pv, $actorId, 'Voucher resubmitted', "PV {$pv->pv_no} was corrected and sent to Audit again.", 'pv_resubmitted'),
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
        $audit = $kind === PvBacklog::AUDIT;

        $message = match ($kind) {
            PvBacklog::AUDIT => "{$count} {$things} with Audit waiting for a tick or a query. The longest has waited {$longest} days.{$which}",
            PvBacklog::QUERIED => "{$count} queried {$things} waiting for Finance to correct and resubmit. The longest has waited {$longest} days.{$which}",
            PvBacklog::ACKNOWLEDGE => "{$count} {$things} ticked by Audit and waiting for Finance to acknowledge. The longest has waited {$longest} days.{$which}",
            default => "{$count} draft {$things} not yet sent to Audit. The oldest is {$longest} days old.{$which}",
        };

        $permission = match ($kind) {
            PvBacklog::AUDIT => 'audit.review',
            PvBacklog::ACKNOWLEDGE => 'finance.acknowledge',
            default => 'finance.create_pv',
        };

        $recipients = $this->scope->usersAt($place, $permission)->get();

        foreach ($recipients as $user) {
            $user->notify(new GeneralDatabaseNotification(
                'Vouchers waiting',
                $message,
                route($audit ? 'audit.home' : 'finance.home'),
                $audit ? Permission::MODULE_AUDIT : Permission::MODULE_FINANCE,
                ['type' => 'pv_waiting', 'kind' => $kind, 'count' => $count, 'longest_days' => $longest, 'pv_numbers' => $listed->all()]
            ));
        }

        return $recipients->count();
    }

    private function toAudit(FinPaymentVoucher $pv, int $actorId, string $title, string $message, string $type): void
    {
        foreach ($this->auditOfficers($pv)->reject(fn (User $user) => $user->id === $actorId) as $user) {
            $this->send($user, $title, $message, $pv, $type, 'audit');
        }
    }

    private function toFinance(FinPaymentVoucher $pv, int $actorId, string $title, string $message, string $type): void
    {
        foreach ($this->financeOfficers($pv)->reject(fn (User $user) => $user->id === $actorId) as $user) {
            $this->send($user, $title, $message, $pv, $type, 'finance');
        }
    }

    /** @return Collection<int, User> */
    private function auditOfficers(FinPaymentVoucher $pv): Collection
    {
        return $this->scope->usersAt($this->placeOf($pv), 'audit.review')->get();
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
