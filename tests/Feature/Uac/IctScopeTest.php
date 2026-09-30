<?php

namespace Tests\Feature\Uac;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Assets\Mdm\MdmAccessGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * The ICT team works inside one location scope — Head Office, or one region — and may assign or remove only the
 * roles flagged ict_assignable, only to users in that scope. Head Office is a district whose staff share the Head
 * Office district's region_id, so "same region" is never enough: a regional ICT user never reaches Head Office staff.
 */
class IctScopeTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected User $regionalIct;

    protected User $headOfficeIct;

    protected User $globalAdmin;

    protected User $super;

    protected User $temaUser;

    protected User $accraOfficeUser;

    protected User $kumasiUser;

    protected User $headOfficeUser;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->buildUacOrg();

        $this->super = $this->person('SA001', $this->headOffice, ['super_admin']);
        $this->globalAdmin = $this->person('GA001', $this->headOffice, ['admin']);

        // Greater Accra holds both Head Office and the regional office / Tema, so the two scopes share a region_id.
        $this->regionalIct = $this->person('ICT001', $this->temaDistrict, ['ict_team']);
        $this->headOfficeIct = $this->person('ICT002', $this->headOffice, ['ict_team']);

        $this->temaUser = $this->person('EMP001', $this->temaDistrict);
        $this->accraOfficeUser = $this->person('EMP002', $this->accraOffice);
        $this->kumasiUser = $this->person('EMP003', $this->kumasiOffice);
        $this->headOfficeUser = $this->person('EMP004', $this->headOffice);
    }

    // ---------------------------------------------------------------- Regional ICT

    public function test_a_regional_ict_user_can_assign_an_assignable_role_inside_their_own_region(): void
    {
        foreach ([$this->temaUser, $this->accraOfficeUser] as $target) {
            $this->patchRoles($this->regionalIct, $target, ['manager', 'secretary'])->assertSessionHasNoErrors();
            $this->assertEqualsCanonicalizing(['manager', 'secretary'], $target->fresh()->roles->pluck('name')->all());
        }
    }

    public function test_a_regional_ict_user_is_refused_for_another_region_and_for_head_office_staff_in_their_own_region(): void
    {
        foreach ([$this->kumasiUser, $this->headOfficeUser] as $target) {
            $this->patchRoles($this->regionalIct, $target, ['manager'])->assertForbidden();
            $this->assertSame(0, $target->fresh()->roles()->count(), $target->staff_id);
        }
    }

    public function test_a_regional_ict_user_cannot_assign_roles_that_are_not_ict_assignable(): void
    {
        foreach (['admin', 'managing_director', 'ict_team', 'credit_union_officer', 'credit_union_committee', 'super_admin'] as $role) {
            $this->patchRoles($this->regionalIct, $this->temaUser, [$role])->assertSessionHasErrors('roles.0');
            $this->assertFalse($this->temaUser->fresh()->hasRoles($role), $role);
        }

        $this->patchRoles($this->regionalIct, $this->temaUser, ['admin'])
            ->assertSessionHasErrors(['roles.0' => 'You cannot grant Global Admin: it is above your own level.']);
        $this->patchRoles($this->regionalIct, $this->temaUser, ['managing_director'])
            ->assertSessionHasErrors(['roles.0' => 'Managing Director can only be assigned by a Global Admin.']);
    }

    // ---------------------------------------------------------------- Head Office ICT

    public function test_a_head_office_ict_user_manages_head_office_users_only(): void
    {
        $this->patchRoles($this->headOfficeIct, $this->headOfficeUser, ['hr_headoffice', 'chief_manager', 'secretary'])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['hr_headoffice', 'chief_manager', 'secretary'], $this->headOfficeUser->fresh()->roles->pluck('name')->all());

        // The regional office sits in the same region as Head Office: still not theirs.
        foreach ([$this->accraOfficeUser, $this->temaUser, $this->kumasiUser] as $target) {
            $this->patchRoles($this->headOfficeIct, $target, ['secretary'])->assertForbidden();
            $this->assertSame(0, $target->fresh()->roles()->count(), $target->staff_id);
        }
    }

    public function test_a_head_office_ict_user_cannot_assign_admin_managing_director_or_ict_team(): void
    {
        foreach (['admin', 'managing_director', 'ict_team'] as $role) {
            $this->patchRoles($this->headOfficeIct, $this->headOfficeUser, [$role])->assertSessionHasErrors('roles.0');
            $this->assertFalse($this->headOfficeUser->fresh()->hasRoles($role), $role);
        }
    }

    public function test_a_scoped_ict_user_can_never_change_their_own_roles(): void
    {
        foreach ([$this->regionalIct, $this->headOfficeIct] as $ict) {
            $this->patchRoles($ict, $ict, ['ict_team', 'manager'])->assertForbidden();
            $this->assertFalse($ict->fresh()->hasRoles('manager'));
        }
    }

    // ---------------------------------------------------------------- Role and location must fit

    public function test_a_role_that_does_not_fit_the_targets_location_is_refused_for_everyone(): void
    {
        $mismatches = [
            ['hr_headoffice', $this->temaUser, 'Hr Headoffice can only be given to Head Office staff.'],
            ['hr_headoffice', $this->accraOfficeUser, 'Hr Headoffice can only be given to Head Office staff.'],
            ['chief_manager', $this->kumasiUser, 'Chief Manager can only be given to Head Office staff.'],
            ['hr_region', $this->headOfficeUser, 'Hr Region can only be given to regional or district staff, not Head Office staff.'],
            ['regional_chief_manager', $this->headOfficeUser, 'Regional Chief Manager can only be given to regional or district staff, not Head Office staff.'],
            ['district_manager', $this->headOfficeUser, 'District Manager can only be given to regional or district staff, not Head Office staff.'],
        ];

        foreach ([$this->globalAdmin, $this->super] as $actor) {
            foreach ($mismatches as [$role, $target, $message]) {
                $this->patchRoles($actor, $target, [$role])->assertSessionHasErrors(['roles.0' => $message]);
                $this->assertFalse($target->fresh()->hasRoles($role), "{$actor->staff_id}: {$role} to {$target->staff_id}");
            }
        }

        // ...and for the ICT team, on top of its own limits.
        $this->patchRoles($this->regionalIct, $this->temaUser, ['hr_headoffice'])->assertSessionHasErrors('roles.0');
        $this->patchRoles($this->headOfficeIct, $this->headOfficeUser, ['hr_region'])->assertSessionHasErrors('roles.0');
    }

    public function test_roles_that_do_fit_are_assigned(): void
    {
        $this->patchRoles($this->globalAdmin, $this->kumasiUser, ['hr_region', 'regional_chief_manager', 'district_manager'])->assertSessionHasNoErrors();
        $this->patchRoles($this->globalAdmin, $this->headOfficeUser, ['hr_headoffice', 'chief_manager'])->assertSessionHasNoErrors();
        $this->patchRoles($this->globalAdmin, $this->temaUser, ['departmental_manager', 'manager'])->assertSessionHasNoErrors();
        $this->patchRoles($this->globalAdmin, $this->headOfficeUser, ['hr_headoffice', 'chief_manager', 'departmental_manager'])->assertSessionHasNoErrors();

        $this->assertTrue($this->kumasiUser->fresh()->hasRoles('hr_region', 'regional_chief_manager', 'district_manager'));
        $this->assertTrue($this->headOfficeUser->fresh()->hasRoles('departmental_manager'));
    }

    // ---------------------------------------------------------------- Removing, and what stays

    public function test_editing_a_user_only_touches_the_roles_the_actor_manages(): void
    {
        $target = $this->person('EMP010', $this->headOffice, ['ict_team', 'manager', 'employee', 'admin']);

        // The form only carries the roles ICT may manage; submitting none removes just those.
        $this->patchRoles($this->headOfficeIct, $target, [])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['ict_team', 'employee', 'admin'], $target->fresh()->roles->pluck('name')->all());

        $removed = AuditLog::query()->where('action', 'role_removed')->sole();
        $this->assertSame('manager', $removed->metadata['role']);
        $this->assertSame($this->headOfficeIct->id, $removed->user_id);
    }

    public function test_global_admin_editing_a_user_leaves_the_implicit_employee_role_alone(): void
    {
        $target = $this->person('EMP011', $this->temaDistrict, ['employee', 'manager']);

        $this->patchRoles($this->globalAdmin, $target, ['secretary'])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['employee', 'secretary'], $target->fresh()->roles->pluck('name')->all());
    }

    public function test_every_assignment_and_removal_is_audited_with_old_new_actor_and_target_scope(): void
    {
        $this->patchRoles($this->regionalIct, $this->temaUser, ['manager'])->assertSessionHasNoErrors();
        $this->patchRoles($this->regionalIct, $this->temaUser, ['secretary'])->assertSessionHasNoErrors();

        $assigned = AuditLog::query()->where('action', 'role_assigned')->orderBy('id')->get();
        $removed = AuditLog::query()->where('action', 'role_removed')->sole();

        $this->assertSame(['manager', 'secretary'], $assigned->pluck('metadata.role')->all());
        $this->assertSame($this->regionalIct->id, $assigned->first()->user_id);
        $this->assertSame([], $assigned->first()->old_values['roles']);
        $this->assertSame(['manager'], $assigned->first()->new_values['roles']);
        $this->assertSame('District', $assigned->first()->metadata['target_scope']['location_type']);
        $this->assertSame('Greater Accra', $assigned->first()->metadata['target_scope']['region']);
        $this->assertSame('Tema District', $assigned->first()->metadata['target_scope']['district']);
        $this->assertSame($this->temaUser->id, (int) $assigned->first()->target_id);

        $this->assertSame('manager', $removed->metadata['role']);
        $this->assertSame(['manager'], $removed->old_values['roles']);
        $this->assertSame(['secretary'], $removed->new_values['roles']);
    }

    // ---------------------------------------------------------------- Lists, search, creation

    public function test_user_lists_are_scoped_by_location(): void
    {
        $this->actingAs($this->regionalIct)->get(route('uac.users'))
            ->assertOk()
            ->assertSee('emp001@example.com')
            ->assertSee('emp002@example.com')
            ->assertDontSee('emp003@example.com')
            ->assertDontSee('emp004@example.com');

        $this->actingAs($this->headOfficeIct)->get(route('uac.users'))
            ->assertOk()
            ->assertSee('emp004@example.com')
            ->assertSee('ga001@example.com')
            ->assertDontSee('emp001@example.com')
            ->assertDontSee('emp002@example.com')
            ->assertDontSee('emp003@example.com');

        $this->actingAs($this->globalAdmin)->get(route('uac.users'))
            ->assertOk()
            ->assertSee('emp001@example.com')
            ->assertSee('emp003@example.com')
            ->assertSee('emp004@example.com');
    }

    public function test_employee_search_is_scoped_by_location(): void
    {
        $staffIds = fn (User $viewer) => collect($this->actingAs($viewer)->getJson(route('uac.employees.search', ['q' => 'EMP0']))->assertOk()->json())
            ->pluck('staff_id')->sort()->values()->all();

        $this->assertSame(['EMP001', 'EMP002'], $staffIds($this->regionalIct));
        $this->assertSame(['EMP004'], $staffIds($this->headOfficeIct));
        $this->assertSame(['EMP001', 'EMP002', 'EMP003', 'EMP004'], $staffIds($this->globalAdmin));
    }

    public function test_the_create_dialog_is_only_offered_roles_that_fit_the_chosen_employee(): void
    {
        $offered = fn (User $viewer, string $staffId) => collect($this->actingAs($viewer)->getJson(route('uac.employees.search', ['q' => $staffId]))->json('0.assignable_role_ids'))
            ->map(fn ($id) => $this->roleNameById($id))->sort()->values()->all();

        $regional = $offered($this->regionalIct, 'EMP001');
        $this->assertContains('manager', $regional);
        $this->assertContains('hr_region', $regional);
        $this->assertContains('district_manager', $regional);
        $this->assertNotContains('hr_headoffice', $regional, 'Head Office HR does not fit a district employee.');
        $this->assertNotContains('admin', $regional);
        $this->assertNotContains('managing_director', $regional);
        $this->assertNotContains('employee', $regional);

        $headOffice = $offered($this->headOfficeIct, 'EMP004');
        $this->assertContains('hr_headoffice', $headOffice);
        $this->assertContains('chief_manager', $headOffice);
        $this->assertNotContains('hr_region', $headOffice);

        $globalAdmin = $offered($this->globalAdmin, 'EMP004');
        $this->assertContains('admin', $globalAdmin);
        $this->assertContains('managing_director', $globalAdmin);
        $this->assertContains('credit_union_officer', $globalAdmin);
        $this->assertNotContains('super_admin', $globalAdmin);
    }

    public function test_creating_a_user_is_limited_to_employees_in_the_actors_scope(): void
    {
        $inScope = $this->makeEmployee('EMP020', $this->accraOffice);
        $headOffice = $this->makeEmployee('EMP021', $this->headOffice);
        $otherRegion = $this->makeEmployee('EMP022', $this->kumasiOffice);

        foreach ([$headOffice, $otherRegion] as $employee) {
            $this->actingAs($this->regionalIct)->post(route('uac.users.store'), ['employee_id' => $employee->id, 'roles' => []])->assertForbidden();
            $this->assertNull(User::query()->where('staff_id', $employee->staff_id)->first());
        }

        $this->actingAs($this->regionalIct)
            ->post(route('uac.users.store'), ['employee_id' => $inScope->id, 'roles' => $this->roleIds(['manager'])])
            ->assertSessionHasNoErrors();
        $this->assertTrue(User::query()->where('staff_id', 'EMP020')->firstOrFail()->hasRoles('manager'));

        // A refused role rolls the whole creation back.
        $this->actingAs($this->headOfficeIct)
            ->from(route('uac.users'))
            ->post(route('uac.users.store'), ['employee_id' => $headOffice->id, 'roles' => $this->roleIds(['admin'])])
            ->assertSessionHasErrors('roles.0');
        $this->assertNull(User::query()->where('staff_id', 'EMP021')->first());
    }

    public function test_a_scoped_ict_user_cannot_change_the_status_of_an_account_above_their_level(): void
    {
        $this->actingAs($this->headOfficeIct);

        $this->patch(route('uac.users.toggle-status', $this->globalAdmin))->assertForbidden();
        $this->assertTrue($this->globalAdmin->fresh()->is_active);

        $this->patch(route('uac.users.toggle-status', $this->headOfficeUser))->assertRedirect();
        $this->assertFalse($this->headOfficeUser->fresh()->is_active);

        // Out of scope: refused as well.
        $this->patch(route('uac.users.toggle-status', $this->temaUser))->assertForbidden();
        $this->assertTrue($this->temaUser->fresh()->is_active);
    }

    // ---------------------------------------------------------------- The scope helper

    public function test_ict_scope_is_head_office_or_a_region_and_fails_closed(): void
    {
        $this->assertSame(['type' => 'head_office', 'region_id' => null], $this->headOfficeIct->ictScope());
        $this->assertSame(['type' => 'region', 'region_id' => $this->accra->id], $this->regionalIct->ictScope());
        $this->assertSame(['type' => 'region', 'region_id' => $this->ashanti->id], $this->person('ICT003', $this->obuasiDistrict, ['ict_team'])->ictScope());
        $this->assertSame(['type' => 'none', 'region_id' => null], $this->person('ICT004', $this->headOffice, ['ict_team'], withEmployee: false)->ictScope());

        // Not scoped: Global Admin, super_admin, anyone who isn't ICT — and an ICT user who is also a Global Admin.
        $this->assertNull($this->globalAdmin->ictScope());
        $this->assertNull($this->super->ictScope());
        $this->assertNull($this->temaUser->ictScope());
        $this->assertNull($this->person('ICT005', $this->headOffice, ['ict_team', 'admin'])->ictScope());

        // The old name survives as a wrapper wherever it was copied.
        $this->assertTrue(app(MdmAccessGuard::class)->isRegionScopedIct($this->regionalIct));
        $this->assertFalse(app(MdmAccessGuard::class)->isRegionScopedIct($this->globalAdmin));
    }

    public function test_an_ict_user_with_no_employee_record_manages_nobody(): void
    {
        $orphan = $this->person('ICT006', $this->headOffice, ['ict_team'], withEmployee: false);

        $this->actingAs($orphan)->get(route('uac.users'))->assertOk()->assertDontSee('emp004@example.com');
        $this->patchRoles($orphan, $this->headOfficeUser, ['manager'])->assertForbidden();
    }

    protected function roleNameById(int|string $id): string
    {
        return \App\Models\Role::query()->whereKey($id)->value('name');
    }
}
