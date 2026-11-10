<?php

namespace App\Services\Finance;

use App\Models\FinPaymentVoucher;
use App\Models\FinPvEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The vouchers that are WAITING right now, and for how long: the one definition shared by the analytics ("Waiting right now"), the
 * waiting export and the reminder command, so a screen and a reminder can never disagree about what is overdue.
 *
 *  - audit: With Audit, waiting for a tick or a query, since the latest of submitted / resubmitted / tick withdrawn;
 *  - queried: Queried, waiting for Finance to correct and resubmit, since the query;
 *  - acknowledge: Audit acknowledged, waiting for Finance's acknowledgement, since Audit's tick.
 * A threshold per kind is the location's setting (AuditSettings for With Audit, FinanceSettings for the other two and for drafts). Reach is NOT decided here: the caller passes a query that is
 * already scoped (a screen: FinanceScope::pvs() at a location; the reminder command: every open voucher, on purpose).
 */
class PvBacklog
{
    public const AUDIT = 'audit';

    public const QUERIED = 'queried';

    public const ACKNOWLEDGE = 'acknowledge';

    /** With Audit and nobody in Audit holds it yet: the Audit manager's wait (AuditSettings::UNASSIGNED_WAIT). Only the Audit reminders ask for it. */
    public const UNASSIGNED = 'unassigned';

    /** With Audit and given to an officer: that officer's wait, counted from the later of the wait's start and the assignment (AuditSettings::WAIT). */
    public const OFFICER = 'officer';

    /** A Draft nobody has sent to Audit yet. Not "waiting" in the Reports sense (Audit never sees it): only the Finance reminders ask for it. */
    public const DRAFT = 'draft';

    /** Status => kind, the event whose time starts the wait, and the setting (of the Audit or the Finance set) that says how long is too long. */
    protected const KINDS = [
        FinPaymentVoucher::WITH_AUDIT => ['kind' => self::AUDIT, 'events' => [FinPvEvent::SUBMITTED, FinPvEvent::RESUBMITTED, FinPvEvent::TICK_WITHDRAWN], 'set' => 'audit', 'setting' => AuditSettings::WAIT],
        FinPaymentVoucher::QUERIED => ['kind' => self::QUERIED, 'events' => [FinPvEvent::QUERIED], 'set' => 'finance', 'setting' => FinanceSettings::QUERIED_WAIT],
        FinPaymentVoucher::AUDIT_ACKNOWLEDGED => ['kind' => self::ACKNOWLEDGE, 'events' => [FinPvEvent::AUDIT_ACKNOWLEDGED], 'set' => 'finance', 'setting' => FinanceSettings::ACKNOWLEDGE_WAIT],
    ];

    public function __construct(protected FinanceSettings $finance, protected AuditSettings $audit) {}

    /**
     * The Drafts of a scoped query that have sat unsent, with how long (since they were created) and the location's limit. Only for the
     * Finance reminders: a draft is Finance's alone and never part of items().
     *
     * @param  Builder<FinPaymentVoucher>  $pvs  a query that is already scoped
     * @return Collection<int, array{pv: FinPaymentVoucher, kind: string, since: Carbon, days: int, threshold: int, overdue: bool}> oldest first
     */
    public function drafts(Builder $pvs, ?CarbonInterface $now = null): Collection
    {
        $now = $now ? Carbon::instance($now) : now();

        return (clone $pvs)->where('fin_pvs.status', FinPaymentVoucher::DRAFT)->get(['fin_pvs.id', 'fin_pvs.pv_no', 'fin_pvs.status', 'fin_pvs.region_id', 'fin_pvs.district_id', 'fin_pvs.created_at'])
            ->map(function (FinPaymentVoucher $pv) use ($now) {
                $since = Carbon::instance($pv->created_at);
                $threshold = $this->finance->value($this->placeOf($pv), FinanceSettings::DRAFT_WAIT);
                $days = (int) floor(max(0, $now->getTimestamp() - $since->getTimestamp()) / 86400);

                return ['pv' => $pv, 'kind' => self::DRAFT, 'since' => $since, 'days' => $days, 'threshold' => $threshold, 'overdue' => $days >= $threshold];
            })->sortByDesc('days')->values();
    }

    /** @return array{region_id: int, district_id: int|null} */
    protected function placeOf(FinPaymentVoucher $pv): array
    {
        return ['region_id' => (int) $pv->region_id, 'district_id' => $pv->district_id !== null ? (int) $pv->district_id : null];
    }

