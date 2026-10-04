<?php

namespace App\Services\Assets;

use App\Models\IctAssetAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which audits a user may open. Same reading rule as the asset lists: super_admin, Global Admin and Head Office ICT
 * see every audit; a regional ICT user only sees audits scoped to their own region (their audits are forced to it).
 */
class AuditVisibility
{
    public static function seesAll(User $user): bool
    {
        return ! $user->isScopedIct() || ($user->ictScope()['type'] ?? null) === 'head_office';
    }

    public static function regionId(User $user): ?int
    {
        return ($user->employee ?? $user->employeeByStaffId)?->region_id;
    }

    public static function canSee(User $user, IctAssetAudit $audit): bool
    {
        return self::seesAll($user) || ($audit->scope_region_id !== null && (int) $audit->scope_region_id === (int) self::regionId($user));
    }

    public static function scope(Builder $query, User $user): Builder
    {
        if (self::seesAll($user)) {
            return $query;
        }

        $region = self::regionId($user);

        return $region ? $query->where('scope_region_id', $region) : $query->whereRaw('1 = 0');
    }
}
