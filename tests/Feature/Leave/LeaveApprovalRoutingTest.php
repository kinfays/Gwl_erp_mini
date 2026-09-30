<?php

namespace Tests\Feature\Leave;

use App\Exceptions\Leave\LeaveAlreadyActionedException;
use App\Livewire\Leave\Approvals;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Leave\Concerns\BuildsLeaveOrg;
use Tests\TestCase;

/**
 * Who a leave request is routed to (R1–R3 for regions and districts, H1–H4 for Head Office), who may act on
 * it, and that it can only be acted on once.
 */
class LeaveApprovalRoutingTest extends TestCase
{
    use BuildsLeaveOrg;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();

        $this->buildOrg();
    }

    // ---------------------------------------------------------------- Region / District

    public function test_r1_district_staff_go_to_their_district_manager_then_the_regional_chief_manager(): void
    {
        $applicant = $this->staff('EMP001', $this->temaDistrict, $this->operations);
        $districtManager = $this->staff('DM001', $this->temaDistrict, $this->operations, ['district_manager']);
        $otherDistrictManager = $this->staff('DM002', $this->kumasiDistrict, $this->operations, ['district_manager']);
        $chief = $this->staff('RCM001', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $otherChief = $this->staff('RCM002', $this->kumasiOffice, $this->operations, ['regional_chief_manager']);

        $request = $this->submitLeave($applicant);

        $this->assertSame('Pending Approval', $request->leave_status);
        $this->assertSame('Pending', $request->manager_recommendation);
        $this->assertFalse($request->is_single_stage);
        $this->assertSame($this->userOf($districtManager)->id, $request->manager_user_id);
        $this->assertSame($this->userOf($chief)->id, $request->chief_user_id);
        $this->assertSame($districtManager->id, $request->manager_id);

        // Another district's manager and another region's chief are not in this chain.
        $this->assertRefused(fn () => $this->workflow()->recommend($otherDistrictManager, $request->fresh(), null, true));

        $this->workflow()->recommend($districtManager, $request->fresh(), 'Covered', true);
        $this->assertSame('Recommended', $request->fresh()->manager_recommendation);
        $this->assertSame('Pending Approval', $request->fresh()->leave_status);

        $this->assertRefused(fn () => $this->workflow()->finalDecision($otherChief, $request->fresh(), null, true));

        $this->workflow()->finalDecision($chief, $request->fresh(), 'Enjoy', true);
        $this->assertSame('Approved', $request->fresh()->leave_status);
        $this->assertSame($chief->id, $request->fresh()->approved_by_id);
    }

    public function test_r2_regional_office_staff_go_to_the_regional_departmental_manager_not_head_offices(): void
    {
        $applicant = $this->staff('EMP002', $this->accraOffice, $this->finance);
        // Head Office is in the same region and department: created first so a scope-blind lookup would pick it.
        $headOfficeManager = $this->staff('DMH001', $this->headOffice, $this->finance, ['departmental_manager']);
        $regionalManager = $this->staff('DMR001', $this->accraOffice, $this->finance, ['departmental_manager']);
        $chief = $this->staff('RCM003', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $request = $this->submitLeave($applicant);

        $this->assertFalse($request->is_single_stage);
        $this->assertSame($this->userOf($regionalManager)->id, $request->manager_user_id);
        $this->assertSame($this->userOf($chief)->id, $request->chief_user_id);
        $this->assertRefused(fn () => $this->workflow()->recommend($headOfficeManager, $request->fresh(), null, true));
    }

    public function test_r3_district_and_departmental_managers_in_a_region_apply_straight_to_the_regional_chief(): void
    {
        $districtManager = $this->staff('DM003', $this->temaDistrict, $this->operations, ['district_manager']);
        $departmentalManager = $this->staff('DMR002', $this->accraOffice, $this->finance, ['departmental_manager']);
        $chief = $this->staff('RCM004', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        foreach ([$districtManager, $departmentalManager] as $manager) {
            $request = $this->submitLeave($manager);

            $this->assertTrue($request->is_single_stage);
            $this->assertNull($request->manager_user_id);
            $this->assertSame($this->userOf($chief)->id, $request->chief_user_id);
            $this->assertSame('Pending Approval', $request->leave_status);

            // Nobody recommends a single-stage request: the chief goes straight to the decision.
            $this->assertRefused(fn () => $this->workflow()->recommend($chief, $request->fresh(), null, true));

            $this->workflow()->finalDecision($chief, $request->fresh(), null, true);
            $this->assertSame('Approved', $request->fresh()->leave_status);
        }
    }

    // ---------------------------------------------------------------- Head Office

    public function test_h1_head_office_staff_in_a_unit_go_to_that_units_manager_then_their_departments_chief(): void
    {
        $applicant = $this->staff('EMP003', $this->headOffice, $this->finance, [], 'Payroll');
        $otherUnitManager = $this->staff('UM002', $this->headOffice, $this->finance, ['manager'], 'Audit');
        $unitManager = $this->staff('UM001', $this->headOffice, $this->finance, ['manager'], 'Payroll');
        $otherChief = $this->staff('CM002', $this->headOffice, $this->operations, ['chief_manager']);
        $chief = $this->staff('CM001', $this->headOffice, $this->finance, ['chief_manager']);

        $request = $this->submitLeave($applicant);

        $this->assertFalse($request->is_single_stage);
        $this->assertSame($this->userOf($unitManager)->id, $request->manager_user_id);
        $this->assertSame($this->userOf($chief)->id, $request->chief_user_id);
        $this->assertRefused(fn () => $this->workflow()->recommend($otherUnitManager, $request->fresh(), null, true));

        $this->workflow()->recommend($unitManager, $request->fresh(), null, true);
        $this->assertRefused(fn () => $this->workflow()->finalDecision($otherChief, $request->fresh(), null, true));
        $this->workflow()->finalDecision($chief, $request->fresh(), null, true);

        $this->assertSame('Approved', $request->fresh()->leave_status);
    }

    public function test_h2_head_office_staff_without_a_unit_go_to_the_departmental_manager_at_head_office(): void
    {
        $applicant = $this->staff('EMP004', $this->headOffice, $this->finance);
        // The regional one first, so a lookup by department alone would pick it.
        $this->staff('DMR003', $this->accraOffice, $this->finance, ['departmental_manager']);
        $departmentalManager = $this->staff('DMH002', $this->headOffice, $this->finance, ['departmental_manager']);
        $chief = $this->staff('CM003', $this->headOffice, $this->finance, ['chief_manager']);

        $request = $this->submitLeave($applicant);

        $this->assertFalse($request->is_single_stage);
        $this->assertSame($this->userOf($departmentalManager)->id, $request->manager_user_id);
        $this->assertSame($this->userOf($chief)->id, $request->chief_user_id);
    }

    public function test_h3_unit_and_departmental_managers_at_head_office_apply_straight_to_their_departments_chief(): void
    {
        $unitManager = $this->staff('UM003', $this->headOffice, $this->finance, ['manager'], 'Payroll');
        $departmentalManager = $this->staff('DMH003', $this->headOffice, $this->finance, ['departmental_manager']);
        $this->staff('CM004', $this->headOffice, $this->operations, ['chief_manager']);
        $chief = $this->staff('CM005', $this->headOffice, $this->finance, ['chief_manager']);

        foreach ([$unitManager, $departmentalManager] as $manager) {
            $request = $this->submitLeave($manager);

            $this->assertTrue($request->is_single_stage);
            $this->assertNull($request->manager_user_id);
            $this->assertSame($this->userOf($chief)->id, $request->chief_user_id);
        }
    }

    public function test_h4_chief_managers_and_regional_chief_managers_apply_straight_to_the_managing_director(): void
    {
        $md = $this->staff('MD001', $this->headOffice, $this->operations, ['managing_director']);
        $chief = $this->staff('CM006', $this->headOffice, $this->finance, ['chief_manager']);
        $regionalChief = $this->staff('RCM005', $this->kumasiOffice, $this->operations, ['regional_chief_manager']);
        // A chief manager of another department is not the MD.
        $this->staff('CM007', $this->headOffice, $this->operations, ['chief_manager']);

        foreach ([$chief, $regionalChief] as $applicant) {
            $request = $this->submitLeave($applicant);

            $this->assertTrue($request->is_single_stage, $applicant->staff_id);
            $this->assertNull($request->manager_user_id);
            $this->assertSame($this->userOf($md)->id, $request->chief_user_id);

            $this->workflow()->finalDecision($md, $request->fresh(), 'Approved', true);
            $this->assertSame('Approved', $request->fresh()->leave_status);
            $this->assertSame($md->id, $request->fresh()->approved_by_id);
        }
    }

    public function test_the_managing_director_role_is_seeded_with_leave_access(): void
    {
        $role = Role::query()->where('name', 'managing_director')->firstOrFail();

        $this->assertTrue((bool) $role->is_system);
        $this->assertTrue((bool) DB::table('module_access')->where('role_id', $role->id)->where('module', 'leave')->value('can_access'));
        $this->assertTrue(DB::table('permissions')->where('name', 'leave.manage_hr_contacts')->where('module', 'leave')->exists());
    }

    public function test_a_chief_manager_cannot_submit_when_there_is_no_managing_director(): void
    {
        $chief = $this->staff('CM008', $this->headOffice, $this->finance, ['chief_manager']);
        // Inactive MDs don't count.
        $this->staff('MD002', $this->headOffice, $this->operations, ['managing_director'], userActive: false);

        $this->assertBlockedSubmission($chief, 'Managing Director', 'managing_director');
    }

    public function test_the_managing_director_cannot_apply_because_nobody_is_above_them(): void
    {
        $md = $this->staff('MD003', $this->headOffice, $this->operations, ['managing_director']);

        $this->assertBlockedSubmission($md, 'no approver above the Managing Director', 'managing_director');
    }

    // ---------------------------------------------------------------- Applicant, missing and inactive approvers

    public function test_the_applicant_can_never_recommend_or_approve_their_own_request(): void
    {
        $md = $this->staff('MD004', $this->headOffice, $this->operations, ['managing_director']);
        $chief = $this->staff('CM009', $this->headOffice, $this->finance, ['chief_manager']);
        // A super_admin who also applies as a district manager: the bypass never covers their own request.
        $this->staff('RCM006', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $superAdmin = $this->staff('SA001', $this->temaDistrict, $this->operations, ['super_admin', 'district_manager']);

        $chiefRequest = $this->submitLeave($chief);
        $this->assertRefused(fn () => $this->workflow()->finalDecision($chief, $chiefRequest->fresh(), null, true));

        $adminRequest = $this->submitLeave($superAdmin);
        $this->assertRefused(fn () => $this->workflow()->finalDecision($superAdmin, $adminRequest->fresh(), null, true));

        // The MD still can.
        $this->workflow()->finalDecision($md, $chiefRequest->fresh(), null, true);
        $this->assertSame('Approved', $chiefRequest->fresh()->leave_status);
    }

    public function test_the_applicant_is_never_offered_their_own_request_in_the_queue(): void
    {
        $chief = $this->staff('CM010', $this->headOffice, $this->finance, ['chief_manager']);
        $this->staff('MD005', $this->headOffice, $this->operations, ['managing_director']);
        $this->submitLeave($chief);

        Livewire::actingAs($this->userOf($chief))
            ->test(Approvals::class)
            ->assertDontSee('Employee CM010');
    }

    public function test_submission_is_blocked_with_a_message_naming_the_missing_role_and_is_audited(): void
    {
        $applicant = $this->staff('EMP005', $this->temaDistrict, $this->operations);
        $this->staff('RCM007', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        // A district manager of a different district does not fill the gap.
        $this->staff('DM004', $this->kumasiDistrict, $this->operations, ['district_manager']);

        $this->assertBlockedSubmission($applicant, 'District Manager', 'district_manager');
        $this->assertBlockedSubmission($applicant, 'Tema District', 'district_manager');
    }

    public function test_submission_is_blocked_when_the_final_approver_is_missing(): void
    {
        $applicant = $this->staff('EMP006', $this->temaDistrict, $this->operations);
        $this->staff('DM005', $this->temaDistrict, $this->operations, ['district_manager']);

        $this->assertBlockedSubmission($applicant, 'Regional Chief Manager', 'regional_chief_manager');
    }

    public function test_a_head_office_unit_without_a_unit_manager_blocks_submission(): void
    {
        $applicant = $this->staff('EMP007', $this->headOffice, $this->finance, [], 'Payroll');
        $this->staff('CM011', $this->headOffice, $this->finance, ['chief_manager']);
        $this->staff('DMH004', $this->headOffice, $this->finance, ['departmental_manager']);

        $this->assertBlockedSubmission($applicant, 'Unit Manager', 'manager');
    }

    public function test_inactive_users_and_inactive_employees_are_never_resolved_as_approvers(): void
    {
        $applicant = $this->staff('EMP008', $this->temaDistrict, $this->operations);
        $this->staff('DM006', $this->temaDistrict, $this->operations, ['district_manager'], userActive: false);
        $this->staff('DM007', $this->temaDistrict, $this->operations, ['district_manager'], employeeActive: false);
        $this->staff('RCM008', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $this->assertBlockedSubmission($applicant, 'District Manager', 'district_manager');

        $active = $this->staff('DM008', $this->temaDistrict, $this->operations, ['district_manager']);

        $this->assertSame($this->userOf($active)->id, $this->submitLeave($applicant)->manager_user_id);
    }

    public function test_a_draft_can_be_saved_without_any_approver_being_set_up(): void
    {
        $applicant = $this->staff('EMP009', $this->temaDistrict, $this->operations);

        $draft = $this->workflow()->savePlanned($applicant, $this->leaveData());

        $this->assertSame('Planned', $draft->leave_status);
        $this->assertNull($draft->manager_id);
        $this->assertNull($draft->manager_user_id);
        $this->assertNull($draft->chief_user_id);
    }

    public function test_approvers_are_resolved_when_a_draft_is_submitted(): void
    {
        $applicant = $this->staff('EMP010', $this->temaDistrict, $this->operations);
        $draft = $this->workflow()->savePlanned($applicant, $this->leaveData());

        $manager = $this->staff('DM009', $this->temaDistrict, $this->operations, ['district_manager']);
        $chief = $this->staff('RCM009', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $submitted = $this->workflow()->submitExisting($applicant, $draft->fresh(), $this->leaveData());

        $this->assertSame($this->userOf($manager)->id, $submitted->manager_user_id);
        $this->assertSame($this->userOf($chief)->id, $submitted->chief_user_id);
        $this->assertSame($manager->id, $submitted->manager_id);
    }

    public function test_reopening_a_denied_request_clears_the_snapshotted_approvers(): void
    {
        $applicant = $this->staff('EMP011', $this->temaDistrict, $this->operations);
        $manager = $this->staff('DM010', $this->temaDistrict, $this->operations, ['district_manager']);
        $this->staff('RCM010', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $request = $this->submitLeave($applicant);
        $this->workflow()->recommend($manager, $request->fresh(), 'No cover', false);
        $this->assertSame('Denied', $request->fresh()->leave_status);

        $this->workflow()->reopen($applicant, $request->fresh());

        $request->refresh();
        $this->assertSame('Planned', $request->leave_status);
        $this->assertNull($request->manager_id);
        $this->assertNull($request->manager_user_id);
        $this->assertNull($request->chief_user_id);
        $this->assertFalse($request->is_single_stage);
    }

    public function test_snapshotted_approver_columns_are_nulled_when_their_user_is_deleted(): void
    {
        $applicant = $this->staff('EMP012', $this->temaDistrict, $this->operations);
        $manager = $this->staff('DM011', $this->temaDistrict, $this->operations, ['district_manager']);
        $this->staff('RCM011', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $request = $this->submitLeave($applicant);

        $this->userOf($manager)->delete();

        $request->refresh();
        $this->assertNull($request->manager_user_id);
        // Still known to be two-stage: the flag doesn't depend on the nullable FK.
        $this->assertFalse($request->is_single_stage);
        $this->assertNotNull($request->chief_user_id);
    }

    // ---------------------------------------------------------------- Several holders, out of scope, double action

    public function test_every_holder_of_the_role_in_scope_is_notified_and_whoever_acts_first_wins(): void
    {
        $applicant = $this->staff('EMP013', $this->temaDistrict, $this->operations);
        $managerA = $this->staff('DM012', $this->temaDistrict, $this->operations, ['district_manager']);
        $managerB = $this->staff('DM013', $this->temaDistrict, $this->operations, ['district_manager']);
        $chiefA = $this->staff('RCM012', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $chiefB = $this->staff('RCM013', $this->accraOffice, $this->finance, ['regional_chief_manager']);

        $request = $this->submitLeave($applicant);

        foreach ([$managerA, $managerB] as $manager) {
            $this->assertInAppSent($manager, 'leave_submitted');
            Mail::assertSent(\App\Mail\LeaveSubmittedMail::class, fn ($mail) => $mail->hasTo($manager->email));
        }
        $this->assertInAppNotSent($chiefA, 'leave_submitted');

        // The snapshot names the first holder, but the second may act — and wins.
        $this->assertSame($this->userOf($managerA)->id, $request->manager_user_id);
        $stale = $request->fresh();
        $this->workflow()->recommend($managerB, $request->fresh(), 'Fine', true);
        $this->assertSame($managerB->id, $request->fresh()->manager_id);

        $this->assertThrows(
            fn () => $this->workflow()->recommend($managerA, $stale, 'Also fine', true),
            LeaveAlreadyActionedException::class
        );

        foreach ([$chiefA, $chiefB] as $chief) {
            $this->assertInAppSent($chief, 'leave_recommended');
        }

        $stale = $request->fresh();
        $this->workflow()->finalDecision($chiefB, $request->fresh(), null, true);

        $this->assertThrows(
            fn () => $this->workflow()->finalDecision($chiefA, $stale, null, true),
            LeaveAlreadyActionedException::class
        );
        $this->assertThrows(
            fn () => $this->workflow()->finalDecision($chiefA, $stale, null, false),
            LeaveAlreadyActionedException::class
        );

        // Approved once, charged once.
        $request->refresh();
        $this->assertSame('Approved', $request->leave_status);
        $this->assertSame($chiefB->id, $request->approved_by_id);
        $this->assertSame(3, (int) LeaveBalance::query()->where('employee_id', $applicant->id)->where('leave_type', 'Annual')->value('used_days'));
    }

    public function test_a_second_action_on_a_decided_request_is_rejected_whatever_the_first_one_was(): void
    {
        $applicant = $this->staff('EMP014', $this->temaDistrict, $this->operations);
        $manager = $this->staff('DM014', $this->temaDistrict, $this->operations, ['district_manager']);
        $chief = $this->staff('RCM014', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $request = $this->submitLeave($applicant);
        $this->workflow()->recommend($manager, $request->fresh(), null, false);

        // A manager's rejection is final: it can be neither recommended after the fact nor decided by the chief.
        $this->assertThrows(fn () => $this->workflow()->recommend($manager, $request->fresh(), null, true), LeaveAlreadyActionedException::class);
        $this->assertThrows(fn () => $this->workflow()->finalDecision($chief, $request->fresh(), null, true), LeaveAlreadyActionedException::class);
        $this->assertSame('Denied', $request->fresh()->leave_status);
        $this->assertSame(0, LeaveBalance::query()->count());
    }

    public function test_the_final_stage_cannot_be_reached_without_a_recommendation(): void
    {
        $applicant = $this->staff('EMP015', $this->temaDistrict, $this->operations);
        $this->staff('DM015', $this->temaDistrict, $this->operations, ['district_manager']);
        $chief = $this->staff('RCM015', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $request = $this->submitLeave($applicant);

        $this->assertThrows(
            fn () => $this->workflow()->finalDecision($chief, $request->fresh(), null, true),
            \RuntimeException::class,
            'must be recommended'
        );
        $this->assertSame('Pending Approval', $request->fresh()->leave_status);
    }

    public function test_someone_who_only_holds_the_role_elsewhere_is_refused(): void
    {
        $applicant = $this->staff('EMP016', $this->headOffice, $this->finance);
        $manager = $this->staff('DMH005', $this->headOffice, $this->finance, ['departmental_manager']);
        $chief = $this->staff('CM012', $this->headOffice, $this->finance, ['chief_manager']);
        // Right roles, wrong scope.
        $otherDepartmentManager = $this->staff('DMH006', $this->headOffice, $this->operations, ['departmental_manager']);
        $otherDepartmentChief = $this->staff('CM013', $this->headOffice, $this->operations, ['chief_manager']);
        $regionalManager = $this->staff('DMR004', $this->accraOffice, $this->finance, ['departmental_manager']);
        $employeeOnly = $this->staff('EMP017', $this->headOffice, $this->finance);

        $request = $this->submitLeave($applicant);

        foreach ([$otherDepartmentManager, $regionalManager, $chief, $employeeOnly] as $outsider) {
            $this->assertRefused(fn () => $this->workflow()->recommend($outsider, $request->fresh(), null, true));
        }

        $this->workflow()->recommend($manager, $request->fresh(), null, true);

        foreach ([$otherDepartmentChief, $manager, $employeeOnly] as $outsider) {
            $this->assertRefused(fn () => $this->workflow()->finalDecision($outsider, $request->fresh(), null, true));
        }

        $this->assertSame('Pending Approval', $request->fresh()->leave_status);
    }

    public function test_out_of_scope_role_holders_get_a_403_in_the_approvals_screen_and_do_not_see_the_request(): void
    {
        $applicant = $this->staff('EMP018', $this->temaDistrict, $this->operations);
        $manager = $this->staff('DM016', $this->temaDistrict, $this->operations, ['district_manager']);
        $outsider = $this->staff('DM017', $this->kumasiDistrict, $this->operations, ['district_manager']);
        $this->staff('RCM016', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $request = $this->submitLeave($applicant);

        Livewire::actingAs($this->userOf($outsider))
            ->test(Approvals::class)
            ->assertDontSee('Employee EMP018')
            ->call('approveRequest', $request->id)
            ->assertForbidden();

        Livewire::actingAs($this->userOf($manager))
            ->test(Approvals::class)
            ->assertSee('Employee EMP018')
            ->call('approveRequest', $request->id)
            ->assertHasNoErrors();

        $this->assertSame('Recommended', $request->fresh()->manager_recommendation);
    }

    public function test_the_approvals_screen_refuses_a_request_someone_else_already_actioned_instead_of_applying_it_twice(): void
    {
        $applicant = $this->staff('EMP019', $this->temaDistrict, $this->operations);
        $managerA = $this->staff('DM018', $this->temaDistrict, $this->operations, ['district_manager']);
        $managerB = $this->staff('DM019', $this->temaDistrict, $this->operations, ['district_manager']);
        $this->staff('RCM017', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $request = $this->submitLeave($applicant);

        $screenB = Livewire::actingAs($this->userOf($managerB))->test(Approvals::class)->assertSee('Employee EMP019');

        Livewire::actingAs($this->userOf($managerA))->test(Approvals::class)->call('denyRequest', $request->id);
        $this->assertSame('Denied', $request->fresh()->leave_status);

        // B's screen still lists it; acting on it now is refused with a message, not a crash or a second decision.
        $screenB->call('approveRequest', $request->id)->assertHasErrors('action');
        $this->assertSame('Denied', $request->fresh()->leave_status);
        $this->assertSame($managerA->id, $request->fresh()->manager_id);
    }

    public function test_an_approver_snapshotted_at_submission_loses_the_right_to_act_once_they_are_deactivated(): void
    {
        $applicant = $this->staff('EMP020', $this->temaDistrict, $this->operations);
        $snapshotted = $this->staff('DM020', $this->temaDistrict, $this->operations, ['district_manager']);
        $this->staff('RCM018', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $request = $this->submitLeave($applicant);

        $this->userOf($snapshotted)->update(['is_active' => false]);
        $replacement = $this->staff('DM021', $this->temaDistrict, $this->operations, ['district_manager']);

        // Not the snapshot, but the resolver finds them at action time; the snapshotted user no longer counts.
        $this->assertRefused(fn () => $this->workflow()->recommend($snapshotted, $request->fresh(), null, true));
        $this->workflow()->recommend($replacement, $request->fresh(), null, true);

        $this->assertSame('Recommended', $request->fresh()->manager_recommendation);
    }

    public function test_an_approver_who_lost_the_role_loses_the_right_to_act(): void
    {
        $applicant = $this->staff('EMP021', $this->temaDistrict, $this->operations);
        $manager = $this->staff('DM022', $this->temaDistrict, $this->operations, ['district_manager']);
        $this->staff('RCM019', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $request = $this->submitLeave($applicant);

        $this->userOf($manager)->roles()->detach();

        $this->assertRefused(fn () => $this->workflow()->recommend($manager, $request->fresh(), null, true));
    }

    public function test_a_recommendation_is_refused_when_nobody_could_take_the_final_stage(): void
    {
        $applicant = $this->staff('EMP022', $this->temaDistrict, $this->operations);
        $manager = $this->staff('DM023', $this->temaDistrict, $this->operations, ['district_manager']);
        $chief = $this->staff('RCM020', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $request = $this->submitLeave($applicant);

        $this->userOf($chief)->update(['is_active' => false]);

        $this->assertThrows(
            fn () => $this->workflow()->recommend($manager, $request->fresh(), null, true),
            \RuntimeException::class,
            'No active approver'
        );
        $this->assertSame('Pending', $request->fresh()->manager_recommendation);
    }

    public function test_super_admin_bypasses_scope_but_not_the_stage_checks(): void
    {
        $applicant = $this->staff('EMP023', $this->temaDistrict, $this->operations);
        $this->staff('DM024', $this->temaDistrict, $this->operations, ['district_manager']);
        $this->staff('RCM021', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $superAdmin = $this->staff('SA002', $this->kumasiOffice, $this->finance, ['super_admin']);
        $request = $this->submitLeave($applicant);

        $this->assertThrows(
            fn () => $this->workflow()->finalDecision($superAdmin, $request->fresh(), null, true),
            \RuntimeException::class,
            'must be recommended'
        );

        $this->workflow()->recommend($superAdmin, $request->fresh(), null, true);
        $this->workflow()->finalDecision($superAdmin, $request->fresh(), null, true);

        $this->assertSame('Approved', $request->fresh()->leave_status);
        $this->assertSame($superAdmin->id, $request->fresh()->approved_by_id);
    }

    // ---------------------------------------------------------------- Balance and existing rules

    public function test_balance_is_deducted_only_at_final_approval(): void
    {
        $applicant = $this->staff('EMP024', $this->temaDistrict, $this->operations);
        $manager = $this->staff('DM025', $this->temaDistrict, $this->operations, ['district_manager']);
        $chief = $this->staff('RCM022', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $request = $this->submitLeave($applicant);
        $this->assertSame(0, LeaveBalance::query()->count());

        $this->workflow()->recommend($manager, $request->fresh(), null, true);
        $this->assertSame(0, LeaveBalance::query()->count());

        $this->workflow()->finalDecision($chief, $request->fresh(), null, true);
        $this->assertSame(3, (int) LeaveBalance::query()->where('employee_id', $applicant->id)->value('used_days'));
    }

    public function test_a_denial_deducts_nothing(): void
    {
        $applicant = $this->staff('EMP025', $this->temaDistrict, $this->operations);
        $manager = $this->staff('DM026', $this->temaDistrict, $this->operations, ['district_manager']);
        $chief = $this->staff('RCM023', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $request = $this->submitLeave($applicant);
        $this->workflow()->recommend($manager, $request->fresh(), null, true);
        $this->workflow()->finalDecision($chief, $request->fresh(), 'Not now', false);

        $this->assertSame('Denied', $request->fresh()->leave_status);
        $this->assertSame(0, LeaveBalance::query()->count());
    }

    public function test_single_stage_approval_deducts_the_balance_once(): void
    {
        $manager = $this->staff('DM027', $this->temaDistrict, $this->operations, ['district_manager']);
        $chief = $this->staff('RCM024', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $request = $this->submitLeave($manager);
        $this->assertSame(0, LeaveBalance::query()->count());

        $this->workflow()->finalDecision($chief, $request->fresh(), null, true);

        $this->assertSame(3, (int) LeaveBalance::query()->where('employee_id', $manager->id)->value('used_days'));
    }

    public function test_requests_already_in_flight_before_the_snapshot_columns_still_flow(): void
    {
        $applicant = $this->staff('EMP028', $this->temaDistrict, $this->operations);
        $manager = $this->staff('DM031', $this->temaDistrict, $this->operations, ['district_manager']);
        $outsider = $this->staff('DM032', $this->kumasiDistrict, $this->operations, ['district_manager']);
        $chief = $this->staff('RCM029', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        // As submitted before the migration: manager_id (an employee) set, no user snapshot, not flagged single-stage.
        $request = LeaveRequest::query()->create([
            'requester_id' => $applicant->id,
            'leave_type' => 'Annual',
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-07',
            'total_days_applied' => 3,
            'manager_id' => $manager->id,
            'manager_recommendation' => 'Pending',
            'leave_status' => 'Pending Approval',
            'request_year' => 2026,
            'department_id' => $applicant->department_id,
            'region_id' => $applicant->region_id,
        ]);
        $this->assertNull($request->manager_user_id);

        Livewire::actingAs($this->userOf($manager))->test(Approvals::class)->assertSee('Employee EMP028');
        Livewire::actingAs($this->userOf($outsider))->test(Approvals::class)->assertDontSee('Employee EMP028');

        $this->assertRefused(fn () => $this->workflow()->recommend($outsider, $request->fresh(), null, true));
        $this->workflow()->recommend($manager, $request->fresh(), null, true);
        $this->workflow()->finalDecision($chief, $request->fresh(), null, true);

        $this->assertSame('Approved', $request->fresh()->leave_status);
    }

    public function test_any_holder_can_decide_a_single_stage_request_and_the_record_names_who_did(): void
    {
        $manager = $this->staff('DM030', $this->temaDistrict, $this->operations, ['district_manager']);
        $firstChief = $this->staff('RCM027', $this->accraOffice, $this->operations, ['regional_chief_manager']);
        $secondChief = $this->staff('RCM028', $this->accraOffice, $this->finance, ['regional_chief_manager']);

        $request = $this->submitLeave($manager);
        $this->assertSame($firstChief->id, $request->manager_id);
        $this->assertInAppSent($firstChief, 'leave_submitted');
        $this->assertInAppSent($secondChief, 'leave_submitted');

        $this->workflow()->finalDecision($secondChief, $request->fresh(), null, true);

        $request->refresh();
        $this->assertSame($secondChief->id, $request->approved_by_id);
        $this->assertSame($secondChief->id, $request->manager_id);
        $this->assertSame($this->userOf($secondChief)->id, $request->chief_user_id);
        $this->assertThrows(fn () => $this->workflow()->finalDecision($firstChief, $request->fresh(), null, true), LeaveAlreadyActionedException::class);
    }

    public function test_casual_leave_is_still_blocked_while_annual_leave_remains(): void
    {
        $applicant = $this->staff('EMP026', $this->temaDistrict, $this->operations);
        $this->staff('DM028', $this->temaDistrict, $this->operations, ['district_manager']);
        $this->staff('RCM025', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        $this->assertThrows(
            fn () => $this->submitLeave($applicant, ['leave_type' => 'Casual']),
            \RuntimeException::class,
            'Casual leave is not allowed'
        );
    }

    public function test_working_days_exclude_weekends(): void
    {
        $applicant = $this->staff('EMP027', $this->temaDistrict, $this->operations);
        $this->staff('DM029', $this->temaDistrict, $this->operations, ['district_manager']);
        $this->staff('RCM026', $this->accraOffice, $this->operations, ['regional_chief_manager']);

        // Mon 5 Oct to Mon 12 Oct 2026: six working days.
        $request = $this->submitLeave($applicant, ['start_date' => '2026-10-05', 'end_date' => '2026-10-12']);

        $this->assertSame(6, (int) $request->total_days_applied);
    }

    // ---------------------------------------------------------------- helpers

    protected function assertRefused(\Closure $action): void
    {
        $this->assertThrows($action, AuthorizationException::class);
    }

    /**
     * Submitting is refused with a ValidationException naming the missing role, nothing is saved, and the
     * attempt is audited.
     */
    protected function assertBlockedSubmission(Employee $applicant, string $messageFragment, string $missingRole): void
    {
        $before = LeaveRequest::query()->count();

        try {
            $this->submitLeave($applicant);
            $this->fail('The submission should have been blocked.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($messageFragment, $e->errors()['leave_type'][0]);
        }

        $this->assertSame($before, LeaveRequest::query()->count());

        $log = DB::table('audit_logs')->where('action', 'leave_submission_blocked')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('leave', $log->module);
        $this->assertSame($applicant->id, (int) $log->target_id);
        $this->assertSame($missingRole, json_decode($log->metadata, true)['missing_role']);
    }

    protected function assertInAppSent(Employee $employee, string $type): void
    {
        Notification::assertSentTo(
            $this->userOf($employee),
            GeneralDatabaseNotification::class,
            fn ($notification, $channels) => ($notification->toArray($employee)['type'] ?? null) === $type
        );
    }

    protected function assertInAppNotSent(Employee $employee, string $type): void
    {
        Notification::assertNotSentTo(
            $this->userOf($employee),
            GeneralDatabaseNotification::class,
            fn ($notification, $channels) => ($notification->toArray($employee)['type'] ?? null) === $type
        );
    }
}
