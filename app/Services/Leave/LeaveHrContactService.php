<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveHrContact;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The HR contact per scope (a region, or Head Office) that hears about finally-approved leave, and who may
 * edit it.
 *
 * Head Office is a district (Employee::boot() sets location_type = 'HeadOffice' from the district name),
 * not a region, and its staff still carry a real region_id. So the scope of a request comes from the
 * requester's location_type, never from region_id alone: Head Office staff -> the NULL-region contact,
 * everyone else -> their region's contact.
 */
class LeaveHrContactService
{
    public const PERMISSION = 'leave.manage_hr_contacts';

    public function __construct(protected LeaveApprovalChainResolver $chain) {}

    /** @return int|null null = Head Office */
    public function scopeFor(Employee $requester): ?int
    {
        return $requester->location_type === 'HeadOffice' ? null : (int) $requester->region_id;
    }

    /** The active contact for a scope, if one is set up. */
    public function contactFor(?int $regionId): ?LeaveHrContact
    {
        return LeaveHrContact::query()->active()->forScope($regionId)->first();
    }

    /**
     * Users who get the in-app notice for a scope: hr_region users in that region, or hr_headoffice users
     * for Head Office. Active users with an active employee record only.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function hrUsersFor(?int $regionId)
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('name', $regionId === null ? 'hr_headoffice' : 'hr_region'))
            ->when($regionId !== null, fn ($users) => $users->where(fn ($q) => $q
                ->whereHas('employee', fn ($e) => $e->where('region_id', $regionId))
                ->orWhereHas('employeeByStaffId', fn ($e) => $e->where('region_id', $regionId))))
            ->with(['employee', 'employeeByStaffId'])
            ->orderBy('id')
            ->get()
            ->reject(fn (User $user) => $this->chain->employeeOf($user)?->is_active === false)
            ->values();
    }

    /**
     * Which scopes $user may edit. Head Office HR, admin and super_admin: every scope. Regional HR: only their
     * own region (never Head Office). Anyone else: none. Holding the permission is checked separately.
     *
     * @return array{all: bool, regionIds: list<int>}
     */
    public function manageableScopes(User $user): array
    {
        if ($user->hasRoles('super_admin', 'admin', 'hr_headoffice')) {
            return ['all' => true, 'regionIds' => []];
        }

        if ($user->hasRoles('hr_region')) {
            $regionId = $this->chain->employeeOf($user)?->region_id;

            return ['all' => false, 'regionIds' => $regionId ? [(int) $regionId] : []];
        }

        return ['all' => false, 'regionIds' => []];
    }

    public function hasPermission(User $user): bool
    {
        return $user->hasRoles('super_admin', 'admin') || $user->hasPermission(self::PERMISSION);
    }

    public function canManage(User $user, ?int $regionId): bool
    {
        if (! $this->hasPermission($user)) {
            return false;
        }

        $scopes = $this->manageableScopes($user);

        return $scopes['all'] || ($regionId !== null && in_array($regionId, $scopes['regionIds'], true));
    }

    /**
     * Create or update the contact for a scope (one row per scope).
     *
     * @param  array{email: string, name?: ?string, is_active?: bool}  $data
     *
     * @throws AuthorizationException
     */
    public function save(User $actor, ?int $regionId, array $data): LeaveHrContact
    {
        $this->authorize($actor, $regionId);

        return DB::transaction(function () use ($actor, $regionId, $data) {
            // The unique index can't police the NULL (Head Office) row, so read it under lock before writing.
            $contact = LeaveHrContact::query()->forScope($regionId)->lockForUpdate()->first();
            $old = $contact?->only(['email', 'name', 'is_active']);

            $contact ??= new LeaveHrContact(['region_id' => $regionId]);
            $contact->fill([
                'email' => $data['email'],
                'name' => $data['name'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ])->save();

            Audit::log(
                action: $old === null ? 'leave_hr_contact_create' : 'leave_hr_contact_update',
                module: 'leave',
                targetType: 'leave_hr_contacts',
                targetId: $contact->id,
                metadata: [
                    'scope' => $regionId === null ? 'Head Office' : 'region:'.$regionId,
                    'region_id' => $regionId,
                    'before' => $old,
                    'after' => $contact->only(['email', 'name', 'is_active']),
                    'by' => $actor->id,
                ]
            );

            return $contact;
        });
    }

    /** @throws AuthorizationException */
    public function delete(User $actor, LeaveHrContact $contact): void
    {
        $this->authorize($actor, $contact->region_id);

        Audit::log(
            action: 'leave_hr_contact_delete',
            module: 'leave',
            targetType: 'leave_hr_contacts',
            targetId: $contact->id,
            metadata: [
                'scope' => $contact->region_id === null ? 'Head Office' : 'region:'.$contact->region_id,
                'region_id' => $contact->region_id,
                'email' => $contact->email,
                'by' => $actor->id,
            ]
        );

        $contact->delete();
    }

    /** @throws AuthorizationException */
    protected function authorize(User $actor, ?int $regionId): void
    {
        if (! $this->canManage($actor, $regionId)) {
            throw new AuthorizationException('You cannot manage the HR contact for this scope.');
        }
    }
}
