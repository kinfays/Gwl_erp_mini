<?php

namespace App\Services\Commercial\Customers;

use App\Models\User;
use App\Services\Commercial\CommercialScope;

/**
 * A thin wrapper of CommercialScope (Phase 8a) for the customer list code that has no Auth::user() (queued jobs, console
 * commands): who may touch which region's customers, and, since 8a, which districts. The rule itself is CommercialScope's; a test
 * pins the Livewire trait to it.
 *
 * super_admin, Global Admin and Head Office staff see every region; holders of commercial.view_all_districts their own region; anyone
 * else only the district on their employee record; a user with no employee record or region sees nothing.
 */
final class CustomerScope
{
    /** null = every region; 0 = no region at all; otherwise the one region id. */
    public static function regionRestriction(?User $user): ?int
    {
        return CommercialScope::for($user)->regionRestriction();
    }

    /** null = no district limit; a list (possibly empty) = only these districts. @return list<int>|null */
    public static function districtIds(?User $user): ?array
    {
        return CommercialScope::for($user)->districtIds();
    }

    public static function canSeeDistrict(?User $user, ?int $districtId, ?int $regionId = null): bool
    {
        return CommercialScope::for($user)->seesDistrict($districtId, $regionId);
    }

    public static function canSeeRegion(?User $user, ?int $regionId): bool
    {
        return CommercialScope::for($user)->seesRegion($regionId);
    }
}
