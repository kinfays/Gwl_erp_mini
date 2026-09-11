<?php

namespace App\Services\CreditUnion;

use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates members through the three registration paths the union actually uses:
 * an officer picking an employee out of the directory, an employee applying for
 * themselves, or an officer hand-entering a non-staff associate.
 */
class MemberRegistrationService
{
    public function __construct(
        protected LedgerService $ledger
    ) {}

    /**
     * Path 1 - an officer registers an employee from the directory. Active immediately.
     */
    public function registerStaffMember(Employee $employee, array $payload = [], ?int $actorId = null): CreditUnionMember
    {
        $this->guardEmployeeIsNotAlreadyAMember($employee);

        $member = $this->create([
            'member_type' => CreditUnionMember::TYPE_STAFF,
            'member_number' => $employee->staff_id,
            'employee_id' => $employee->id,
            'staff_id' => $employee->staff_id,
            'full_name' => $payload['full_name'] ?? $employee->full_name,
            'phone' => $payload['phone'] ?? null,
            'address' => $payload['address'] ?? null,
            'application_source' => CreditUnionMember::SOURCE_HR_ADDED,
            'status' => CreditUnionMember::STATUS_ACTIVE,
        ], $payload, $actorId);

        return $this->activate($member);
    }

    /**
     * Path 2 - an employee applies for themselves. Stays pending until approved.
     */
    public function submitSelfApplication(User $user, array $payload = []): CreditUnionMember
    {
        $employee = $user->employee ?? $user->employeeByStaffId;

        if (! $employee) {
            throw ValidationException::withMessages([
                'form.phone' => 'Your staff record could not be found. Contact HR before applying.',
            ]);
        }

        $this->guardEmployeeIsNotAlreadyAMember($employee);

        return $this->create([
            'member_type' => CreditUnionMember::TYPE_STAFF,
            'member_number' => $employee->staff_id,
            'employee_id' => $employee->id,
            'staff_id' => $employee->staff_id,
            'full_name' => $payload['full_name'] ?? $employee->full_name,
            'phone' => $payload['phone'] ?? null,
            'address' => $payload['address'] ?? null,
            'application_source' => CreditUnionMember::SOURCE_SELF_APPLIED,
            'status' => CreditUnionMember::STATUS_PENDING,
            'applied_by' => $user->id,
        ], $payload, $user->id);
    }

    /**
     * Path 3 - an officer hand-enters a non-staff associate. Active immediately, and
     * gets the next sequential P-prefixed member number since there is no staff ID.
     */
    public function registerAssociateMember(array $payload, ?int $actorId = null): CreditUnionMember
    {
        $member = $this->create([
            'member_type' => CreditUnionMember::TYPE_ASSOCIATE,
            'member_number' => $this->nextAssociateMemberNumber(),
            'employee_id' => null,
            'staff_id' => null,
            'full_name' => $payload['full_name'] ?? '',
            'phone' => $payload['phone'] ?? null,
            'address' => $payload['address'] ?? null,
            'application_source' => CreditUnionMember::SOURCE_ASSOCIATE_MANUAL,
            'status' => CreditUnionMember::STATUS_ACTIVE,
        ], $payload, $actorId);

        return $this->activate($member);
    }

    public function updateMember(CreditUnionMember $member, array $payload): CreditUnionMember
    {
        $member->update([
            'full_name' => $payload['full_name'] ?? $member->full_name,
            'phone' => $payload['phone'] ?? null,
            'address' => $payload['address'] ?? null,
            'legacy_account_number' => $payload['legacy_account_number'] ?? null,
            'default_monthly_savings_amount' => $this->nullableAmount($payload['default_monthly_savings_amount'] ?? null),
            'default_monthly_shares_amount' => $this->nullableAmount($payload['default_monthly_shares_amount'] ?? null),
        ]);

        Audit::log(
            action: 'credit_union.member_updated',
            module: Permission::MODULE_CREDIT_UNION,
            targetType: CreditUnionMember::class,
            targetId: $member->id,
            metadata: [
                'member_number' => $member->member_number,
                'member_type' => $member->member_type,
            ]
        );

        return $member->refresh();
    }

