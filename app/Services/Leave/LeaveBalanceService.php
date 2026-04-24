<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Carbon\Carbon;

class LeaveBalanceService
{
    public function __construct(
        protected LeaveEntitlementService $entitlements
    ) {}

    /**
     * Returns a "virtual" balance for validation/UI even if no leave_balances row exists.
     */
    public function getVirtualRemaining(Employee $employee, string $leaveType, int $year): int
    {
        $entitle = $this->entitlements->entitlementDays($leaveType);

        // Sick is unlimited
        if ($leaveType === 'Sick') {
            return 9999;
        }

        // Approved used days from requests (source of truth before balance row exists)
        $used = LeaveRequest::query()
            ->where('requester_id', $employee->id)
            ->where('leave_type', $leaveType)
            ->where('leave_status', 'Approved')
            ->where('request_year', $year)
            ->sum('total_days_applied');

        $carry = ($leaveType === 'Annual') ? $this->eligibleAnnualCarryOver($employee, $year) : 0;

        return max(0, ($entitle + $carry) - $used);
    }

    /**
     * Create or fetch a LeaveBalance row (done on FINAL APPROVAL).
     */
    public function getOrCreateForApproval(Employee $employee, string $leaveType, int $year): LeaveBalance
    {
        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type', $leaveType)
            ->where('current_year', $year)
            ->first();

        if ($balance) {
            return $balance;
        }

        $entitle = $this->entitlements->entitlementDays($leaveType);

        $carry = 0;
        $carryExpiry = null;

        if ($leaveType === 'Annual') {
            $carry = $this->eligibleAnnualCarryOver($employee, $year);
            $carryExpiry = Carbon::create($year, 1, 1)->addDays(90)->toDateString();
        }

        $remaining = $entitle + $carry;

        return LeaveBalance::create([
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
        $balance->remaining_days = max(0, $balance->remaining_days - $days);
        $balance->save();
    }

    /**
     * Eligible carry over = previous year's remaining Annual leave if not expired.
     * (Uses previous year's balance row if exists; otherwise computes from approved requests.)
     */
    protected function eligibleAnnualCarryOver(Employee $employee, int $year): int
    {
        $prevYear = $year - 1;
        $expiry = Carbon::create($year, 1, 1)->addDays(90);

        // If today is after expiry, no carry
        if (now()->greaterThan($expiry)) {
            return 0;
        }

        // Prefer previous balance row if it exists
        $prev = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type', 'Annual')
            ->where('current_year', $prevYear)
            ->first();

        if ($prev) {
            return max(0, (int) $prev->remaining_days);
        }

        // Otherwise compute virtual remaining for previous year
        return $this->getVirtualRemaining($employee, 'Annual', $prevYear);
    }
}
