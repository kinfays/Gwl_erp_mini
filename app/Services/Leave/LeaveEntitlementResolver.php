<?php

namespace App\Services\Leave;

use App\Enums\LeaveType;
use App\Models\Employee;
use App\Models\LeaveBalance;
use Carbon\Carbon;

class LeaveEntitlementResolver
{
    /**
     * Get base entitlement days per leave type.
     */
    public function baseEntitlement(LeaveType $leaveType): int
    {
        return match ($leaveType) {
            LeaveType::Annual    => 31,
            LeaveType::Casual    => 5,
            LeaveType::Paternity => 7,
            LeaveType::Maternity => 93,
            LeaveType::Sick      => 9999,
        };
    }

    /**
     * Resolve entitlement and carry-over data for an employee & leave type.
     *
     * This does NOT create a leave balance.
     * It only calculates what should be used if/when a balance is created.
     */
    public function resolve(Employee $employee, LeaveType $leaveType, int $year): array
    {
        $entitleDays = $this->baseEntitlement($leaveType);

        $carryOverDays = 0;
        $carryOverExpiry = null;

        // Carry-over applies ONLY to Annual leave
        if ($leaveType === LeaveType::Annual) {
            [$carryOverDays, $carryOverExpiry] = $this->resolveAnnualCarryOver(
                $employee,
                $year
            );
        }

        return [
            'entitle_days' => $entitleDays,
            'carry_over_days' => $carryOverDays,
            'carry_over_expired_date' => $carryOverExpiry,
            'total_available_days' => $entitleDays + $carryOverDays,
        ];
    }

    /**
     * Resolve Annual Leave carry-over from previous year.
     */
    protected function resolveAnnualCarryOver(Employee $employee, int $year): array
    {
        $previousYear = $year - 1;

        $previousBalance = LeaveBalance::where('staff_id', $employee->id)
            ->where('leave_type', LeaveType::Annual)
            ->where('current_year', $previousYear)
            ->first();

        if (! $previousBalance) {
            return [0, null];
        }

        if ($previousBalance->remaining_days <= 0) {
            return [0, null];
        }

        // Carry-over expiry: Jan 1 + 90 days of the new year
        $expiryDate = Carbon::create($year, 1, 1)->addDays(90);

        if (now()->greaterThan($expiryDate)) {
            return [0, $expiryDate];
        }

        return [
            $previousBalance->remaining_days,
            $expiryDate,
        ];
    }
}