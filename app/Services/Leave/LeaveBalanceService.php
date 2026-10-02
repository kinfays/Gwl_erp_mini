<?php

namespace App\Services\Leave;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Carbon\Carbon;

class LeaveBalanceService
{
    public function __construct(
        protected LeaveEntitlementService $entitlements,
        protected WorkingDaysCalculator $workingDays,
        protected AnnualEntitlementService $annual,
    ) {}

    /**
     * Returns a "virtual" balance for validation/UI even if no leave_balances row exists.
     */
    public function getVirtualRemaining(Employee $employee, string $leaveType, int $year): int
    {
        // Sick is unlimited
        if ($leaveType === 'Sick') {
            return 9999;
        }

        // Annual is the employee's own entitlement for the year (grade, service, less compulsory leave).
        $entitle = $leaveType === 'Annual'
            ? $this->annual->netFor($employee, $year)
            : $this->entitlements->entitlementDays($leaveType);

        // Approved used days from requests (source of truth before balance row exists)
        $used = $this->approvedDays($employee->id, $leaveType, $year);

        if ($leaveType !== 'Annual') {
            return max(0, $entitle - $used);
        }

        $balance = $this->findBalance($employee->id, 'Annual', $year);

        // Once the year's row exists it also holds Annual days charged without a leave request
        // (compulsory deductions); never count fewer than the approved requests, though.
        $used = max($used, (int) ($balance?->used_days ?? 0));

        // Carry-over is fixed when the year's balance row is opened; until then it is worked out live.
        $carry = $balance ? (int) $balance->carry_over_days : $this->eligibleAnnualCarryOver($employee, $year);

        return max(0, ($entitle + $carry) - $used - $this->forfeitedCarryOver($employee->id, $year, $carry));
    }

