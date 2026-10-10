<?php

namespace App\Services\Commercial\Sms;

use App\Models\CommercialSmsCampaign;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * THE region lock of the SMS feature, and the only place that decides who may use it. Every screen, action, queued job, command
 * and webhook goes through here; the audience queries add the region condition themselves (SmsAudienceResolver) and take the
 * region ids from here as well, so the UI is never the only guard.
 *
 *  - The feature is on only when GWL_COMMERCIAL_SMS_ENABLED, the module AND the customer list are on.
 *  - Only customers of the regions named in gwl.commercial_sms_region_names (default ACCRA WEST) can be messaged. The names are
 *    matched to region ids at run time; no id is hard-coded.
 *  - Only super_admin and staff whose employee record is in one of those regions can use it. Head Office is a DISTRICT whose
 *    staff share a region_id, so a region id alone proves nothing: location_type 'HeadOffice' is never let in (the rule of
 *    ScopesCommercialByActor / CustomerScope, which is why Global Admin is not let in either unless they work in the region).
 *  - Holding the permission is checked here too (allows()), so one call answers "may this person do this".
 *
 * Jobs have no Auth::user(): they call creatorMay() with the campaign, which re-checks the person who created it.
 */
final class SmsAccess
{
    public const SEND = 'commercial.send_sms';

    public const BALANCE = 'commercial.view_sms_balance';

    public const REPORTS = 'commercial.view_sms_reports';

    public const SETTINGS = 'commercial.manage_sms_settings';

    public static function enabled(): bool
    {
        return (bool) config('gwl.commercial_module_enabled')
            && (bool) config('gwl.commercial_customer_list_enabled')
            && (bool) config('gwl.commercial_sms_enabled');
    }

    /** @return list<int> ids of the regions the feature is locked to (empty if none of the names exists) */
    public static function regionIds(): array
    {
        $names = array_values(array_unique(array_map('mb_strtoupper', array_filter(array_map('trim', (array) config('gwl.commercial_sms_region_names', []))))));

        if ($names === []) {
            return [];
        }

        return Region::query()->get(['id', 'region_name'])
            ->filter(fn (Region $region) => in_array(mb_strtoupper(trim((string) $region->region_name)), $names, true))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** The region the person works in, when that region is one the feature is locked to and they do not work at Head Office. */
    protected static function ownRegionId(?User $user): ?int
    {
        $employee = $user?->employee ?? $user?->employeeByStaffId;

        if (! $employee || $employee->region_id === null || $employee->location_type === 'HeadOffice') {
            return null;
        }

        return in_array((int) $employee->region_id, self::regionIds(), true) ? (int) $employee->region_id : null;
    }

    public static function userMay(?User $user): bool
    {
        if (! $user || ! self::enabled()) {
            return false;
        }

        return $user->hasRoles('super_admin') || self::ownRegionId($user) !== null;
    }

    /** The feature is on, the person belongs to the locked region (or is super_admin) and holds the permission. */
    public static function allows(?User $user, string $permission): bool
    {
        if (! self::userMay($user)) {
            return false;
        }

        return $user->hasRoles('super_admin') || $user->hasPermission($permission);
    }

    /** 404 with the flag off (the routes do not exist then either), 403 for anyone outside the lock or without the permission. */
    public static function authorize(?User $user, string ...$permissions): void
    {
        abort_unless(self::enabled(), 404);

        foreach ($permissions as $permission) {
            if (self::allows($user, $permission)) {
                return;
            }
        }

        abort(403, 'You do not have permission to use SMS.');
    }

    /** Region ids the person may work with: their own (staff) or every locked region (super_admin); none otherwise. */
    public static function allowedRegionIds(?User $user): array
    {
        if (! self::userMay($user)) {
            return [];
        }

        return $user->hasRoles('super_admin') ? self::regionIds() : array_values(array_filter([self::ownRegionId($user)]));
    }

    /** The region a new campaign of this person belongs to (their own; for super_admin the first locked region unless asked). */
    public static function campaignRegionId(?User $user, ?int $requested = null): ?int
    {
        $allowed = self::allowedRegionIds($user);

        if ($allowed === []) {
            return null;
        }

        return $requested !== null && in_array($requested, $allowed, true) ? $requested : $allowed[0];
    }

    public static function canSeeRegion(?User $user, ?int $regionId): bool
    {
        return $regionId !== null && in_array($regionId, self::allowedRegionIds($user), true);
    }

    /** Campaigns the person may see: inside the locked regions AND their own region. */
    public static function scopeCampaigns(Builder $query, ?User $user): Builder
    {
        $allowed = self::allowedRegionIds($user);

        return $allowed === [] ? $query->whereRaw('1 = 0') : $query->whereIn($query->qualifyColumn('region_id'), $allowed);
    }

    /** A campaign of the person's scope, or 404 (never 403: a campaign of another region must not be confirmed to exist). */
    public static function findCampaign(int $id, ?User $user): CommercialSmsCampaign
    {
        return self::scopeCampaigns(CommercialSmsCampaign::query(), $user)->whereKey($id)->firstOrFail();
    }

    /** Scope for any model with a region_id (templates). */
    public static function scopeRegion(Builder $query, ?User $user): Builder
    {
        return self::scopeCampaigns($query, $user);
    }

    /**
     * Inside a queued job: may this campaign still run? The region must still be locked-in and the person who created it must
     * still be allowed to send (they may have been moved or lost the role since). A campaign without a creator (the user was
     * deleted) does not run.
     */
    public static function campaignMayRun(CommercialSmsCampaign $campaign): bool
    {
        if (! self::enabled() || ! in_array((int) $campaign->region_id, self::regionIds(), true)) {
            return false;
        }

        $creator = $campaign->creator;

        return $creator !== null && self::allows($creator, self::SEND) && self::canSeeRegion($creator, (int) $campaign->region_id);
    }

    /**
     * The region ids an audience may be drawn from for this campaign: only its own region, and only while it is locked-in.
     * The audience queries use this for the region condition, whoever calls them.
     *
     * @return list<int>
     */
    public static function audienceRegionIds(CommercialSmsCampaign|int $campaign): array
    {
        $regionId = $campaign instanceof CommercialSmsCampaign ? (int) $campaign->region_id : $campaign;

        return in_array($regionId, self::regionIds(), true) ? [$regionId] : [];
    }

    /** Ids of the officers (and super_admins) of the locked regions who should be told about the balance. @return list<int> */
    public static function balanceRecipientIds(int $regionId): array
    {
        if (! in_array($regionId, self::regionIds(), true)) {
            return [];
        }

        return User::query()->get()->filter(
            fn (User $user) => $user->hasRoles('super_admin') || (self::allows($user, self::BALANCE) && self::canSeeRegion($user, $regionId))
        )->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