    /**
     * Posts the one-time initial share as a `shares` ledger entry once a member is active.
     * The membership form fee is a non-refundable admin charge, not a member asset, so it
     * is only recorded on the member row and never touches the ledger.
     */
    public function activate(CreditUnionMember $member): CreditUnionMember
    {
        if (! $member->isActive() || $member->initial_share_paid_at !== null) {
            return $member;
        }

        $amount = round((float) $member->initial_share_amount, 2);

        if ($amount <= 0) {
            return $member;
        }

        $this->ledger->post($member, [
            'account_type' => CreditUnionLedgerEntry::ACCOUNT_SHARES,
            'entry_type' => CreditUnionLedgerEntry::ENTRY_CONTRIBUTION,
            'amount' => $amount,
            'transaction_date' => ($member->registered_at ?? today())->toDateString(),
            'source' => CreditUnionLedgerEntry::SOURCE_CASH,
            'remarks' => 'Initial share purchase at registration',
        ], $member->created_by);

        $member->forceFill([
            'initial_share_paid_at' => $member->registered_at ?? today(),
        ])->save();

        return $member->refresh();
    }

    public function nextAssociateMemberNumber(): string
    {
        $prefix = CreditUnionMember::ASSOCIATE_NUMBER_PREFIX;

        $highest = CreditUnionMember::withTrashed()
            ->ofType(CreditUnionMember::TYPE_ASSOCIATE)
            ->where('member_number', 'like', $prefix.'%')
            ->pluck('member_number')
            ->map(fn (string $number) => (int) ltrim(substr($number, strlen($prefix)), '0'))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    protected function create(array $attributes, array $payload, ?int $actorId): CreditUnionMember
    {
        if (trim((string) $attributes['full_name']) === '') {
            throw ValidationException::withMessages([
                'form.full_name' => 'Full name is required.',
            ]);
        }

        if (trim((string) $attributes['member_number']) === '') {
            throw ValidationException::withMessages([
                'form.member_number' => 'A member number could not be resolved for this member.',
            ]);
        }

        $member = DB::transaction(function () use ($attributes, $payload, $actorId): CreditUnionMember {
            $member = CreditUnionMember::query()->create([
                ...$attributes,
                'registered_at' => $payload['registered_at'] ?? today()->toDateString(),
                'legacy_account_number' => $payload['legacy_account_number'] ?? null,
                'membership_form_fee_amount' => $this->formFeeAmount($payload),
                'membership_form_fee_paid_at' => $payload['membership_form_fee_paid_at'] ?? null,
                'initial_share_amount' => $this->initialShareAmount($payload),
                'default_monthly_savings_amount' => $this->nullableAmount($payload['default_monthly_savings_amount'] ?? null),
                'default_monthly_shares_amount' => $this->nullableAmount($payload['default_monthly_shares_amount'] ?? null),
                'created_by' => $actorId ?? auth()->id(),
            ]);

            Audit::log(
                action: 'credit_union.member_created',
                module: Permission::MODULE_CREDIT_UNION,
                targetType: CreditUnionMember::class,
                targetId: $member->id,
                metadata: [
                    'member_number' => $member->member_number,
                    'member_type' => $member->member_type,
                    'application_source' => $member->application_source,
                    'status' => $member->status,
                    'membership_form_fee_amount' => (float) $member->membership_form_fee_amount,
                ]
            );

            return $member;
        });

        return $member;
    }

    protected function guardEmployeeIsNotAlreadyAMember(Employee $employee): void
    {
        $exists = CreditUnionMember::query()
            ->where('employee_id', $employee->id)
            ->orWhere(function ($query) use ($employee) {
                $query->whereNotNull('staff_id')->where('staff_id', $employee->staff_id);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'form.employee_id' => 'This employee already has a credit union membership record.',
            ]);
        }
    }

    protected function formFeeAmount(array $payload): float
    {
        return round((float) ($payload['membership_form_fee_amount'] ?? config('gwl.credit_union_membership_form_fee')), 2);
    }

    protected function initialShareAmount(array $payload): float
    {
        return round((float) ($payload['initial_share_amount'] ?? config('gwl.credit_union_initial_share_amount')), 2);
    }

    protected function nullableAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}
