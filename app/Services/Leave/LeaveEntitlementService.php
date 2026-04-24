<?php

namespace App\Services\Leave;

class LeaveEntitlementService
{
    public function entitlementDays(string $leaveType): int
    {
        return match ($leaveType) {
            'Annual' => 31,
            'Casual' => 5,
            'Paternity' => 7,
            'Maternity' => 93,
            'Sick' => 9999, // unlimited
            default => 0,
        };
    }
}