    /**
     * Create or fetch a LeaveBalance row (done on FINAL APPROVAL).
     */
    public function getOrCreateForApproval(Employee $employee, string $leaveType, int $year): LeaveBalance
    {
        $balance = $this->findBalance($employee->id, $leaveType, $year);

        if ($balance) {
            return $balance;
        }

        $entitle = $leaveType === 'Annual'
            ? $this->annual->forEmployee($employee, $year, 'balance_opened')->net_days
            : $this->entitlements->entitlementDays($leaveType);

        $carry = 0;
        $carryExpiry = null;

        if ($leaveType === 'Annual') {
            $carry = $this->eligibleAnnualCarryOver($employee, $year);
            $carryExpiry = $this->carryOverExpiry($year)->toDateString();
        }

        $remaining = $entitle + $carry;

        $balance = LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type' => $leaveType,
            'entitle_days' => $entitle,
            'used_days' => 0,
            'remaining_days' => $remaining,
            'carry_over_days' => $carry,
            'carry_over_expired_date' => $carryExpiry,
            'current_year' => $year,
            'region_id' => $employee->region_id,
            'district_id' => $employee->district_id,
        ]);

        // A row opened after the expiry date starts with its unused carry-over already forfeited.
        $this->applyCarryOverForfeiture($balance);

        return $balance;
    }

    /**
     * The year's Annual entitlement changed (grade, service, compulsory days): move the balance to it. Days already
     * taken stay taken: the entitlement never goes below them, so only the remaining days move. Null when there is no
     * balance yet (it will be opened from the entitlement) or nothing changed.
     *
     * @return array{old: array<string, int>, new: array<string, int>, floored: bool}|null
     */
    public function syncEntitlement(Employee $employee, int $year, int $netDays): ?array
    {
        $balance = $this->findBalance($employee->id, 'Annual', $year);

        if (! $balance) {
            return null;
        }

        $entitle = max($netDays, (int) $balance->used_days);

        if ($entitle === (int) $balance->entitle_days) {
            return null;
        }

        $old = ['entitle_days' => (int) $balance->entitle_days, 'remaining_days' => (int) $balance->remaining_days];

        $balance->entitle_days = $entitle;
        $this->applyCarryOverForfeiture($balance);

        return [
            'old' => $old,
            'new' => ['entitle_days' => (int) $balance->entitle_days, 'remaining_days' => (int) $balance->remaining_days],
            'floored' => $entitle > $netDays,
        ];
    }

    /**
     * Deduct days on approval (updates used + remaining).
     */
    public function deduct(LeaveBalance $balance, int $days): void
    {
        if ($balance->leave_type === 'Sick') {
            // unlimited; we can still track used if desired
            $balance->used_days += $days;
            $balance->save();

            return;
        }

        $balance->used_days += $days;

        if ($balance->leave_type === 'Annual') {
            // Leave approved late for dates before the expiry uses carry-over first, so this also
            // shrinks any forfeiture already applied.
            $this->applyCarryOverForfeiture($balance);

            return;
        }

        $balance->remaining_days = max(0, $balance->remaining_days - $days);
        $balance->save();
    }

    /**
     * Forfeits unused carry-over on Annual balances whose expiry date has passed since the last run.
     *
     * @return array{balances: int, days: int}
     */
    public function forfeitExpiredCarryOver(bool $dryRun = false): array
    {
        $summary = ['balances' => 0, 'days' => 0];

        LeaveBalance::query()
            ->where('leave_type', 'Annual')
            ->where('carry_over_days', '>', 0)
            ->whereNull('carry_over_forfeited_at')
            ->where('current_year', '<=', now()->year)
            ->chunkById(200, function ($balances) use ($dryRun, &$summary) {
                foreach ($balances as $balance) {
                    $year = (int) $balance->current_year;

                    if (! $this->carryOverHasExpired($year)) {
                        continue;
                    }

                    $newlyForfeited = $dryRun
                        ? $this->forfeitedCarryOver((int) $balance->employee_id, $year, (int) $balance->carry_over_days) - (int) $balance->carry_over_forfeited_days
                        : $this->applyCarryOverForfeiture($balance);

                    if ($newlyForfeited > 0) {
                        $summary['balances']++;
                        $summary['days'] += $newlyForfeited;
                    }
                }
            });

        return $summary;
    }

    /**
     * First day on which carry-over into $year can no longer be used.
     */
    public function carryOverExpiry(int $year): Carbon
    {
        return Carbon::create($year, 1, 1)->addDays((int) config('gwl.carry_over_expiry_days', 90));
    }

    /**
     * Carry-over is used first by Annual leave taken before it expires; whatever is still unused on
     * the expiry date is forfeited. Keeps the stored row in step with getVirtualRemaining() and
     * returns the change in forfeited days.
     */
    protected function applyCarryOverForfeiture(LeaveBalance $balance): int
    {
        if ($balance->leave_type !== 'Annual') {
            return 0;
        }

        $year = (int) $balance->current_year;
        $oldRemaining = (int) $balance->getOriginal('remaining_days');
        $oldForfeited = (int) $balance->getOriginal('carry_over_forfeited_days');
        $forfeited = $this->forfeitedCarryOver((int) $balance->employee_id, $year, (int) $balance->carry_over_days);

        $balance->carry_over_forfeited_days = $forfeited;
        $balance->remaining_days = max(0, (int) $balance->entitle_days + (int) $balance->carry_over_days - (int) $balance->used_days - $forfeited);

        if (! $balance->carry_over_forfeited_at && $this->carryOverHasExpired($year)) {
            $balance->carry_over_forfeited_at = now();
        }

        $balance->save();

        if ($forfeited === $oldForfeited) {
            return 0;
        }

        AuditLog::record(
            'leave_carry_over_forfeited',
            'leave',
            'leave_balances',
            $balance->id,
            ['remaining_days' => $oldRemaining, 'carry_over_forfeited_days' => $oldForfeited],
            ['remaining_days' => (int) $balance->remaining_days, 'carry_over_forfeited_days' => $forfeited],
            [
                'employee_id' => (int) $balance->employee_id,
                'year' => $year,
                'carry_over_days' => (int) $balance->carry_over_days,
                'expired_on' => $this->carryOverExpiry($year)->toDateString(),
            ]
        );

        return $forfeited - $oldForfeited;
    }

    protected function forfeitedCarryOver(int $employeeId, int $year, int $carry): int
    {
        if ($carry <= 0 || ! $this->carryOverHasExpired($year)) {
            return 0;
        }

        return max(0, $carry - $this->annualDaysBeforeExpiry($employeeId, $year));
    }

    protected function carryOverHasExpired(int $year): bool
    {
        return now()->greaterThan($this->carryOverExpiry($year));
    }

    /**
     * Approved Annual days in $year dated before the carry-over expiry; a request spanning the expiry
     * only counts its working days before it. Compulsory deductions are charged for December/January,
     * always after the expiry, so they never use carry-over.
     */
    protected function annualDaysBeforeExpiry(int $employeeId, int $year): int
    {
        $expiry = $this->carryOverExpiry($year);
        $lastCarryOverDay = $expiry->copy()->subDay();

        return (int) LeaveRequest::query()
            ->where('requester_id', $employeeId)
            ->where('leave_type', 'Annual')
            ->where('leave_status', 'Approved')
            ->where('request_year', $year)
            ->whereDate('start_date', '<', $expiry->toDateString())
            ->get(['start_date', 'end_date', 'total_days_applied'])
            ->sum(fn (LeaveRequest $request) => $request->end_date->lt($expiry)
                ? (int) $request->total_days_applied
                : min((int) $request->total_days_applied, $this->workingDays->workingDays($request->start_date, $lastCarryOverDay)));
    }

    /**
     * Eligible carry over = previous year's unused Annual leave, net of the carry-over that year
     * already forfeited. Without a previous balance row, fall back to that year's approved requests
     * (its own carry-over has long lapsed).
     */
    protected function eligibleAnnualCarryOver(Employee $employee, int $year): int
    {
        $prevYear = $year - 1;
        $prev = $this->findBalance($employee->id, 'Annual', $prevYear);

        if ($prev) {
            $forfeited = $this->forfeitedCarryOver($employee->id, $prevYear, (int) $prev->carry_over_days);

            return max(0, (int) $prev->entitle_days + (int) $prev->carry_over_days - (int) $prev->used_days - $forfeited);
        }

        return max(0, $this->annual->netFor($employee, $prevYear) - $this->approvedDays($employee->id, 'Annual', $prevYear));
    }

    protected function approvedDays(int $employeeId, string $leaveType, int $year): int
    {
        return (int) LeaveRequest::query()
            ->where('requester_id', $employeeId)
            ->where('leave_type', $leaveType)
            ->where('leave_status', 'Approved')
            ->where('request_year', $year)
            ->sum('total_days_applied');
    }

    protected function findBalance(int $employeeId, string $leaveType, int $year): ?LeaveBalance
    {
        return LeaveBalance::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type', $leaveType)
            ->where('current_year', $year)
            ->first();
    }
}
