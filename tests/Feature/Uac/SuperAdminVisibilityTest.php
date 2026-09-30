<?php

namespace Tests\Feature\Uac;

use App\Livewire\Uac\RoleAccessManager;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * super_admin is a developer back-end account: everyone else must not see it (lists, roles, employee contexts,
 * counts) or its audit rows, except role/access changes, which show with the actor as "System". Only super_admin
 * sees and manages super_admin.
 */
class SuperAdminVisibilityTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected User $super;

    protected User $globalAdmin;

    protected User $ict;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->buildUacOrg();

        // The developer account has an employee record here, to prove employee contexts hide it too.
        $this->super = $this->person('SA001', $this->headOffice, ['super_admin']);
        $this->globalAdmin = $this->person('GA001', $this->headOffice, ['admin']);
        $this->ict = $this->person('ICT001', $this->headOffice, ['ict_team']);
    }

    public function test_super_admin_accounts_are_hidden_from_user_lists_for_everyone_but_a_super_admin(): void
    {
        $this->actingAs($this->globalAdmin)->get(route('uac.users'))
            ->assertOk()
            ->assertSee('ict001@example.com')
            ->assertDontSee('sa001@example.com');

        $this->actingAs($this->ict)->get(route('uac.users'))
            ->assertOk()
            ->assertDontSee('sa001@example.com');

        $this->actingAs($this->super)->get(route('uac.users'))
            ->assertOk()
            ->assertSee('sa001@example.com')
            ->assertSee('ga001@example.com');
    }

    public function test_super_admin_is_left_out_of_the_dashboard_counts_and_role_lists_of_everyone_else(): void
    {
        $forGlobalAdmin = $this->actingAs($this->globalAdmin)->get(route('uac.index'))->viewData('stats');
        $forSuper = $this->actingAs($this->super)->get(route('uac.index'))->viewData('stats');

        // Users: super_admin, Global Admin and ICT exist; the Global Admin sees two of the three.
        $this->assertSame(2, $forGlobalAdmin['users']);
        $this->assertSame(3, $forSuper['users']);
        // Roles: the Super Admin role is counted only for a super_admin (the implicit Employee role never is).
        $this->assertSame($forGlobalAdmin['roles'] + 1, $forSuper['roles']);

        $listed = fn (User $viewer) => collect(Livewire::actingAs($viewer)->test(RoleAccessManager::class)->get('roles'))->pluck('name');

        $this->assertNotContains('super_admin', $listed($this->globalAdmin));
        $this->assertNotContains('employee', $listed($this->globalAdmin));
        $this->assertContains('super_admin', $listed($this->super));
        $this->assertNotContains('employee', $listed($this->super));
    }

    public function test_super_admin_is_hidden_from_employee_search_and_staff_profiles(): void
    {
        $hr = $this->person('HR001', $this->headOffice, ['hr_headoffice']);

        $this->actingAs($this->globalAdmin)->getJson(route('uac.employees.search', ['q' => 'SA001']))
            ->assertOk()->assertJsonCount(0);
        $this->actingAs($this->super)->getJson(route('uac.employees.search', ['q' => 'SA001']))
            ->assertOk()->assertJsonCount(1);

        $this->actingAs($hr)->getJson(route('staff.users.show', $this->super))->assertNotFound();
        $this->actingAs($this->super)->getJson(route('staff.users.show', $this->super))->assertOk();
    }

    public function test_only_a_super_admin_can_open_or_manage_another_super_admin(): void
    {
        $other = $this->person('SA002', $this->headOffice, ['super_admin']);

        foreach ([$this->globalAdmin, $this->ict] as $viewer) {
            $this->actingAs($viewer);
            $this->getJson(route('uac.users.show', $other))->assertNotFound();
            $this->patch(route('uac.users.update', $other), ['roles' => $this->roleIds(['secretary'])])->assertNotFound();
            $this->patch(route('uac.users.toggle-status', $other))->assertNotFound();
            $this->post(route('uac.users.invite', $other))->assertNotFound();
        }

        $this->assertTrue($other->fresh()->is_active);
        $this->assertFalse($other->fresh()->hasRoles('secretary'));

        $this->actingAs($this->super);
        $this->getJson(route('uac.users.show', $other))->assertOk();
        $this->patch(route('uac.users.toggle-status', $other))->assertRedirect();
        $this->assertFalse($other->fresh()->is_active);
        $this->patch(route('uac.users.update', $other), ['roles' => $this->roleIds(['super_admin', 'secretary'])])->assertRedirect();
        $this->assertTrue($other->fresh()->hasRoles('secretary'));
    }

    // ---------------------------------------------------------------- Audit rows

    public function test_a_super_admins_audit_rows_are_invisible_to_everyone_else_in_one_place(): void
    {
        $this->actingAs($this->super);
        AuditLog::record('developer_maintenance', 'system');
        $this->actingAs($this->globalAdmin);
        AuditLog::record('export_employees', 'staff');

        $this->assertSame(['export_employees'], AuditLog::query()->visibleTo($this->globalAdmin)->pluck('action')->all());
        $this->assertSame(['export_employees'], AuditLog::query()->visibleTo($this->ict)->pluck('action')->all());
        $this->assertEqualsCanonicalizing(['developer_maintenance', 'export_employees'], AuditLog::query()->visibleTo($this->super)->pluck('action')->all());

        // The dashboard's recent activity and its count go through the same scope.
        $this->actingAs($this->globalAdmin)->get(route('uac.index'))
            ->assertOk()
            ->assertSee('export_employees')
            ->assertDontSee('developer_maintenance');
        $this->assertSame(1, $this->actingAs($this->globalAdmin)->get(route('uac.index'))->viewData('stats')['audit_logs']);
        $this->assertSame(2, $this->actingAs($this->super)->get(route('uac.index'))->viewData('stats')['audit_logs']);

        // The audit page itself is a super_admin page.
        $this->actingAs($this->globalAdmin)->get(route('uac.audit-log'))->assertForbidden();
        $this->actingAs($this->super)->get(route('uac.audit-log'))->assertOk()->assertSee('developer_maintenance');
    }

    public function test_role_changes_made_by_a_super_admin_show_to_others_with_the_actor_as_system(): void
    {
        $target = $this->person('EMP001', $this->headOffice);

        $this->actingAs($this->super)->patch(route('uac.users.update', $target), ['roles' => $this->roleIds(['secretary'])])
            ->assertRedirect();

        $row = AuditLog::query()->where('action', 'role_assigned')->sole();
        $this->assertTrue($row->actor_is_super_admin);

        $this->assertNotNull(AuditLog::query()->visibleTo($this->globalAdmin)->where('action', 'role_assigned')->first());
        $this->assertSame('System', $row->actorLabelFor($this->globalAdmin));
        $this->assertSame('System', $row->actorLabelFor($this->ict));
        $this->assertSame('Employee SA001', $row->actorLabelFor($this->super));

        $this->actingAs($this->globalAdmin)->get(route('uac.index'))
            ->assertOk()
            ->assertSee('role_assigned')
            ->assertSee('System')
            ->assertDontSee('Employee SA001');
    }

    public function test_giving_or_taking_the_super_admin_role_never_shows_to_others(): void
    {
        $target = $this->person('SA003', $this->headOffice);

        $this->actingAs($this->super)->patch(route('uac.users.update', $target), ['roles' => $this->roleIds(['super_admin'])])->assertRedirect();
        $this->actingAs($this->super)->patch(route('uac.users.update', $target), ['roles' => []])->assertRedirect();

        $this->assertSame(2, AuditLog::query()->whereIn('action', ['super_admin_role_assigned', 'super_admin_role_removed'])->count());
        $this->assertSame(0, AuditLog::query()->visibleTo($this->globalAdmin)->where('action', 'like', 'super_admin_%')->count());
        $this->assertSame(0, AuditLog::query()->visibleTo($this->globalAdmin)->where('action', 'like', 'role_%')->count());
    }

    public function test_hidden_rows_stay_hidden_after_the_actor_is_demoted_or_deleted(): void
    {
        $this->actingAs($this->super);
        AuditLog::record('developer_maintenance', 'system');

        $this->super->roles()->detach();
        $this->assertSame(0, AuditLog::query()->visibleTo($this->globalAdmin)->where('action', 'developer_maintenance')->count());

        $this->super->delete();
        $row = AuditLog::query()->where('action', 'developer_maintenance')->sole();
        $this->assertNull($row->user_id);
        $this->assertTrue($row->actor_is_super_admin);
        $this->assertSame(0, AuditLog::query()->visibleTo($this->globalAdmin)->where('action', 'developer_maintenance')->count());
    }

    public function test_other_peoples_rows_are_shown_under_their_own_names(): void
    {
        $this->actingAs($this->globalAdmin);
        AuditLog::record('export_employees', 'staff');

        $row = AuditLog::query()->sole();

        $this->assertFalse($row->actor_is_super_admin);
        $this->assertSame('Employee GA001', $row->actorLabelFor($this->ict));
    }
}
