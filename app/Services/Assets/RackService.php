<?php

namespace App\Services\Assets;

use App\Models\AuditLog;
use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctRack;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of ict_racks (Assets > Racks), and the one place that knows whether a device fits in a rack.
 *
 * Reading is `assets.view_racks`, writing `assets.manage_racks` (super_admin passes both). Writing is confined to the
 * actor's own region exactly like writing an asset (the service-level twin of ScopesAssetsByActor::scopeAssetsForActor():
 * a regional or Head Office ICT user only ever changes racks stamped with their own region; Head Office shares its
 * region's id, which is why the region, not the district, is what is compared). A rack outside that scope answers
 * ModelNotFoundException (404), never "forbidden", so another region's rack is not confirmed to exist.
 *
 * Every change re-reads the rack `lockForUpdate` inside a transaction and is judged on that copy, then writes one audit
 * row. A rack that still has devices in it is never deleted, shortened below its highest device, or decommissioned.
 * Unit 1 is the bottom of the rack; a device covers rack_unit .. rack_unit + rack_unit_span - 1.
 */
class RackService
{
    public const VIEW_PERMISSION = 'assets.view_racks';

    public const MANAGE_PERMISSION = 'assets.manage_racks';

    public const DUPLICATE_NAME_MESSAGE = 'A rack with this name already exists.';

    public const DUPLICATE_CODE_MESSAGE = 'A rack with this code already exists.';

    public static function mayView(User $actor): bool
    {
        return $actor->hasRoles('super_admin') || $actor->hasPermission(self::VIEW_PERMISSION);
    }

    public static function mayManage(User $actor): bool
    {
        return $actor->hasRoles('super_admin') || $actor->hasPermission(self::MANAGE_PERMISSION);
    }

    /** Whether $actor's region is the rack's: the write scope. Anyone not ICT-scoped is not limited by region. */
    public static function mayWrite(User $actor, IctRack $rack): bool
    {
        if (! $actor->isScopedIct()) {
            return true;
        }

        $regionId = ($actor->employee ?? $actor->employeeByStaffId)?->region_id;

        return $regionId !== null && (int) $regionId === (int) $rack->region_id;
    }

    /** @param array<string, mixed> $data name, code, region_id, district_id, location, total_units, status, notes */
    public function create(array $data, ?User $actor = null): IctRack
    {
        $actor = $this->manager($actor);

        $data = $this->clean([...['name' => ''], ...$data]);

        if (($data['region_id'] ?? null) === null) {
            throw ValidationException::withMessages(['form.region_id' => 'Your account has no region assigned — contact an administrator before adding racks.']);
        }

        if ($actor->isScopedIct() && (int) $data['region_id'] !== (int) (($actor->employee ?? $actor->employeeByStaffId)?->region_id)) {
            throw ValidationException::withMessages(['form.region_id' => 'You can only add racks in your own region.']);
        }

        $this->checkIdentity($data, null);
        $this->checkDistrict($data['district_id'] ?? null, $data['region_id'] ?? null);
        $this->checkStatusAndHeight($data, null);

        return DB::transaction(function () use ($data) {
            $rack = IctRack::query()->create($data);

            AuditLog::record(
                action: 'create_rack',
                module: Permission::MODULE_ASSETS,
                targetType: 'ict_racks',
                targetId: $rack->id,
                old: null,
                new: $rack->toArray(),
            );

            return $rack;
        });
    }

