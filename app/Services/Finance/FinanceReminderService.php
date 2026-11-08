<?php

namespace App\Services\Finance;

use App\Models\FinPaymentVoucher;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The FINANCE reminders (php artisan finance:remind): a voucher Queried and not yet corrected and resubmitted, a voucher Audit has
 * ticked that Finance has not acknowledged, and a Draft nobody has sent. Limits, on/off and repeat interval are the location's
 * FinanceSettings (changed only by the Finance manager). Recipients: Finance officers based at the voucher's location who hold
 * finance.create_pv (query, draft) or finance.acknowledge (a tick). See PvReminderService for the mechanism.
 */
class FinanceReminderService extends PvReminderService
{
    public function __construct(PvBacklog $backlog, PvNotificationService $notices, protected FinanceSettings $finance)
    {
        parent::__construct($backlog, $notices);
    }

    protected function settings(): ModuleReminderSettings
    {
        return $this->finance;
    }

    protected function repeatKey(): string
    {
        return FinanceSettings::REPEAT;
    }

    protected function enabledKey(): string
    {
        return FinanceSettings::ENABLED;
    }

    protected function items(CarbonInterface $now): Collection
    {
        $waiting = $this->backlog->items(FinPaymentVoucher::query()->whereIn('fin_pvs.status', [FinPaymentVoucher::QUERIED, FinPaymentVoucher::AUDIT_ACKNOWLEDGED]), $now);

        return $waiting->concat($this->backlog->drafts(FinPaymentVoucher::query(), $now))->values();
    }
}
