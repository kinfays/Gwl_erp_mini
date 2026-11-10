<?php

namespace App\Services\Finance;

use App\Models\FinPaymentVoucher;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The AUDIT reminders (php artisan audit:remind), two waits of a voucher With Audit (PvBacklog::auditItems):
 *  - UNASSIGNED: sent but not yet given to an officer for longer than AuditSettings::UNASSIGNED_WAIT: the Audit MANAGERS (audit.assign)
 *    of the voucher's location are reminded (nobody else in Audit can see or act on it);
 *  - OFFICER: given to an officer who has not ticked or queried it within AuditSettings::WAIT: only THAT officer is reminded.
 * On/off, both limits and the repeat interval are the location's AuditSettings (changed only by the Audit manager). See
 * PvReminderService for the mechanism.
 */
class AuditReminderService extends PvReminderService
{
    public function __construct(PvBacklog $backlog, PvNotificationService $notices, protected AuditSettings $audit)
    {
        parent::__construct($backlog, $notices);
    }

    protected function settings(): ModuleReminderSettings
    {
        return $this->audit;
    }

    protected function repeatKey(): string
    {
        return AuditSettings::REPEAT;
    }

    protected function enabledKey(): string
    {
        return AuditSettings::ENABLED;
    }

    protected function items(CarbonInterface $now): Collection
    {
        return $this->backlog->auditItems(FinPaymentVoucher::query(), $now);
    }
}