    /**
     * Change a rack. Its region never changes (it was stamped when it was created); the district must be one of that
     * region. $data may hold only the fields being changed.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(IctRack $rack, array $data, ?User $actor = null): IctRack
    {
        $actor = $this->manager($actor);

        $data = $this->clean($data);
        unset($data['region_id']);

        return DB::transaction(function () use ($rack, $data, $actor) {
            $locked = $this->lockedWritable($rack, $actor);

            $this->checkIdentity($data, $locked);
            $this->checkDistrict(array_key_exists('district_id', $data) ? $data['district_id'] : $locked->district_id, $locked->region_id);
            $this->checkStatusAndHeight($data, $locked);

            $old = $locked->toArray();
            $locked->update($data);
            $locked->refresh();

            AuditLog::record(
                action: 'update_rack',
                module: Permission::MODULE_ASSETS,
                targetType: 'ict_racks',
                targetId: $locked->id,
                old: $old,
                new: $locked->toArray(),
            );

            return $locked;
        });
    }

    /**
     * Delete a rack that holds no device. A device in it is never orphaned: the refusal is a ValidationException on
     * `delete` that says how many, and nothing changes.
     */
    public function delete(IctRack $rack, ?User $actor = null): void
    {
        $actor = $this->manager($actor);

        DB::transaction(function () use ($rack, $actor) {
            $locked = $this->lockedWritable($rack, $actor);

            $count = $this->deviceCount($locked);

            if ($count > 0) {
                throw ValidationException::withMessages([
                    'delete' => "This rack cannot be deleted because {$count} ".($count === 1 ? 'device is' : 'devices are').' mounted in it. Move '.($count === 1 ? 'it' : 'them').' to another rack, or take '.($count === 1 ? 'it' : 'them').' out of this one, first.',
                ]);
            }

            $old = $locked->toArray();
            $locked->delete();

            AuditLog::record(
                action: 'delete_rack',
                module: Permission::MODULE_ASSETS,
                targetType: 'ict_racks',
                targetId: (int) $old['id'],
                old: $old,
                new: null,
            );
        });
    }

    /** How many devices are mounted in a rack, whoever may see them. */
    public function deviceCount(IctRack $rack): int
    {
        return IctAsset::query()->where('rack_id', $rack->id)->count();
    }

    /**
     * Whether a device covering $span U from $start fits in $rack and is clear of every other device in it. The rack
     * row should be locked by the caller when the answer is about to be written. Returns null when it fits, else the
     * form field to blame and a plain sentence that names the device in the way.
     *
     * @return array{field: string, message: string}|null
     */
    public function placementProblem(IctRack $rack, int $start, int $span, ?int $ignoreAssetId = null): ?array
    {
        if ($start < 1) {
            return ['field' => 'form.rack_unit', 'message' => 'The starting U must be 1 or more (1 is the bottom of the rack).'];
        }

        if ($span < 1) {
            return ['field' => 'form.rack_unit_span', 'message' => 'A device takes at least 1 U.'];
        }

        $end = $start + $span - 1;

        if ($end > (int) $rack->total_units) {
            return [
                'field' => 'form.rack_unit',
                'message' => "That does not fit: it would reach U{$end}, and {$rack->name} is only {$rack->total_units}U high.",
            ];
        }

        $others = IctAsset::query()
            ->where('rack_id', $rack->id)
            ->whereNotNull('rack_unit')
            ->when($ignoreAssetId, fn ($query, $id) => $query->whereKeyNot($id))
            ->orderBy('rack_unit')
            ->get(['id', 'asset_name', 'rack_id', 'rack_unit', 'rack_unit_span']);

        foreach ($others as $other) {
            $otherEnd = (int) $other->rack_unit + max(1, (int) $other->rack_unit_span) - 1;

            if ((int) $other->rack_unit <= $end && $otherEnd >= $start) {
                return [
                    'field' => 'form.rack_unit',
                    'message' => IctRack::positionLabel($start, $span).' overlaps '.$other->asset_name.' ('.IctRack::positionLabel((int) $other->rack_unit, (int) $other->rack_unit_span).') in '.$rack->name.'.',
                ];
            }
        }

        return null;
    }

    /** The highest U any device in the rack reaches (0 for an empty rack), and the device that reaches it. */
    protected function highestUsed(IctRack $rack): array
    {
        $highest = 0;
        $by = null;

        foreach (IctAsset::query()->where('rack_id', $rack->id)->whereNotNull('rack_unit')->get(['id', 'asset_name', 'rack_unit', 'rack_unit_span']) as $device) {
            $end = (int) $device->rack_unit + max(1, (int) $device->rack_unit_span) - 1;

            if ($end > $highest) {
                $highest = $end;
                $by = $device->asset_name;
            }
        }

        return [$highest, $by];
    }

    protected function manager(?User $actor): User
    {
        $actor ??= Auth::user();

        if (! $actor || ! self::mayManage($actor)) {
            throw new AuthorizationException('You do not have permission to manage racks.');
        }

        return $actor;
    }

