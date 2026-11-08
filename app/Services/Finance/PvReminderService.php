<?php

namespace App\Services\Finance;

use App\Models\FinPaymentVoucher;
use App\Models\FinReminderState;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reminds the officers of a location about vouchers that have waited longer than that location allows. Two concrete services share
 * this one mechanism, each with ITS OWN settings and recipients: FinanceReminderService (finance:remind) and AuditReminderService
 * (audit:remind). What counts as waiting, and for how long, is PvBacklog's, the same as the Reports screen.
 *
 * A voucher is reminded about at most once per repeat interval per wait: fin_reminder_states remembers when each wait (`since`)
 * was last reminded (so running the command every day tells nobody twice in a row, while a voucher that is queried AGAIN starts a
 * fresh wait and is reminded afresh). A location whose set is switched off is skipped entirely. The vouchers due at a location are
 * grouped by kind of wait and each recipient gets ONE notice for the group: the count, the longest wait and the PV numbers, never a
 * payee, amount or comment. Recipients are people based at the location holding the permission of the kind (never super_admin). This reads
 * every location on purpose (like transport:check-expiries): it has no signed-in user, and each person only ever gets the notice
 * of their own location.
 */
abstract class PvReminderService
{
    public function __construct(
        protected PvBacklog $backlog,
        protected PvNotificationService $notices,
    ) {}

    /** This module's settings (on/off, repeat interval). */
    abstract protected function settings(): ModuleReminderSettings;

    /** The setting key of the repeat interval. */
    abstract protected function repeatKey(): string;

    /** The setting key of the on/off switch. */
    abstract protected function enabledKey(): string;

    /** @return Collection<int, array{pv: FinPaymentVoucher, kind: string, since: Carbon, days: int, threshold: int, overdue: bool}> */
    abstract protected function items(CarbonInterface $now): Collection;

    /**
     * @return array{waiting: int, overdue: int, reminded: int, notifications: int, skipped: int, disabled: int} counts of vouchers
     *                                                                                                          (notifications = notices sent; skipped = reminded recently; disabled = the location switched this set off)
     */
    public function remind(bool $dryRun = false, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $items = $this->items($now);
        $overdue = $items->where('overdue', true);
        $summary = ['waiting' => $items->count(), 'overdue' => $overdue->count(), 'reminded' => 0, 'notifications' => 0, 'skipped' => 0, 'disabled' => 0];
        $due = collect();

        foreach ($overdue as $item) {
            /** @var FinPaymentVoucher $pv */
            $pv = $item['pv'];
            $place = ['region_id' => (int) $pv->region_id, 'district_id' => $pv->district_id !== null ? (int) $pv->district_id : null];

            if (! $this->settings()->value($place, $this->enabledKey())) {
                $summary['disabled']++;

                continue;
            }

            $repeat = $this->settings()->value($place, $this->repeatKey());
            $state = FinReminderState::query()->where('pv_id', $pv->id)->where('kind', $item['kind'])->first();

            $isDue = ! $state
                || $state->since->getTimestamp() !== $item['since']->getTimestamp()
                || $state->last_sent_at->lte($now->copy()->subDays($repeat));

            if (! $isDue) {
                $summary['skipped']++;

                continue;
            }

            $due->push($item + ['unit' => FinanceBooksVersion::unitKey($place)]);
        }

        $summary['reminded'] = $due->count();

        if ($dryRun) {
            return $summary;
        }

        // One notice per recipient per location and kind of wait, then each voucher's wait is recorded as reminded.
        foreach ($due->groupBy(fn (array $item) => $item['unit'].'|'.$item['kind']) as $group) {
            $summary['notifications'] += $this->notices->waiting($group, $group->first()['kind']);

            foreach ($group as $item) {
                FinReminderState::query()->updateOrCreate(
                    ['pv_id' => $item['pv']->id, 'kind' => $item['kind']],
                    ['since' => $item['since'], 'last_sent_at' => $now]
                );
            }
        }

        return $summary;
    }
}
