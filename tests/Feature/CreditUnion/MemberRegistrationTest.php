<?php

namespace Tests\Feature\CreditUnion;

use App\Livewire\CreditUnion\ApplyMembership;
use App\Livewire\CreditUnion\MembersList;
use App\Models\AuditLog;
use App\Models\CreditUnionLedgerEntry;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use Livewire\Livewire;

class MemberRegistrationTest extends CreditUnionTestCase
{
    public function test_officer_registers_a_staff_member_from_the_employee_directory(): void
    {
        $employee = $this->createEmployee('200101', 'Akosua Mensah');
        $this->actingAs($this->officer());

        Livewire::test(MembersList::class)
            ->call('openStaffForm')
            ->set('form.employee_id', $employee->id)
            ->call('save')
            ->assertHasNoErrors();

        $member = CreditUnionMember::query()->where('employee_id', $employee->id)->firstOrFail();

        $this->assertSame(CreditUnionMember::TYPE_STAFF, $member->member_type);
        $this->assertSame($employee->staff_id, $member->member_number);
        $this->assertSame($employee->staff_id, $member->staff_id);
        $this->assertSame(CreditUnionMember::SOURCE_HR_ADDED, $member->application_source);
        $this->assertSame(CreditUnionMember::STATUS_ACTIVE, $member->status);
    }

    public function test_registration_posts_the_initial_share_but_never_ledgers_the_membership_form_fee(): void
    {
        $employee = $this->createEmployee('200102', 'Kwame Boateng');
        $this->actingAs($this->officer());

        Livewire::test(MembersList::class)
            ->call('openStaffForm')
            ->set('form.employee_id', $employee->id)
            ->call('save')
            ->assertHasNoErrors();

        $member = CreditUnionMember::query()->where('employee_id', $employee->id)->firstOrFail();
        $entries = $member->ledgerEntries()->get();

        // The 200 initial share is a member asset and is ledgered; the 20 form fee is an
        // admin charge and is only recorded on the member row.
        $this->assertCount(1, $entries);

        $share = $entries->first();
        $this->assertSame(CreditUnionLedgerEntry::ACCOUNT_SHARES, $share->account_type);
        $this->assertSame(CreditUnionLedgerEntry::ENTRY_CONTRIBUTION, $share->entry_type);
        $this->assertSame('200.00', $share->amount);
        $this->assertSame('200.00', $share->balance_after);
        $this->assertSame('Initial share purchase at registration', $share->remarks);

        $this->assertSame('20.00', $member->membership_form_fee_amount);
        $this->assertNotNull($member->initial_share_paid_at);
        $this->assertSame(0, CreditUnionLedgerEntry::query()->where('amount', 20)->count());
    }

    public function test_member_creation_is_audit_logged(): void
    {
        $employee = $this->createEmployee('200103', 'Adwoa Owusu');
        $this->actingAs($this->officer());

        Livewire::test(MembersList::class)
            ->call('openStaffForm')
            ->set('form.employee_id', $employee->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.member_created')
            ->exists());

        $this->assertTrue(AuditLog::query()
            ->where('module', Permission::MODULE_CREDIT_UNION)
            ->where('action', 'credit_union.ledger_entry_posted')
            ->exists());
    }

    public function test_manually_entered_associate_members_get_sequential_p_numbers_and_no_employee_link(): void
    {
        $this->actingAs($this->officer());

        Livewire::test(MembersList::class)
            ->call('openAssociateForm')
            ->set('form.full_name', 'Yaw Nkrumah')
            ->set('form.phone', '0244000111')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(MembersList::class)
            ->call('openAssociateForm')
            ->set('form.full_name', 'Esi Amankwah')
            ->call('save')
            ->assertHasNoErrors();

        $first = CreditUnionMember::query()->where('full_name', 'Yaw Nkrumah')->firstOrFail();
        $second = CreditUnionMember::query()->where('full_name', 'Esi Amankwah')->firstOrFail();

        $this->assertSame('P0001', $first->member_number);
        $this->assertSame('P0002', $second->member_number);
        $this->assertNull($first->employee_id);
        $this->assertNull($first->staff_id);
        $this->assertSame(CreditUnionMember::TYPE_ASSOCIATE, $first->member_type);
        $this->assertSame(CreditUnionMember::SOURCE_ASSOCIATE_MANUAL, $first->application_source);
        $this->assertSame(CreditUnionMember::STATUS_ACTIVE, $first->status);
        $this->assertSame(200.0, $first->balanceFor(CreditUnionLedgerEntry::ACCOUNT_SHARES));
    }

