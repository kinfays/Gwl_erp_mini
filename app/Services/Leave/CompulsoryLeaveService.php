<?php

namespace App\Services\Leave;

use App\Models\AuditLog;
use App\Models\CompulsoryLeavePeriod;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The compulsory leave of a year: who may set it, what applies when nothing is set, and saving it (audited, and the
 * year's entitlements recalculated). The deduction itself is made by LeaveEntitlementCalculator; compulsory leave is
 * never a leave request and is never charged to used_days.
 */
class CompulsoryLeaveService
{
    public const PERMISSION = 'leave.manage_compulsory';

    public function __construct(protected AnnualEntitlementService $entitlements) {}

    /**
     * Head Office HR and Global Admin (slug `admin`) manage it; super_admin bypasses. Regional HR and everyone else
     * have no access, whatever permissions they might have been given.
     */
    public function canManage(User $user): bool
    {
        if ($user->hasRoles('super_admin', 'admin')) {
            return true;
        }

        return $user->hasRoles('hr_headoffice') && $user->hasPermission(self::PERMISSION);
    }

    /**
     * What applies to $year: its record, the configured default when there is none, or — for a year handled by the older
     * category-based deduction screen — nothing more (those days are already in used_days).
     *
     * @return array{period: ?CompulsoryLeavePeriod, days: int, source: 'record'|'default'|'legacy'}
     */
    public function statusFor(int $year): array
    {
        $period = CompulsoryLeavePeriod::forYear($year);

        if (CompulsoryLeavePeriod::hasLegacyDeduction($year)) {
            return ['period' => $period, 'days' => 0, 'source' => 'legacy'];
        }

        return $period
            ? ['period' => $period, 'days' => (int) $period->days, 'source' => 'record']
            : ['period' => null, 'days' => (int) config('gwl.leave_compulsory_default_days', 11), 'source' => 'default'];
    }

    /**
     * The shutdown windows still ahead for this person: from the start date up to the day before staff resume, for the
     * years whose compulsory leave has both dates set and applies to them. Used to keep leave requests off those days.
     *
     * @return list<array{start: string, end: string, label: string, days: int}>
     */
    public function windowsFor(?Employee $employee): array
    {
        if (! $employee || ! Schema::hasTable('compulsory_leave_periods') || ! app(LeaveEntitlementCalculator::class)->appliesCompulsory($employee)) {
            return [];
        }

        return CompulsoryLeavePeriod::query()
            ->whereNotNull('start_date')
            ->whereNotNull('resume_date')
            ->whereDate('resume_date', '>', today())
            ->orderBy('start_date')
            ->get()
            ->map(function (CompulsoryLeavePeriod $period) {
                $end = $period->resume_date->copy()->subDay();

                return [
                    'start' => $period->start_date->toDateString(),
                    'end' => $end->toDateString(),
                    'label' => $period->start_date->format('d M Y').' - '.$end->format('d M Y'),
                    'days' => (int) $period->days,
                ];
            })
            ->all();
    }

    /** @return array{days: int, start_date: ?string, resume_date: ?string, notes: ?string} */
    protected function snapshot(CompulsoryLeavePeriod $period): array
    {
        return [
            'days' => (int) $period->days,
            'start_date' => $period->start_date?->toDateString(),
            'resume_date' => $period->resume_date?->toDateString(),
            'notes' => $period->notes,
        ];
    }

    /**
     * How many active staff the compulsory leave reaches and who is outside it, so HR can see what the days apply to.
     *
     * @return array{covered: int, district: int, contract: int, ungraded: int}
     */
    public function coverage(): array
    {
        $calculator = app(LeaveEntitlementCalculator::class);
        $counts = ['covered' => 0, 'district' => 0, 'contract' => 0, 'ungraded' => 0];

        Employee::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(500, function ($employees) use ($calculator, &$counts) {
                foreach ($employees as $employee) {
                    match (true) {
                        ! $calculator->isEligible($employee) => $counts['contract']++,
                        $employee->staffGrade() === null => $counts['ungraded']++,
                        $calculator->appliesCompulsory($employee) => $counts['covered']++,
                        default => $counts['district']++,
                    };
                }
            }, 'id');

        return $counts;
    }

    /**
     * Set the year's compulsory leave. Changing the days recalculates everyone's entitlement for the year (days already
     * taken are never taken back: see LeaveBalanceService::syncEntitlement).
     *
     * @param  array{days: int, start_date?: ?string, resume_date?: ?string, notes?: ?string}  $data
     * @return array{period: CompulsoryLeavePeriod, recalculated: array{updated: int, unchanged: int}|null}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function save(User $actor, int $year, array $data): array
    {
        if (! $this->canManage($actor)) {
            throw new AuthorizationException('You are not allowed to manage compulsory leave.');
        }

        if (CompulsoryLeavePeriod::hasLegacyDeduction($year)) {
            throw ValidationException::withMessages([
                'days' => "{$year} was handled by the earlier compulsory deduction, whose days are already counted as used. It can't be changed here.",
            ]);
        }

        $before = $this->statusFor($year)['days'];

        $period = DB::transaction(function () use ($actor, $year, $data) {
            $period = CompulsoryLeavePeriod::forYear($year) ?? new CompulsoryLeavePeriod(['year' => $year]);
            $old = $period->exists ? $this->snapshot($period) : null;

            $period->fill([
                'days' => (int) $data['days'],
                'start_date' => ($data['start_date'] ?? null) ?: null,
                'resume_date' => ($data['resume_date'] ?? null) ?: null,
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'updated_by' => $actor->id,
            ])->save();

            AuditLog::record(
                'leave_compulsory_period_saved',
                'leave',
                'compulsory_leave_periods',
                $period->id,
                $old,
                $this->snapshot($period),
                ['year' => $year]
            );

            return $period;
        });

        $recalculated = (int) $period->days !== $before
            ? $this->entitlements->recalculateYear($year, 'compulsory_days_changed')
            : null;

        return ['period' => $period, 'recalculated' => $recalculated];
    }
}