    /** Re-read `lockForUpdate` and judge the write scope on THAT copy; outside it the rack answers 404. */
    protected function lockedWritable(IctRack $rack, User $actor): IctRack
    {
        $locked = IctRack::query()->lockForUpdate()->find($rack->id);

        if (! $locked || ! self::mayWrite($actor, $locked)) {
            throw (new ModelNotFoundException)->setModel(IctRack::class, [$rack->id]);
        }

        return $locked;
    }

    /**
     * Trim the text fields; a blank code, location or note is NULL (a blank code must never reach the unique index as '').
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function clean(array $data): array
    {
        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }

        foreach (['code', 'location', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = trim((string) $data[$key]);
                $data[$key] = $value === '' ? null : $value;
            }
        }

        foreach (['region_id', 'district_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = ($data[$key] === '' || $data[$key] === null) ? null : (int) $data[$key];
            }
        }

        if (array_key_exists('total_units', $data) && is_numeric($data['total_units'])) {
            $data['total_units'] = (int) $data['total_units'];
        }

        return $data;
    }

    /** Name (and code, when given) must not belong to another rack. Compared ignoring case: SQLite's unique index does not. */
    protected function checkIdentity(array $data, ?IctRack $rack): void
    {
        if (array_key_exists('name', $data)) {
            if ($data['name'] === '') {
                throw ValidationException::withMessages(['form.name' => 'Give the rack a name.']);
            }

            $taken = IctRack::query()
                ->whereRaw('LOWER(name) = ?', [IctRack::nameKey($data['name'])])
                ->when($rack, fn ($query) => $query->whereKeyNot($rack->id))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['form.name' => self::DUPLICATE_NAME_MESSAGE]);
            }
        }

        if (filled($data['code'] ?? null)) {
            $taken = IctRack::query()
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($data['code'])])
                ->when($rack, fn ($query) => $query->whereKeyNot($rack->id))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['form.code' => self::DUPLICATE_CODE_MESSAGE]);
            }
        }
    }

    /** The district must be one of the rack's own region (Head Office is a district of its region, so this is the right test). */
    protected function checkDistrict(?int $districtId, ?int $regionId): void
    {
        if ($districtId === null) {
            return;
        }

        $district = District::query()->find($districtId);

        if (! $district || $regionId === null || (int) $district->region_id !== (int) $regionId) {
            throw ValidationException::withMessages(['form.district_id' => 'The selected district is not in this rack\'s region.']);
        }
    }

    /**
     * Status must be one of the known ones and the height a sensible number; and what is mounted in the rack limits both:
     * it cannot be made shorter than its highest device reaches, and cannot be decommissioned while it still holds devices.
     *
     * @param  array<string, mixed>  $data
     */
    protected function checkStatusAndHeight(array $data, ?IctRack $rack): void
    {
        if (array_key_exists('status', $data) && ! in_array($data['status'], IctRack::STATUSES, true)) {
            throw ValidationException::withMessages(['form.status' => 'Pick one of the rack statuses in the list.']);
        }

        if (array_key_exists('total_units', $data)) {
            $units = $data['total_units'];

            if (! is_int($units) || $units < 1 || $units > IctRack::MAX_UNITS) {
                throw ValidationException::withMessages(['form.total_units' => 'The height must be a whole number from 1 to '.IctRack::MAX_UNITS.' U.']);
            }

            if ($rack) {
                [$highest, $by] = $this->highestUsed($rack);

                if ($units < $highest) {
                    throw ValidationException::withMessages([
                        'form.total_units' => "{$by} reaches U{$highest}, so the rack cannot be shorter than {$highest}U. Move it first.",
                    ]);
                }
            }
        }

        if ($rack && ($data['status'] ?? null) === IctRack::STATUS_DECOMMISSIONED && $rack->status !== IctRack::STATUS_DECOMMISSIONED) {
            $count = $this->deviceCount($rack);

            if ($count > 0) {
                throw ValidationException::withMessages([
                    'form.status' => "A rack cannot be decommissioned while {$count} ".($count === 1 ? 'device is' : 'devices are').' mounted in it. Move '.($count === 1 ? 'it' : 'them').' out first.',
                ]);
            }
        }
    }
}
