<?php

namespace App\Services\Leave;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveEntitlement;

/**
 * Stores each employee's Annual entitlement for a year (leave_entitlements) and keeps the year's Annual leave balance
 * in step with it. The figures themselves come from LeaveEntitlementCalculator; this class is the persistence and the
 * "what changed, and what does it do to a balance that already has leave on it" part.
 *
 * Generation (a yearly job, and lazily when a balance is first opened) and recalculation (a grade, hire date, location
 * or the year's compulsory days changed) are the same operation, so running either again changes nothing that is already
 * right: the work is idempotent. When a balance already has days taken, only its remaining days move, and its entitlement
 * never drops below what has been used.
 */
class AnnualEntitlementService
{
    public function __construct(protected LeaveEntitlementCalculator $calculator) {}

    public function find(Employee $employee, int $year): ?LeaveEntitlement
    {
        return LeaveEntitlement::query()->where('employee_id', $employee->id)->where('year', $year)->first();
    }

    /**
     * The Annual days available to $employee in $year. The stored figure when there is one; otherwise worked out for this
     * and later years. A past year that was never generated predates grade-based entitlement, so it keeps the flat
     * figure it always had (this is what the carry-over fallback reads).
     */
    public function netFor(Employee $employee, int $year): int
    {
        if ($row = $this->find($employee, $year)) {
            return $row->net_days;
        }

        if ($year < (int) now()->format('Y')) {
            return (int) config('gwl.leave_annual_days.ungraded', 31);
        }

        return $this->calculator->netEntitlement($employee, $year);
    }

    /** gross / compulsory / net for a screen: the stored row, or the same figures worked out. @return array{gross: int, compulsory: int, net: int} */
    public function figuresFor(Employee $employee, int $year): array
    {
        if ($row = $this->find($employee, $year)) {
            return ['gross' => $row->gross_days, 'compulsory' => $row->compulsory_days, 'net' => $row->net_days];
        }

        $figures = $this->calculator->breakdown($employee, $year);

        return ['gross' => $figures['gross'], 'compulsory' => $figures['compulsory'], 'net' => $figures['net']];
    }

    /** The year's entitlement row for the employee, created or brought up to date. */
    public function forEmployee(Employee $employee, int $year, string $reason = 'generate'): LeaveEntitlement
    {
        $this->store($employee, $year, $reason);

        return $this->find($employee, $year);
    }

    /**
     * (Re)generate the year's entitlements for every active, leave-eligible employee. Contract staff are left out.
     *
     * @return array{created: int, updated: int, unchanged: int, skipped: int}
     */
    public function generate(int $year, string $reason = 'generate'): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        Employee::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($year, $reason, &$counts) {
                foreach ($employees as $employee) {
                    if (! $this->calculator->isEligible($employee)) {
                        $counts['skipped']++;

                        continue;
                    }

                    $counts[$this->store($employee, $year, $reason)]++;
                }
            });

        if ($counts['created'] > 0 || $counts['updated'] > 0) {
            AuditLog::record('leave_entitlements_generated', 'leave', 'year', $year, null, $counts, ['year' => $year, 'reason' => $reason]);
        }

        return $counts;
    }

    /**
     * Something this employee's entitlement depends on has changed: bring this year's and any later year's stored
     * entitlements up to date. Years that were never generated have nothing stored and are worked out when needed.
     */
    public function recalculate(Employee $employee, string $reason): void
    {
        if (! $employee->is_active) {
            return;
        }

        LeaveEntitlement::query()
            ->where('employee_id', $employee->id)
            ->where('year', '>=', (int) now()->format('Y'))
            ->orderBy('year')
            ->pluck('year')
            ->each(fn (int $year) => $this->store($employee, $year, $reason));
    }

    /**
     * The year's compulsory days changed: recalculate everyone who has a stored entitlement for it.
     *
     * @return array{updated: int, unchanged: int}
     */
    public function recalculateYear(int $year, string $reason): array
    {
        $counts = ['updated' => 0, 'unchanged' => 0];

        Employee::query()
            ->where('is_active', true)
            ->whereIn('id', LeaveEntitlement::query()->where('year', $year)->select('employee_id'))
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($year, $reason, &$counts) {
                foreach ($employees as $employee) {
                    $this->store($employee, $year, $reason) === 'updated' ? $counts['updated']++ : $counts['unchanged']++;
                }
            });

        return $counts;
    }

    /** @return 'created'|'updated'|'unchanged' */
    protected function store(Employee $employee, int $year, string $reason): string
    {
        $figures = $this->calculator->breakdown($employee, $year);
        $values = [
            'grade_snapshot' => $figures['grade'],
            'tenure_years' => $figures['tenure_years'],
            'gross_days' => $figures['gross'],
            'compulsory_days' => $figures['compulsory'],
            'net_days' => $figures['net'],
        ];

        $row = $this->find($employee, $year);

        if (! $row) {
            LeaveEntitlement::query()->create(['employee_id' => $employee->id, 'year' => $year] + $values);
            app(LeaveBalanceService::class)->syncEntitlement($employee, $year, $figures['net']);

            return 'created';
        }

        $old = $row->only(array_keys($values));

        if ($old === $values) {
            return 'unchanged';
        }

        $row->update($values);
        $balance = app(LeaveBalanceService::class)->syncEntitlement($employee, $year, $figures['net']);

        AuditLog::record(
            'leave_entitlement_recalculated',
            'leave',
            'leave_entitlements',
            $row->id,
            $old,
            $values,
            array_filter([
                'employee_id' => $employee->id,
                'staff_id' => $employee->staff_id,
                'year' => $year,
                'reason' => $reason,
                'balance' => $balance,
            ], fn ($value) => $value !== null)
        );

        return 'updated';
    }
}