    /**
     * @param  Builder<FinPaymentVoucher>  $pvs  a query that is already scoped
     * @return Collection<int, array{pv: FinPaymentVoucher, kind: string, since: Carbon, days: int, threshold: int, overdue: bool}> longest wait first
     */
    public function items(Builder $pvs, ?CarbonInterface $now = null): Collection
    {
        $now = $now ? Carbon::instance($now) : now();
        $open = (clone $pvs)->whereIn('fin_pvs.status', array_keys(self::KINDS))
            ->get(['fin_pvs.id', 'fin_pvs.pv_no', 'fin_pvs.status', 'fin_pvs.region_id', 'fin_pvs.district_id', 'fin_pvs.last_submitted_at', 'fin_pvs.submitted_at', 'fin_pvs.pv_date', 'fin_pvs.amount', 'fin_pvs.payee_name', 'fin_pvs.assigned_to_user_id', 'fin_pvs.assigned_at']);

        if ($open->isEmpty()) {
            return collect();
        }

        $actions = collect(self::KINDS)->pluck('events')->flatten()->unique()->all();
        $latest = [];

        foreach ($open->pluck('id')->chunk(500) as $ids) {
            FinPvEvent::query()->whereIn('pv_id', $ids->all())->whereIn('action', $actions)->orderBy('id')->get(['pv_id', 'action', 'created_at'])
                ->each(function (FinPvEvent $event) use (&$latest): void {
                    $latest[$event->pv_id][$event->action] = $event->created_at;
                });
        }

        return $open->map(function (FinPaymentVoucher $pv) use ($latest, $now) {
            $definition = self::KINDS[$pv->status];
            $times = collect($definition['events'])->map(fn (string $action) => $latest[$pv->id][$action] ?? null)->filter();
            $since = $times->isNotEmpty() ? Carbon::instance($times->max()) : Carbon::instance($pv->last_submitted_at ?? $pv->submitted_at ?? $now);
            $threshold = ($definition['set'] === 'audit' ? $this->audit : $this->finance)->value($this->placeOf($pv), $definition['setting']);
            $days = (int) floor(max(0, $now->getTimestamp() - $since->getTimestamp()) / 86400);

            return ['pv' => $pv, 'kind' => $definition['kind'], 'since' => $since, 'days' => $days, 'threshold' => $threshold, 'overdue' => $days >= $threshold];
        })->sortByDesc('days')->values();
    }

    /**
     * The vouchers With Audit split by WHO is waiting on them, for the Audit reminders (the Reports keep the single `audit` kind of items()):
     *  - unassigned: no officer yet; since the wait began; the manager's limit; no recipient officer;
     *  - officer: given to an officer; since the LATER of the wait's start and the assignment (an officer cannot be slow before they have
     *    it, and a reassignment gives the new officer a fresh wait); the officer's limit; `recipient` is that officer's user id.
     * Every With Audit voucher of the query is in exactly one of the two.
     *
     * @param  Builder<FinPaymentVoucher>  $pvs  a query that is already scoped
     * @return Collection<int, array{pv: FinPaymentVoucher, kind: string, since: Carbon, days: int, threshold: int, overdue: bool, recipient: int|null}> longest wait first
     */
    public function auditItems(Builder $pvs, ?CarbonInterface $now = null): Collection
    {
        $now = $now ? Carbon::instance($now) : now();

        return $this->items((clone $pvs)->where('fin_pvs.status', FinPaymentVoucher::WITH_AUDIT), $now)->map(function (array $item) use ($now) {
            /** @var FinPaymentVoucher $pv */
            $pv = $item['pv'];

            if ($pv->assigned_to_user_id === null) {
                $threshold = $this->audit->value($this->placeOf($pv), AuditSettings::UNASSIGNED_WAIT);

                return ['kind' => self::UNASSIGNED, 'threshold' => $threshold, 'overdue' => $item['days'] >= $threshold, 'recipient' => null] + $item;
            }

            $since = $pv->assigned_at && $pv->assigned_at->gt($item['since']) ? Carbon::instance($pv->assigned_at) : $item['since'];
            $days = (int) floor(max(0, $now->getTimestamp() - $since->getTimestamp()) / 86400);

            return ['kind' => self::OFFICER, 'since' => $since, 'days' => $days, 'overdue' => $days >= $item['threshold'], 'recipient' => (int) $pv->assigned_to_user_id] + $item;
        })->sortByDesc('days')->values();
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::AUDIT => 'With Audit',
            self::QUERIED => 'Queried, waiting for Finance',
            self::ACKNOWLEDGE => 'Ticked, waiting for Finance to acknowledge',
            self::DRAFT => 'Draft not yet sent',
            self::UNASSIGNED => 'Sent to Audit, waiting for an officer',
            self::OFFICER => 'With an Audit officer',
            default => $kind,
        };
    }
}
