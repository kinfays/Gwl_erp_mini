<?php

namespace App\Services\CreditUnion;

use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Validation\ValidationException;

/**
 * The approve/reject step for self-applied memberships. Approval is committee business:
 * it needs `credit_union.approve_membership` specifically, and nobody may wave through
 * their own application.
 */
class MembershipApplicationService
{
    public const APPROVE_PERMISSION = 'credit_union.approve_membership';

    public function __construct(
        protected MemberRegistrationService $registration
    ) {}

    public function canApprove(User $user, CreditUnionMember $member): bool
    {
        if (! $this->holdsApprovalPermission($user)) {
            return false;
        }

        return ! $this->isOwnApplication($user, $member);
    }

    public function approve(CreditUnionMember $member, User $approver): CreditUnionMember
    {
        $this->guardPendingApplication($member);

        if (! $this->holdsApprovalPermission($approver)) {
            abort(403, 'Membership approval requires the credit union committee permission.');
        }

        if ($this->isOwnApplication($approver, $member)) {
            abort(403, 'You cannot approve your own membership application.');
        }

        $member->forceFill([
            'status' => CreditUnionMember::STATUS_ACTIVE,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ])->save();

        Audit::log(
            action: 'credit_union.membership_application_approved',
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionMember::class,
            targetId: $member->id,
            metadata: [
                'member_number' => $member->member_number,
                'approved_by' => $approver->id,
                'applied_by' => $member->applied_by,
            ]
        );

        // Activation is what posts the one-time initial share into the shares ledger.
        return $this->registration->activate($member);
    }

    public function reject(CreditUnionMember $member, User $approver, string $reason): CreditUnionMember
    {
        $this->guardPendingApplication($member);

        if (! $this->holdsApprovalPermission($approver)) {
            abort(403, 'Membership approval requires the credit union committee permission.');
        }

        if ($this->isOwnApplication($approver, $member)) {
            abort(403, 'You cannot decide on your own membership application.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejectionReason' => 'A reason is required when rejecting an application.',
            ]);
        }

        $member->forceFill([
            'status' => CreditUnionMember::STATUS_INACTIVE,
            'exit_reason' => 'Application rejected: '.$reason,
        ])->save();

        Audit::log(
            action: 'credit_union.membership_application_rejected',
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionMember::class,
            targetId: $member->id,
            metadata: [
                'member_number' => $member->member_number,
                'rejected_by' => $approver->id,
                'reason' => $reason,
            ]
        );

        return $member->refresh();
    }

    protected function guardPendingApplication(CreditUnionMember $member): void
    {
        if (! $member->isPending()) {
            throw ValidationException::withMessages([
                'applications' => 'Only pending membership applications can be decided on.',
            ]);
        }
    }

    protected function holdsApprovalPermission(User $user): bool
    {
        return $user->hasRoles('super_admin') || $user->hasPermission(self::APPROVE_PERMISSION);
    }

    protected function isOwnApplication(User $user, CreditUnionMember $member): bool
    {
        if ($member->applied_by !== null && (int) $member->applied_by === (int) $user->id) {
            return true;
        }

        $employee = $user->employee ?? $user->employeeByStaffId;

        if ($employee && $member->employee_id !== null && (int) $member->employee_id === (int) $employee->id) {
            return true;
        }

        return $member->staff_id !== null
            && $user->staff_id !== null
            && (string) $member->staff_id === (string) $user->staff_id;
    }
}