    public function test_employee_self_application_stays_pending_and_posts_nothing_yet(): void
    {
        $employee = $this->createEmployee('200104', 'Ama Serwaa');
        $this->actingAs($this->employeeUser($employee));

        Livewire::test(ApplyMembership::class)
            ->set('form.phone', '0201234567')
            ->set('form.address', 'Kaneshie, Accra')
            ->call('submit')
            ->assertHasNoErrors();

        $member = CreditUnionMember::query()->where('employee_id', $employee->id)->firstOrFail();

        $this->assertSame(CreditUnionMember::STATUS_PENDING, $member->status);
        $this->assertSame(CreditUnionMember::SOURCE_SELF_APPLIED, $member->application_source);
        $this->assertSame($employee->staff_id, $member->member_number);
        $this->assertNotNull($member->applied_by);
        $this->assertNull($member->initial_share_paid_at);
        $this->assertSame(0, $member->ledgerEntries()->count());
    }

    public function test_an_employee_cannot_be_registered_twice(): void
    {
        $employee = $this->createEmployee('200105', 'Kofi Asante');
        $this->actingAs($this->officer());

        Livewire::test(MembersList::class)
            ->call('openStaffForm')
            ->set('form.employee_id', $employee->id)
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(MembersList::class)
            ->call('openStaffForm')
            ->set('form.employee_id', $employee->id)
            ->call('save')
            ->assertHasErrors('form.employee_id');

        $this->assertSame(1, CreditUnionMember::query()->where('employee_id', $employee->id)->count());
    }

    public function test_an_officer_can_edit_a_member_record(): void
    {
        $member = CreditUnionMember::factory()->associate()->shareIssued()->create();
        $this->actingAs($this->officer());

        Livewire::test(MembersList::class)
            ->call('openEdit', $member->id)
            ->set('form.phone', '0559998888')
            ->set('form.address', 'Takoradi')
            ->set('form.default_monthly_savings_amount', '150')
            ->call('save')
            ->assertHasNoErrors();

        $member->refresh();
        $this->assertSame('0559998888', $member->phone);
        $this->assertSame('Takoradi', $member->address);
        $this->assertSame('150.00', $member->default_monthly_savings_amount);
    }

    public function test_a_plain_employee_cannot_reach_the_member_register(): void
    {
        $employee = $this->createEmployee('200106', 'Nana Yaw');
        $this->actingAs($this->employeeUser($employee));

        $this->get(route('credit-union.members'))->assertForbidden();
    }

    public function test_an_officer_can_load_the_module_screens(): void
    {
        $officer = $this->officer();

        $this->actingAs($officer)->get(route('credit-union.home'))->assertRedirect(route('credit-union.members'));
        $this->actingAs($officer)->get(route('credit-union.members'))->assertOk()->assertSee('Member Register');
        $this->actingAs($officer)->get(route('credit-union.members.applications'))->assertOk()->assertSee('Pending Queue');

        $member = CreditUnionMember::factory()->associate()->shareIssued()->create();
        $this->actingAs($officer)->get(route('credit-union.members.show', $member))->assertOk()->assertSee($member->member_number);
    }

    public function test_an_employee_lands_on_the_apply_screen_and_can_load_it(): void
    {
        $employee = $this->createEmployee('200107', 'Araba Cudjoe');
        $user = $this->employeeUser($employee);

        $this->actingAs($user)->get(route('credit-union.home'))->assertRedirect(route('credit-union.apply'));
        $this->actingAs($user)->get(route('credit-union.apply'))->assertOk()->assertSee('Apply for Membership');
    }
}
