<?php

namespace App\Services\Assets;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetAssignment;
use App\Models\IctAssetTransfer;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * THE only place the holder of a device is set or cleared, and the only place "previous assigned to" can come from.
 * Every form, the audit-correction apply, the demo seeder and any future import or bulk action call this; nothing else
 * writes ict_assets.assigned_to_employee_id or ict_asset_assignments (the old previous_assigned_to_employee_id column
 * is no longer written or read, see the backfill migration).
 *
 *  - assign():    an unassigned device gets its first/next holder. Never creates a "previous" entry.
 *  - reassign():  a real handover. The old holder's row closes as "reassigned" and becomes the previous holder.
 *  - correct():   the holder was picked by mistake. The wrong row closes as "corrected" (never a previous holder) and the
 *                 right person inherits the original assigned_at.
 *  - unassign():  the holder is cleared. The row closes as "unassigned" and the person becomes the previous holder.
 *  - change():    what a save does: picks one of the above from the asset's current holder and the new one. A holder
 *                 swapped for another needs $kind (reassigned | correction) and is refused without it.
 *
 * Each action re-reads the asset lockForUpdate inside one transaction, authorises on that copy (permission + the same
 * own-region rule as scopeAssetsForActor()) and only then writes. The ict_assets column is the fast "who holds it now"
 * (dashboards, filters and exports read it); the open history row must agree with it, and a device whose column was
 * written some other way (older rows, direct fixtures) is reconciled into a row before any change.
 */
class AssetAssignmentService
{
    public const KIND_REASSIGNED = 'reassigned';
    public const KIND_CORRECTION = 'correction';

    public const KINDS = [self::KIND_REASSIGNED, self::KIND_CORRECTION];

    /** The form field a validation message about the person is shown against. */
    public const PERSON_KEY = 'form.assigned_to_employee_id';

    /** The form field a validation message about the kind of change is shown against. */
    public const KIND_KEY = 'assignmentChange';

    public function __construct(protected AssetTransferService $transfers) {}

    /** $newDevice: the device is being created with its first holder, which is not a "change" worth a transfer line. */
    public function assign(IctAsset $asset, int $employeeId, ?User $actor = null, bool $newDevice = false): IctAsset
    {
        return $this->run($asset, $actor, function (IctAsset $locked, User $user) use ($employeeId, $newDevice) {
            if ($locked->assigned_to_employee_id) {
                throw ValidationException::withMessages([self::PERSON_KEY => 'This device already has a holder. Reassign it or correct the entry instead.']);
            }

            $this->guardCanHold($locked, $user, $employeeId);
            $this->open($locked, $employeeId, now(), $user);
            $this->finish($locked, null, $employeeId, 'assign', $user, null, null, ! $newDevice);
        });
    }

    public function reassign(IctAsset $asset, int $employeeId, ?User $actor = null): IctAsset
    {
        return $this->run($asset, $actor, function (IctAsset $locked, User $user) use ($employeeId) {
            $from = $this->requireHolder($locked, $employeeId);
            $this->guardCanHold($locked, $user, $employeeId);

            $this->close($this->openRow($locked), IctAssetAssignment::END_REASSIGNED, $user);
            $this->open($locked, $employeeId, now(), $user);
            $this->finish($locked, $from, $employeeId, 'reassign', $user, null, IctAssetAssignment::END_REASSIGNED);
        });
    }

    public function correct(IctAsset $asset, int $employeeId, ?User $actor = null): IctAsset
    {
        return $this->run($asset, $actor, function (IctAsset $locked, User $user) use ($employeeId) {
            $from = $this->requireHolder($locked, $employeeId);
            $this->guardCanHold($locked, $user, $employeeId);

            $wrong = $this->openRow($locked);
            $this->close($wrong, IctAssetAssignment::END_CORRECTED, $user);
            // The right person has held it since the day the wrong one was (wrongly) recorded.
            $this->open($locked, $employeeId, $wrong->assigned_at, $user);
            $this->finish($locked, $from, $employeeId, 'correct', $user, 'Correction', IctAssetAssignment::END_CORRECTED);
        });
    }

    public function unassign(IctAsset $asset, ?User $actor = null): IctAsset
    {
        return $this->run($asset, $actor, function (IctAsset $locked, User $user) {
            $from = $locked->assigned_to_employee_id;

            if (! $from) {
                return;
            }

            $this->close($this->openRow($locked), IctAssetAssignment::END_UNASSIGNED, $user);
            $this->finish($locked, $from, null, 'unassign', $user, null, IctAssetAssignment::END_UNASSIGNED);
        });
    }

