<?php

namespace App\Services\Assets\Mdm;

use App\Models\IctAsset;
use App\Models\MdmDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and act on which phone. This is the service-level twin of the Livewire trait
 * App\Livewire\Assets\Concerns\ScopesAssetsByActor, with identical semantics, because commands run in queued
 * jobs where there is no Auth::user() to read:
 *
 *   - super_admin / admin: every region.
 *   - ict_team without admin/super_admin ("region-scoped ICT"): only phones whose ASSET is in the region of
 *     their own employee record. No employee/region on file means nothing at all.
 *   - devices with no linked asset (unverified enrollments) belong to nobody's region, so only the unscoped
 *     roles can see them.
 *
 * Never trust a device or asset id that came from a client: resolve it through devices() / assets() (or
 * deviceOrFail / assetOrFail) so a tampered id is a 404 instead of someone else's phone.
 */
class MdmAccessGuard
{
    public function isOperator(User $user): bool
    {
        return $user->hasRoles(User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_ICT_TEAM);
    }

    public function isRegionScopedIct(User $user): bool
    {
        return $user->hasRoles(User::ROLE_ICT_TEAM)
            && ! $user->hasRoles(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);
    }

    public function regionId(User $user): ?int
    {
        $employee = $user->employee ?? $user->employeeByStaffId;

        return $employee?->region_id;
    }

    /** super_admin passes every check; everyone else needs the permission row. */
    public function has(User $user, string $permission): bool
    {
        return $user->hasRoles(User::ROLE_SUPER_ADMIN) || $user->hasPermission($permission);
    }

    /** Only the unscoped roles may clear an identity-review flag. */
    public function canReview(User $user): bool
    {
        return $this->isOperator($user)
            && ! $this->isRegionScopedIct($user)
            && $this->has($user, 'assets.mdm_command');
    }

    /** IctAsset rows this user may see. */
    public function assets(User $user): Builder
    {
        $query = IctAsset::query();

        if (! $this->isOperator($user)) {
            return $query->whereRaw('1 = 0');
        }

        if (! $this->isRegionScopedIct($user)) {
            return $query;
        }

        $regionId = $this->regionId($user);

        return $regionId ? $query->where('region_id', $regionId) : $query->whereRaw('1 = 0');
    }

    /** MdmDevice rows this user may see (deleted devices included; filter with notDeleted() where relevant). */
    public function devices(User $user): Builder
    {
        $query = MdmDevice::query();

        if (! $this->isOperator($user)) {
            return $query->whereRaw('1 = 0');
        }

        if (! $this->isRegionScopedIct($user)) {
            return $query;
        }

        $regionId = $this->regionId($user);

        return $regionId
            ? $query->whereHas('asset', fn (Builder $asset) => $asset->where('region_id', $regionId))
            : $query->whereRaw('1 = 0');
    }

    /**
     * Phones that can be enrolled right now: an in-scope, Active handset (asset type Ph — not POS terminals or SIM
     * cards) that has no live MDM device and carries a serial number or IMEI to verify the enrolling phone against.
     */
    public function enrollableAssets(User $user): Builder
    {
        return $this->assets($user)
            ->where('device_category', IctAsset::DEVICE_CATEGORY_PHONE)
            ->where('asset_type', 'Ph')
            ->where('status', IctAsset::STATUS_ACTIVE)
            ->whereDoesntHave('mdmDevice', fn (Builder $device) => $device->notDeleted())
            ->where(function (Builder $identity) {
                $identity->where(fn (Builder $q) => $q->whereNotNull('serial_number')->where('serial_number', '!=', ''))
                    ->orWhere(fn (Builder $q) => $q->whereNotNull('imei')->where('imei', '!=', ''));
            });
    }

    public function canAccessAsset(User $user, IctAsset $asset): bool
    {
        return $this->assets($user)->whereKey($asset->getKey())->exists();
    }

    public function canAccessDevice(User $user, MdmDevice $device): bool
    {
        return $this->devices($user)->whereKey($device->getKey())->exists();
    }

    public function deviceOrFail(User $user, int $deviceId): MdmDevice
    {
        return $this->devices($user)->findOrFail($deviceId);
    }
}
