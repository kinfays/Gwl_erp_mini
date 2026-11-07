<?php

namespace App\Services\Finance;

use App\Models\District;
use App\Models\Employee;
use App\Models\FinPaymentVoucher;
use App\Models\FinPvAttachment;
use App\Models\FinPvEvent;
use App\Models\User;
use App\Services\Transport\TransportScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The one place that decides which payment vouchers (and so which events, attachments, employees and analytics) a user may
 * reach, in BOTH the Finance and the Audit module. Every screen, the services and the tests call it; never filter by
 * region in Blade or in a screen, and never scope by region alone.
 *
 * Head Office and every region keep their own Finance and their own Audit. A location is Head Office OR a region WITHOUT
 * Head Office (Head Office is a district that shares a region id with its staff). That rule is TransportScope's, one rule for
 * the whole ERP; this class only applies it to the fin_ tables, whose rows carry region_id and district_id.
 *  - super_admin reads every location and must pick the location a write goes into;
 *  - everyone else reads and writes in their own location only, taken from their employee record;
 *  - another location's row answers 404 (not 403), the same as one that does not exist;
 *  - a login with no employee record or no region reaches nothing;
 *  - a DRAFT is visible to Finance only: the Audit view (`as: audit`) never includes drafts, nor a voided draft.
 * Suppliers and the category / type catalogue are deliberately NOT location-scoped (they are one company-wide list).
 */
class FinanceScope
{
    public const AS_FINANCE = 'finance';

    public const AS_AUDIT = 'audit';

    public function __construct(protected TransportScope $scope) {}

    public function can(User $user, string $permission): bool
    {
        return $this->scope->can($user, $permission);
    }

    public function seesAll(User $user): bool
    {
        return $this->scope->seesAll($user);
    }

    /** Whether the user has a location at all (super_admin always does: they pick). */
    public function hasLocation(User $user): bool
    {
        return $this->seesAll($user) || $this->scope->locationOf($user) !== null;
    }

    /** The user's own place (employee region and district), or null. */
    public function ownLocation(User $user): ?array
    {
        return $this->scope->locationOf($user);
    }

    public function isHeadOffice(array $location): bool
    {
        return $this->scope->isHeadOfficeDistrict($location['district_id'] ?? null);
    }

    /** A short stable key of what a user reads, for cache keys. */
    public function keyFor(User $user): string
    {
        return $this->scope->keyFor($user);
    }

    // ------------------------------------------------------------------ vouchers and what hangs off them

    /** Restricts a query on `fin_pvs` (region_id + district_id) to the user's reach: location only. */
    public function scope(Builder $query, User $user): Builder
    {
        return $this->scope->apply($query, $user);
    }

    /** Restricts a query to the location (Head Office or region) of the given place. */
    public function atLocation(Builder $query, array $location): Builder
    {
        return $this->scope->applyLocation($query, $location['region_id'] ?? null, $location['district_id'] ?? null);
    }

    /**
     * The vouchers a user may reach, seen as Finance (drafts included) or as Audit (never a draft, never a voided draft).
     * The caller still checks the permission (finance.view / audit.view): this decides reach, not rights.
     */
    public function pvs(User $user, string $as = self::AS_FINANCE): Builder
    {
        $query = $this->scope(FinPaymentVoucher::query(), $user);

        if ($as === self::AS_AUDIT) {
            $query->where('fin_pvs.status', '!=', FinPaymentVoucher::DRAFT)
                ->whereNotNull('fin_pvs.submitted_at');
        }

        return $query;
    }

    /** 404 unless the user reaches the voucher (as Finance or as Audit). */
    public function findPv(User $user, int|string $id, string $as = self::AS_FINANCE): FinPaymentVoucher
    {
        return $this->pvs($user, $as)->whereKey($id)->firstOrFail();
    }

    public function containsPv(User $user, FinPaymentVoucher $pv, string $as = self::AS_FINANCE): bool
    {
        return $this->pvs($user, $as)->whereKey($pv->getKey())->exists();
    }