    /**
     * What a save of a device does with the holder it was sent: nothing when it is the same, unassign when it is empty,
     * assign when the device had nobody, otherwise a reassign or a correction as $kind says. Looked at on the locked copy,
     * so a holder changed by someone else in the meantime is judged against what is really there now.
     */
    public function change(IctAsset $asset, ?int $employeeId, ?string $kind = null, ?User $actor = null): IctAsset
    {
        $employeeId = $employeeId ?: null;

        return $this->run($asset, $actor, function (IctAsset $locked, User $user) use ($employeeId, $kind) {
            $current = $locked->assigned_to_employee_id ? (int) $locked->assigned_to_employee_id : null;

            if ($current === $employeeId) {
                return;
            }

            if ($employeeId === null) {
                $this->unassign($locked, $user);
            } elseif ($current === null) {
                $this->assign($locked, $employeeId, $user);
            } elseif ($kind === self::KIND_CORRECTION) {
                $this->correct($locked, $employeeId, $user);
            } elseif ($kind === self::KIND_REASSIGNED) {
                $this->reassign($locked, $employeeId, $user);
            } else {
                throw ValidationException::withMessages([self::KIND_KEY => 'Say whether this is a handover to a new person or a correction of a mistake.']);
            }
        });
    }

    /**
     * Mark a PAST row (a handover or an unassign already recorded) as a correction: the person it names was entered by
     * mistake and must stop appearing as "previous assigned to". Only for holders of assets.correct_assignments
     * (super_admin has every permission), with a reason code from CORRECTION_CODES, and only for a closed row; fixing
     * the current holder is done with correct().
     */
    public function markRowCorrected(IctAssetAssignment $row, string $code, ?User $actor = null): IctAssetAssignment
    {
        $user = $this->actor($actor);

        if (! $user->hasRoles('super_admin') && ! $user->hasPermission('assets.correct_assignments')) {
            abort(403, 'You do not have permission to correct assignment history.');
        }

        if (! array_key_exists($code, IctAssetAssignment::CORRECTION_CODES)) {
            throw ValidationException::withMessages(['correctionCode' => 'Choose a reason from the list.']);
        }

        return DB::transaction(function () use ($row, $code, $user) {
            $asset = IctAsset::query()->lockForUpdate()->find($row->ict_asset_id);
            abort_unless($asset, 404);
            $this->authorizeOn($asset, $user);

            $locked = IctAssetAssignment::query()->lockForUpdate()->where('ict_asset_id', $asset->id)->find($row->id);
            abort_unless($locked, 404);

            if (! in_array($locked->end_reason, IctAssetAssignment::PREVIOUS_REASONS, true)) {
                throw ValidationException::withMessages(['correctionCode' => 'Only a past handover or unassign can be marked as a correction.']);
            }

            $before = $locked->end_reason;
            $locked->forceFill([
                'end_reason' => IctAssetAssignment::END_CORRECTED,
                'correction_code' => $code,
                'ended_by_user_id' => $user->id,
            ])->save();

            $this->audit('correct_history_row', $asset, $user, [
                'assignment_id' => $locked->id,
                'employee_id' => $locked->employee_id,
                'end_reason_from' => $before,
                'end_reason_to' => IctAssetAssignment::END_CORRECTED,
                'reason_code' => $code,
            ]);

            return $locked;
        });
    }

    /** Runs $work on the freshly locked asset after authorising on it, inside one transaction; returns the asset reloaded. */
    protected function run(IctAsset $asset, ?User $actor, callable $work): IctAsset
    {
        $user = $this->actor($actor);

        return DB::transaction(function () use ($asset, $user, $work) {
            $locked = IctAsset::query()->lockForUpdate()->find($asset->getKey());
            abort_unless($locked, 404);

            $this->authorizeOn($locked, $user);
            $this->reconcile($locked, $user);
            $work($locked, $user);

            return $locked->refresh();
        });
    }

    protected function actor(?User $actor): User
    {
        $user = $actor ?? Auth::user();

        // Nobody acting, nothing written (the same rule as the Transport service).
        abort_unless($user instanceof User, 403, 'Sign in to change who holds a device.');

        return $user;
    }

    /** Permission to write assets plus the own-region rule of ScopesAssetsByActor::scopeAssetsForActor(). */
    protected function authorizeOn(IctAsset $asset, User $user): void
    {
        $mayWrite = $user->hasRoles('super_admin')
            || collect(['assets.create', 'assets.edit', 'assets.manage_audits'])->contains(fn (string $permission) => $user->hasPermission($permission));

        abort_unless($mayWrite, 403, 'You do not have permission to perform this action.');

        if ($user->isScopedIct()) {
            $regionId = $this->regionOf($user);

            abort_unless($regionId !== null && (int) $asset->region_id === $regionId, 404);
        }
    }

    protected function regionOf(User $user): ?int
    {
        return ($user->employee ?? $user->employeeByStaffId)?->region_id;
    }

