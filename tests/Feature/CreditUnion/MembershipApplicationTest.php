<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\ApplyMembership;
use App\Livewire\CreditUnion\MembershipApplications;
use App\Models\AuditLog;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

class MembershipApplicationTest extends CreditUnionTestCase
{
    public function test_a_committee_member_approves_a_pending_application_and_the_initial_share_posts(): void
    {
        [$applicant, $member] = $this->pendingApplication('300101', 'Abena Darko');

        $this->actingAs($this->committeeMember());

        Livewire::test(MembershipApplications::class)
            ->call('approve', $member->id)
            ->assertHasNoErrors();

        $member->refresh();

        $this->assertSame(CreditUnionMember::STATUS_ACTIVE, $member->status);
        $this->assertNotNull($member->approved_at);
        $this->assertNotNull($member->approved_by);
        $this->assertNotSame($applicant->id, $member->approved_by);

        $entries = $member->ledgerEntries()->get();
        $this->assertCount(1, $entries);
        $this->assertSame(CreditUnionLedgerEntry::ACCOUNT_SHARES, $entries->first()->account_type);
        $this->assertSame('200.00', $entries->first()->amount);
        $this->assertNotNull($member->initial_share_paid_at);

        $this->assertTrue(AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.membership_application_approved')
            ->exists());
    }

    public function test_an_applicant_cannot_approve_their_own_application(): void
    {
        [$applicant, $member] = $this->pendingApplication('300102', 'Selorm Agbeko');

        // Even with the committee permission, self-approval is blocked.
        $applicant->roles()->attach(
            Role::query()->where('name', 'credit_union_committee')->firstOrFail()
        );

        $this->actingAs($applicant->fresh());

        Livewire::test(MembershipApplications::class)
            ->call('approve', $member->id)
            ->assertForbidden();

        $member->refresh();
        $this->assertSame(CreditUnionMember::STATUS_PENDING, $member->status);
        $this->assertNull($member->approved_at);
        $this->assertSame(0, $member->ledgerEntries()->count());
    }

    public function test_an_officer_without_the_approve_permission_cannot_approve(): void
    {
        [, $member] = $this->pendingApplication('300103', 'Mawuli Tetteh');

        $officer = $this->officer();
        $this->assertFalse($officer->hasPermission('credit_union.approve_membership'));

        $this->actingAs($officer);

        // The officer may view the queue...
        Livewire::test(MembershipApplications::class)
            ->assertSee('Pending Queue')
            // ...but the approve action itself is committee-only.
            ->call('approve', $member->id)
            ->assertForbidden();

        $this->assertSame(CreditUnionMember::STATUS_PENDING, $member->fresh()->status);
    }

    public function test_the_queue_hides_the_approve_action_from_users_who_cannot_decide(): void
    {
        [, $member] = $this->pendingApplication('300104', 'Efua Bonsu');

        $this->actingAs($this->officer());

        $approvable = Livewire::test(MembershipApplications::class)->viewData('approvable');

        $this->assertFalse($approvable[$member->id]);
    }

    public function test_a_rejected_application_is_marked_inactive_with_the_reason(): void
    {
        [, $member] = $this->pendingApplication('300105', 'Kojo Danso');

        $this->actingAs($this->committeeMember());

        Livewire::test(MembershipApplications::class)
            ->call('startReject', $member->id)
            ->set('rejectionReason', 'Probation not completed')
            ->call('reject')
            ->assertHasNoErrors();

        $member->refresh();

        $this->assertSame(CreditUnionMember::STATUS_INACTIVE, $member->status);
        $this->assertStringContainsString('Probation not completed', (string) $member->exit_reason);
        $this->assertSame(0, $member->ledgerEntries()->count());

        $this->assertTrue(AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.membership_application_rejected')
            ->exists());
    }

    public function test_an_already_decided_application_cannot_be_approved_again(): void
    {
        [, $member] = $this->pendingApplication('300106', 'Rita Appiah');

        $this->actingAs($this->committeeMember());

        Livewire::test(MembershipApplications::class)
            ->call('approve', $member->id)
            ->assertHasNoErrors();

        Livewire::test(MembershipApplications::class)
            ->call('approve', $member->id)
            ->assertHasErrors('applications');

        $this->assertSame(1, $member->fresh()->ledgerEntries()->count());
    }

    /**
     * @return array{0: User, 1: CreditUnionMember}
     */
    protected function pendingApplication(string $staffId, string $name): array
    {
        $employee = $this->createEmployee($staffId, $name);
        $applicant = $this->employeeUser($employee);

        $this->actingAs($applicant);

        Livewire::test(ApplyMembership::class)
            ->set('form.phone', '0200000000')
            ->call('submit')
            ->assertHasNoErrors();

        $member = CreditUnionMember::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(CreditUnionMember::STATUS_PENDING, $member->status);

        return [$applicant, $member];
    }
}