    /** Timeline rows of the vouchers the user reaches. */
    public function events(User $user, string $as = self::AS_FINANCE): Builder
    {
        return FinPvEvent::query()->whereIn('fin_pv_events.pv_id', $this->pvs($user, $as)->select('fin_pvs.id'));
    }

    /** Attachment rows of the vouchers the user reaches. */
    public function attachments(User $user, string $as = self::AS_FINANCE): Builder
    {
        return FinPvAttachment::query()->whereIn('fin_pv_attachments.pv_id', $this->pvs($user, $as)->select('fin_pvs.id'));
    }

    /** 404 unless the user reaches the row's voucher. */
    public function authorizePv(User $user, Model $pv, string $as = self::AS_FINANCE): Model
    {
        abort_unless($this->containsPv($user, $pv, $as), 404);

        return $pv;
    }

    // ------------------------------------------------------------------ writing

    /**
     * Where a new voucher goes. A member of staff works in their own location (the region and district of their employee
     * record); super_admin has none and must say which district's books ($districtId).
     *
     * @return array{region_id: int, district_id: int|null}
     */
    public function recordingLocation(User $actor, ?int $districtId = null): array
    {
        if ($this->seesAll($actor)) {
            $district = $districtId ? District::query()->find($districtId) : null;

            if (! $district) {
                throw ValidationException::withMessages(['location' => 'Choose the location whose vouchers you are working in.']);
            }

            return ['region_id' => (int) $district->region_id, 'district_id' => (int) $district->id];
        }

        $own = $this->scope->locationOf($actor);

        if (! $own) {
            // Not a 403: nothing here is theirs to see, so nothing is theirs to write.
            abort(404);
        }

        return $own;
    }

    /** Whether two places belong to the same books (same Head Office / same region without it). */
    public function sameLocation(array $a, array $b): bool
    {
        $ho = fn (array $place) => $this->scope->isHeadOfficeDistrict($place['district_id'] ?? null);

        if ($ho($a) || $ho($b)) {
            return $ho($a) && $ho($b);
        }

        return ($a['region_id'] ?? null) !== null && (int) $a['region_id'] === (int) ($b['region_id'] ?? 0);
    }

    // ------------------------------------------------------------------ people

    /**
     * The people a voucher can be paid to as an employee or a retiree, in the location being worked in (the user's own, or
     * the one super_admin chose). EmployeeDirectory is deliberately untouched.
     *  - employee: current staff (is_active);
     *  - retiree: staff who have left service (deactivated, reason retired / resigned / contract ended / other / none; a
     *    Transfer is an internal move, not leaving, so it is excluded).
     *
     * @return Builder<Employee>
     */
    public function employees(User $actor, string $kind, ?array $location = null): Builder
    {
        $location = $this->seesAll($actor) ? $location : $this->scope->locationOf($actor);
        $query = Employee::query()->visibleInErp()->orderBy('employees.full_name');

        if (! $location || (! $this->isHeadOffice($location) && ($location['region_id'] ?? null) === null)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isHeadOffice($location)) {
            $query->where('employees.location_type', 'HeadOffice');
        } else {
            $query->where('employees.region_id', $location['region_id'])
                ->where(function (Builder $inner): void {
                    $inner->whereNull('employees.location_type')->orWhere('employees.location_type', '!=', 'HeadOffice');
                });
        }

        return match ($kind) {
            FinPaymentVoucher::PAYEE_EMPLOYEE => $query->where('employees.is_active', true),
            FinPaymentVoucher::PAYEE_RETIREE => $query->where('employees.is_active', false)
                ->where(function (Builder $inner): void {
                    $inner->whereNull('employees.deactivation_reason')->orWhere('employees.deactivation_reason', '!=', Employee::EXIT_REASON_TRANSFER);
                }),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Active users based at a location who hold a permission (through any role): who a notice is sent to. super_admin is not
     * "based" anywhere, so is never picked here.
     *
     * @return Builder<User>
     */
    public function usersAt(array $location, string $permission): Builder
    {
        return $this->scope->peopleAt($location['region_id'] ?? null, $location['district_id'] ?? null)
            ->where('users.is_active', true)
            ->whereHas('roles.permissions', fn (Builder $permissions) => $permissions->where('permissions.name', $permission));
    }
}
