<?php

namespace App\Services\Finance;

use App\Models\FinPaymentVoucher;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The AUDIT reminders (php artisan audit:remind): a voucher With Audit that has waited too long for a tick or a query. Limit, on/off
 * and repeat interval are the location's AuditSettings (changed only by the Audit manager). Recipients: Audit officers based at the
 * voucher's location who hold audit.review. See PvReminderService for the mechanism.
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
        return $this->backlog->items(FinPaymentVoucher::query()->where('fin_pvs.status', FinPaymentVoucher::WITH_AUDIT), $now);
    }
}