    /**
     * The person must be a real, ACTIVE employee, and for a regional ICT user from their own region (the pool the
     * "Assigned To" picker offers). A general-use device has no holder at all.
     */
    protected function guardCanHold(IctAsset $asset, User $user, int $employeeId): void
    {
        if ($asset->is_general_use) {
            throw ValidationException::withMessages([self::PERSON_KEY => 'A general-use device is shared and has no holder.']);
        }

        $employee = Employee::query()->find($employeeId);

        if (! $employee) {
            throw ValidationException::withMessages([self::PERSON_KEY => 'The selected person could not be found.']);
        }

        if (! $employee->is_active) {
            throw ValidationException::withMessages([self::PERSON_KEY => 'This person is no longer active and cannot be given a device.']);
        }

        if ($user->isScopedIct() && (int) $employee->region_id !== $this->regionOf($user)) {
            throw ValidationException::withMessages([self::PERSON_KEY => 'This person is outside your region.']);
        }
    }

    protected function requireHolder(IctAsset $asset, int $newEmployeeId): int
    {
        $from = (int) $asset->assigned_to_employee_id;

        if (! $from) {
            throw ValidationException::withMessages([self::PERSON_KEY => 'This device has no holder to hand over from. Assign it instead.']);
        }

        if ($from === $newEmployeeId) {
            throw ValidationException::withMessages([self::PERSON_KEY => 'This person already holds the device.']);
        }

        return $from;
    }

    protected function openRow(IctAsset $asset): IctAssetAssignment
    {
        return IctAssetAssignment::query()
            ->where('ict_asset_id', $asset->id)
            ->current()
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function close(IctAssetAssignment $row, string $reason, User $user): void
    {
        $row->forceFill([
            'ended_at' => now(),
            'end_reason' => $reason,
            'is_current' => null,
            'ended_by_user_id' => $user->id,
        ])->save();
    }

    protected function open(IctAsset $asset, int $employeeId, $assignedAt, User $user): IctAssetAssignment
    {
        return IctAssetAssignment::query()->create([
            'ict_asset_id' => $asset->id,
            'employee_id' => $employeeId,
            'assigned_at' => $assignedAt,
            'is_current' => true,
            'recorded_by_user_id' => $user->id,
        ]);
    }

    /** Stores the new holder on the asset, writes the transfer line and the audit row. */
    protected function finish(IctAsset $asset, ?int $from, ?int $to, string $action, User $user, ?string $transferReason, ?string $endReason, bool $logTransfer = true): void
    {
        $asset->forceFill(['assigned_to_employee_id' => $to])->save();

        if ($logTransfer) {
            $this->transfers->log($asset, IctAssetTransfer::TYPE_ASSIGNMENT_CHANGE, array_filter([
                'from_employee_id' => $from,
                'to_employee_id' => $to,
                'reason' => $transferReason,
            ], fn ($value) => $value !== null), $user->id);
        }

        $this->audit($action, $asset, $user, array_filter([
            'from_employee_id' => $from,
            'to_employee_id' => $to,
            'end_reason' => $endReason,
        ], fn ($value) => $value !== null));
    }

    /** Ids, the action and the reason only: never a name or free text. */
    protected function audit(string $action, IctAsset $asset, User $user, array $metadata): void
    {
        AuditLog::record(
            action: 'asset_assignment_'.$action,
            module: Permission::MODULE_ASSETS,
            targetType: 'ict_assets',
            targetId: $asset->id,
            metadata: ['action' => $action, 'actor_user_id' => $user->id, ...$metadata],
        );
    }

    /**
     * Makes the open history row agree with the asset's holder column before a change is judged against it. A device
     * whose holder was stored some other way (a record that predates the history, a direct fixture) gets a row for
     * the person it names; a row naming someone else is closed as a handover first. Rare, and logged like any change.
     */
    protected function reconcile(IctAsset $asset, User $user): void
    {
        $holder = $asset->assigned_to_employee_id ? (int) $asset->assigned_to_employee_id : null;
        $open = IctAssetAssignment::query()->where('ict_asset_id', $asset->id)->current()->lockForUpdate()->first();

        if ($open && (int) $open->employee_id === $holder) {
            return;
        }

        if ($open) {
            $this->close($open, $holder ? IctAssetAssignment::END_REASSIGNED : IctAssetAssignment::END_UNASSIGNED, $user);
        }

        if ($holder) {
            IctAssetAssignment::query()->create([
                'ict_asset_id' => $asset->id,
                'employee_id' => $holder,
                'assigned_at' => null,
                'is_current' => true,
                'is_backfilled' => true,
                'recorded_by_user_id' => null,
            ]);
        }
    }
}
