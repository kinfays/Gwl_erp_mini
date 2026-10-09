<?php

namespace App\Services\Commercial\Customers;

use App\Models\User;

/**
 * The service-level twin of Livewire\Commercial\Concerns\ScopesCommercialByActor for code that has no Auth::user() (queued
 * jobs, console commands): who may touch which region's customers. A test pins the two together.
 *
 * super_admin, Global Admin and Head Office staff see every region; everyone else their own region; a user with no
 * employee record or region sees nothing.
 */
final class CustomerScope
{
    /** null = every region; 0 = no region at all; otherwise the one region id. */
    public static function regionRestriction(?User $user): ?int
    {
        if (! $user) {
            return 0;
        }

        $employee = $user->employee ?? $user->employeeByStaffId;

        if ($user->hasRoles('super_admin', 'admin') || $employee?->location_type === 'HeadOffice') {
            return null;
        }

        return $employee?->region_id !== null ? (int) $employee->region_id : 0;
    }

    public static function canSeeRegion(?User $user, ?int $regionId): bool
    {
        $restriction = self::regionRestriction($user);

        return $restriction === null || ($regionId !== null && $restriction !== 0 && $restriction === $regionId);
    }
}
