<?php

namespace App\Services\Finance;

use App\Models\District;
use App\Models\Employee;
use App\Models\FinPaymentVoucher;
use App\Models\FinPvAttachment;
use App\Models\FinPvBatch;
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

    /** super_admin: every voucher that reached Audit, in every location (they still cannot review: nothing is assigned to them). */
    public const AUDIT_ALL = 'all';

    /** An Audit officer (audit.review): the vouchers assigned to them, plus any voucher they have ticked, queried or withdrawn a tick on. */
    public const AUDIT_ASSIGNED = 'assigned';

    /** Audit manager (and other read-only roles): only the vouchers an Audit officer has worked on. */
    public const AUDIT_WORKED = 'worked';

    public const AUDIT_NONE = 'none';

    /**
     * The columns the Audit manager's ASSIGNMENT QUEUE may read of a voucher that nobody has worked on yet: a reference, where it
     * is, how much, when it was sent and who has it. Deliberately no payee, details, remarks, attachments or creator.
     */
    public const QUEUE_COLUMNS = [
        'fin_pvs.id', 'fin_pvs.region_id', 'fin_pvs.district_id', 'fin_pvs.batch_id', 'fin_pvs.pv_no', 'fin_pvs.category_id', 'fin_pvs.type_id',
        'fin_pvs.amount', 'fin_pvs.status', 'fin_pvs.round', 'fin_pvs.submitted_at', 'fin_pvs.last_submitted_at',
        'fin_pvs.assigned_to_user_id', 'fin_pvs.assigned_by_user_id', 'fin_pvs.assigned_at',
    ];

    /** The same idea for a batch: no remarks. */
    public const BATCH_QUEUE_COLUMNS = [
        'fin_pv_batches.id', 'fin_pv_batches.batch_no', 'fin_pv_batches.title', 'fin_pv_batches.region_id', 'fin_pv_batches.district_id',
        'fin_pv_batches.status', 'fin_pv_batches.sent_at',
    ];

    /** The payee pickers send nothing to the page for fewer characters than this, and never more rows than the limit. */
    public const SEARCH_MIN = 2;

    public const SEARCH_LIMIT = 20;

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
        $query = $this->pvsForTotals($user, $as);

        if ($as === self::AS_AUDIT && ! $this->seesAll($user)) {
            // THE rule of Audit's view, in one place. Nobody in Audit reads a voucher that is still awaiting assignment, except that
            // the manager's ASSIGNMENT QUEUE (assignmentQueue(), minimal columns) lists it:
            //  - an officer (audit.review): a voucher assigned to them, or one they have worked on (ticked, queried, withdrawn a tick);
            //  - the manager and the read-only roles: a voucher an Audit officer has worked on.
            // A voucher nobody has worked on and that is not theirs is invisible: no row, no detail, no file, no export.
            $assigned = $this->can($user, 'audit.review');
            $worked = $this->readsWorked($user);

            $query->where(function (Builder $reach) use ($user, $assigned, $worked): void {
                $reach->whereRaw('1 = 0');

                if ($assigned) {
                    $reach->orWhere('fin_pvs.assigned_to_user_id', $user->id)
                        ->orWhereExists(fn ($events) => $events->selectRaw('1')->from('fin_pv_events')
                            ->whereColumn('fin_pv_events.pv_id', 'fin_pvs.id')
                            ->where('fin_pv_events.actor_user_id', $user->id)
                            ->whereIn('fin_pv_events.action', FinPvEvent::REVIEWING));
                }

                if ($worked) {
                    $reach->orWhereExists(fn ($events) => $events->selectRaw('1')->from('fin_pv_events')
                        ->whereColumn('fin_pv_events.pv_id', 'fin_pvs.id')
                        ->whereIn('fin_pv_events.action', FinPvEvent::REVIEWING));
                }
            });
        }

        return $query;
    }

    /**
     * Whether the user reads the vouchers an Audit officer has worked on: the manager (audit.assign), audit.view_reviewed, or a
     * read-only holder of audit.view. An officer's own audit.view is only the door to their screen: they read what is theirs.
     */
    protected function readsWorked(User $user): bool
    {
        return $this->can($user, 'audit.assign')
            || $this->can($user, 'audit.view_reviewed')
            || ($this->can($user, 'audit.view') && ! $this->can($user, 'audit.review'));
    }

    /**
     * The location's vouchers for COUNTING only (analytics figures, how many are waiting): the same location, the same draft rule
     * as pvs(), but without the Audit manager's "worked on" limit. Never list, open or export rows from this; a person with no
     * Audit reach at all still gets nothing.
     */
    public function pvsForTotals(User $user, string $as = self::AS_FINANCE): Builder
    {
        $query = $this->scope(FinPaymentVoucher::query(), $user);

        if ($as === self::AS_AUDIT) {
            $query->where('fin_pvs.status', '!=', FinPaymentVoucher::DRAFT)
                ->whereNotNull('fin_pvs.submitted_at');

            if ($this->auditReach($user) === self::AUDIT_NONE) {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    /**
     * How much of Audit's work a user reads: everything that reached Audit (super_admin), what is assigned to them or they have
     * worked on (an officer, audit.review), only what an officer has worked on (the manager and read-only roles), or nothing.
     * Someone holding both the officer and the manager role is an officer here (pvs() adds the manager's part to the officer's).
     */
    public function auditReach(User $user): string
    {
        if ($this->seesAll($user)) {
            return self::AUDIT_ALL;
        }

        if ($this->can($user, 'audit.review')) {
            return self::AUDIT_ASSIGNED;
        }

        return $this->readsWorked($user) ? self::AUDIT_WORKED : self::AUDIT_NONE;
    }

    /**
     * The Audit manager's ASSIGNMENT QUEUE: every voucher the location sent to Audit (never a draft), whatever its state, limited to
     * QUEUE_COLUMNS. It is the only way to list a voucher nobody has worked on, and only for audit.assign holders. Read a page of it
     * for display; it carries no payee, details, remarks or attachment, and opening the voucher still goes through pvs().
     */
    public function assignmentQueue(User $user): Builder
    {
        $query = $this->pvsForTotals($user, self::AS_AUDIT)->select(self::QUEUE_COLUMNS);

        if (! $this->can($user, 'audit.assign')) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /** The sent batches of the location for the assignment queue (BATCH_QUEUE_COLUMNS only), for audit.assign holders. */
    public function assignmentBatches(User $user): Builder
    {
        $query = $this->scope(FinPvBatch::query(), $user)->where('fin_pv_batches.status', FinPvBatch::SENT)->select(self::BATCH_QUEUE_COLUMNS);

        if (! $this->can($user, 'audit.assign')) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    // ------------------------------------------------------------------ batches

    /**
     * The batches a user may reach, seen as Finance (drafts and cancelled ones included) or as Audit (only SENT batches). Finance
     * reads every batch of the location. In Audit the manager (audit.assign) and super_admin see every sent batch, anyone else only
     * a batch with at least one voucher they may open (pvs()). A batch the user does not reach answers 404.
     */
    public function batches(User $user, string $as = self::AS_FINANCE): Builder
    {
        $query = $this->scope(FinPvBatch::query(), $user);

        if ($as === self::AS_AUDIT) {
            $query->where('fin_pv_batches.status', FinPvBatch::SENT);

            if ($this->auditReach($user) === self::AUDIT_NONE) {
                $query->whereRaw('1 = 0');
            } elseif (! $this->seesAll($user) && ! $this->can($user, 'audit.assign')) {
                $query->whereExists(fn ($members) => $members->selectRaw('1')->from('fin_pvs')
                    ->whereColumn('fin_pvs.batch_id', 'fin_pv_batches.id')
                    ->whereIn('fin_pvs.id', $this->pvs($user, self::AS_AUDIT)->select('fin_pvs.id')));
            }
        }

        return $query;
    }

    /**
     * The location's batches for COUNTING only (the analytics' batch figures): the same location and the same Audit rule as batches()
     * (sent ones only) but without the officer's "only mine" limit, the way pvsForTotals() is to pvs(). Never list or open from this.
     */
    public function batchesForTotals(User $user, string $as = self::AS_FINANCE): Builder
    {
        $query = $this->scope(FinPvBatch::query(), $user);

        if ($as === self::AS_AUDIT) {
            $query->where('fin_pv_batches.status', FinPvBatch::SENT);

            if ($this->auditReach($user) === self::AUDIT_NONE) {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    /** 404 unless the user reaches the batch (as Finance or as Audit). */
    public function findBatch(User $user, int|string $id, string $as = self::AS_FINANCE): FinPvBatch
    {
        return $this->batches($user, $as)->whereKey($id)->firstOrFail();
    }

    public function containsBatch(User $user, FinPvBatch $batch, string $as = self::AS_FINANCE): bool
    {
        return $this->batches($user, $as)->whereKey($batch->getKey())->exists();
    }

    /**
     * The Audit officers a voucher (or the vouchers of a batch) may be given to: active people based at the location who hold
     * audit.review, minus anyone who sent ANY of these vouchers to Audit in any round (separation of duties). super_admin is never
     * based anywhere, so is never offered.
     *
     * @param  array<int, int>  $pvIds
     * @return Builder<User>
     */
    public function assignableOfficers(array $location, array $pvIds = []): Builder
    {
        $query = $this->usersAt($location, 'audit.review');

        if ($pvIds !== []) {
            $query->whereNotIn('users.id', FinPvEvent::query()->whereIn('pv_id', $pvIds)->whereIn('action', FinPvEvent::SUBMITTING)->select('actor_user_id'));
        }

        return $query->orderBy('users.full_name');
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
     * A "contains" pattern for a typed term where % and _ are ordinary characters. Pair it with `LIKE ? ESCAPE '!'`: that escape
     * character works the same on MySQL and SQLite (a backslash does not, on SQLite).
     */
    public static function like(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    }

    /**
     * Current staff or retirees of the location whose name or staff ID contains the term: the voucher form's server-side picker.
     * Still ONLY that location's people (it is employees() underneath), at most 20, none for under 2 characters. A row is the
     * id, the name and staff ID + department as a disambiguator: never a phone number or an e-mail address.
     *
     * @return list<array{value: int, label: string, description: string}>
     */
    public function searchEmployees(User $actor, string $kind, string $term, ?array $location = null): array
    {
        $term = trim((string) preg_replace('/\s+/u', ' ', $term));

        if (mb_strlen($term) < self::SEARCH_MIN) {
            return [];
        }

        $like = self::like($term);

        return $this->employees($actor, $kind, $location)
            ->where(fn (Builder $query) => $query->whereRaw("employees.full_name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("employees.staff_id LIKE ? ESCAPE '!'", [$like]))
            ->with('department:id,department_name')
            ->limit(self::SEARCH_LIMIT)
            ->get(['employees.id', 'employees.full_name', 'employees.staff_id', 'employees.department_id'])
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => $employee->full_name,
                'description' => trim('Staff ID '.$employee->staff_id.($employee->department ? ' · '.$employee->department->department_name : '')),
            ])->all();
    }

    /**
     * The districts a voucher may be paid to: REGION BASED ONLY, the districts of the location being worked in. A regional user
     * gets their region's districts (not Head Office, not another region's); Head Office staff the Head Office district; super_admin
     * the districts of the location they picked (nothing until they pick one). Few rows, so the picker filters in the browser.
     *
     * @return Builder<District>
     */
    public function districts(User $actor, ?array $location = null): Builder
    {
        $location = $this->seesAll($actor) ? $location : $this->scope->locationOf($actor);

        if (! $location) {
            return District::query()->whereRaw('1 = 0')->orderBy('districts.district_name');
        }

        return $this->scope->districtsOfUnit($this->isHeadOffice($location), $location['region_id'] ?? null)->orderBy('districts.district_name');
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
