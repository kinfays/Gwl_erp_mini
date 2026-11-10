<?php

namespace App\Services\Finance;

/**
 * The AUDIT reminder set of a location (Audit > Settings > Reminders, audit.manage_reminders): whether Audit officers are reminded
 * at all, how many days a voucher may sit With Audit before they are reminded, and how often a reminder repeats. Independent of the
 * Finance set (FinanceSettings): a Finance manager cannot see or change these and the Audit manager cannot change Finance's.
 * The wait is also what the Reports call "overdue" for vouchers With Audit.
 */
class AuditSettings extends ModuleReminderSettings
{
    public const ENABLED = 'audit_remind_enabled';

    public const WAIT = 'audit_wait_days';

    /** Days a sent voucher may stay unassigned before the Audit manager is reminded. */
    public const UNASSIGNED_WAIT = 'audit_unassigned_days';

    public const REPEAT = 'audit_repeat_days';

    public function module(): string
    {
        return 'audit';
    }

    public function permission(): string
    {
        return 'audit.manage_reminders';
    }

    /** Whether Audit reminders are on for the location. @param array{region_id: int|null, district_id: int|null} $location */
    public function enabled(array $location): bool
    {
        return (bool) $this->value($location, self::ENABLED);
    }

    public function definitions(): array
    {
        return [
            self::ENABLED => $this->toggle('Send Audit reminders', 'Switch off to stop Audit reminders for this location.', 'gwl.audit_remind_enabled'),
            self::UNASSIGNED_WAIT => $this->days('Remind the Audit manager after', 'A voucher or batch that has been sent to Audit this long without being given to an officer is reminded about to the Audit managers of the location.', 'gwl.fin_audit_unassigned_wait_days'),
            self::WAIT => $this->days('Remind the officer after', 'A voucher given to an officer that has waited this long for a tick or a query (counted from when it was given to them) is reminded about to that officer. Also what the Reports call overdue.', 'gwl.fin_audit_wait_days'),
            self::REPEAT => $this->days('Remind again after', 'The same voucher is reminded about at most once in this many days.', 'gwl.audit_remind_repeat_days'),
        ];
    }
}
