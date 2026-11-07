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
 * A notice carries the PV number and the action ONLY, never the payee, the amount or a comment.
 */
class PvNotificationService
{
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

    /** One reminder for a voucher that has waited too long (used by the reminder command in phase 3). */
    public function waiting(FinPaymentVoucher $pv, int $days): int
    {
        $toAudit = $pv->status === FinPaymentVoucher::WITH_AUDIT;
        $recipients = $toAudit ? $this->auditOfficers($pv) : $this->financeOfficers($pv);
        $message = $toAudit
            ? "PV {$pv->pv_no} has been waiting for Audit for {$days} days."
            : "PV {$pv->pv_no} has been queried for {$days} days and is waiting for Finance.";

        foreach ($recipients as $user) {
            $this->send($user, 'Voucher waiting', $message, $pv, 'pv_waiting', $toAudit ? 'audit' : 'finance');
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
