<?php

namespace App\Services\Finance;

/**
 * The FINANCE reminder set of a location (Finance > Settings > Reminders, finance.manage_reminders): whether Finance officers are
 * reminded at all, how many days a voucher may sit Queried before they are reminded, how many days Audit-acknowledged before they
 * are reminded to acknowledge it, how many days a Draft may sit unsent, and how often a reminder repeats. The Audit set is
 * AuditSettings; the two never share a key. The mechanics (overrides, only stored when different, audit rows) are ModuleReminderSettings'.
 */
class FinanceSettings extends ModuleReminderSettings
{
    public const ENABLED = 'finance_remind_enabled';

    public const QUERIED_WAIT = 'queried_wait_days';

    public const ACKNOWLEDGE_WAIT = 'acknowledge_wait_days';

    public const DRAFT_WAIT = 'draft_wait_days';

    public const REPEAT = 'finance_repeat_days';

    public function module(): string
    {
        return 'finance';
    }

    public function permission(): string
    {
        return 'finance.manage_reminders';
    }

    /** Whether Finance reminders are on for the location. @param array{region_id: int|null, district_id: int|null} $location */
    public function enabled(array $location): bool
    {
        return (bool) $this->value($location, self::ENABLED);
    }

    public function definitions(): array
    {
        return [
            self::ENABLED => $this->toggle('Send Finance reminders', 'Switch off to stop every Finance reminder for this location (queries, acknowledgements and drafts).', 'gwl.fin_remind_enabled'),
            self::QUERIED_WAIT => $this->days('Remind about a query after', 'A queried voucher that Finance has not corrected and resubmitted by then is reminded about to the Finance officers (those who can prepare vouchers).', 'gwl.fin_queried_wait_days'),
            self::ACKNOWLEDGE_WAIT => $this->days('Remind to acknowledge after', 'A voucher Audit has ticked that Finance has not acknowledged by then is reminded about to the Finance officers who can acknowledge.', 'gwl.fin_acknowledge_wait_days'),
            self::DRAFT_WAIT => $this->days('Remind about a draft after', 'A draft that has sat unsent this long is reminded about to the Finance officers who can prepare vouchers.', 'gwl.fin_draft_wait_days'),
            self::REPEAT => $this->days('Remind again after', 'The same voucher is reminded about at most once in this many days.', 'gwl.fin_remind_repeat_days'),
        ];
    }
}
