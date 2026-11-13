<?php

namespace Tests\Feature\Uac;

use App\Livewire\Uac\RoleAccessManager;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Uac\RoleAssignmentService;
use App\Services\Uac\RoleGrantPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * The ICT Manager: an ICT account (one location scope, Head Office or one region) that hands out roles to the users of
 * its own location, ranks above the ICT team, never sees Global Admin or super_admin accounts, roles or audit rows, never
 * changes its own roles or another ICT Manager's, and never grants a tiered, protected or Head Office-only role. Only a
 * Global Admin or super_admin gives or changes an ICT Manager's roles.
 */
class IctManagerTest extends TestCase
{
    use BuildsUacOrg;
    use RefreshDatabase;

    protected User $super;

    protected User $globalAdmin;

    protected User $manager;

    protected User $otherManager;

    protected User $ictTeam;

    protected User $regional;

    protected User $tema;

    protected User $kumasi;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->buildUacOrg();

        $this->super = $this->person('SA001', $this->headOffice, ['super_admin']);
        $this->globalAdmin = $this->person('GA001', $this->headOffice, ['admin']);
        $this->manager = $this->person('ICM001', $this->accraOffice, ['ict_manager']);
        $this->otherManager = $this->person('ICM002', $this->accraOffice, ['ict_manager']);
        $this->ictTeam = $this->person('ICT001', $this->accraOffice, ['ict_team']);
        $this->regional = $this->person('EMP001', $this->accraOffice);
        $this->tema = $this->person('EMP002', $this->temaDistrict);
        $this->kumasi = $this->person('EMP003', $this->kumasiOffice);
    }

    // ---------------------------------------------------------------- The role itself

    public function test_the_role_exists_with_the_ict_team_permissions_and_is_not_ict_assignable(): void
    {
        $role = $this->role('ict_manager');

        $this->assertSame('ICT Manager', $role->display_name);
        $this->assertFalse((bool) $role->ict_assignable);
        $this->assertFalse((bool) $role->is_protected);

        $team = $this->role('ict_team')->permissions->pluck('name')->sort()->values()->all();
        $manager = $role->permissions->pluck('name')->sort()->values()->all();

        $this->assertSame($team, $manager);
        $this->assertContains('uac.assign_roles', $manager);
        $this->assertContains('assets.mdm_command', $manager);
        $this->assertNotContains('uac.manage_roles', $manager);
        $this->assertNotContains('uac.import_data', $manager);

        $this->assertEqualsCanonicalizing(['uac', 'assets', 'health_safety', 'blog', 'leave'], $this->manager->getAccessibleModules());
    }

    public function test_the_tiers_rank_the_ict_manager_between_the_global_admin_and_the_ict_team(): void
    {
        $policy = app(RoleGrantPolicy::class);

        $this->assertGreaterThan(User::TIER_ICT_MANAGER, User::TIER_GLOBAL_ADMIN);
        $this->assertGreaterThan(User::TIER_ICT, User::TIER_ICT_MANAGER);
        $this->assertSame(User::TIER_ICT_MANAGER, $this->manager->tier());
        $this->assertSame(User::TIER_ICT, $this->ictTeam->tier());
        $this->assertSame(User::TIER_ICT_MANAGER, $policy->roleTier('ict_manager'));
        $this->assertTrue($this->manager->isScopedIct());
        $this->assertTrue($this->manager->isIctManager());
        $this->assertFalse($this->ictTeam->isIctManager());
        $this->assertSame(['type' => 'region', 'region_id' => $this->accra->id], $this->manager->ictScope());
    }

    // ---------------------------------------------------------------- Reach

    public function test_an_ict_manager_sees_only_users_of_their_own_region(): void
    {
        $visible = app(RoleGrantPolicy::class)->usersQueryFor($this->manager)->pluck('staff_id')->all();

        $this->assertContains('EMP001', $visible);
        $this->assertContains('EMP002', $visible);
        $this->assertNotContains('EMP003', $visible);
        // Head Office shares the region id but is not part of a regional scope.
        $this->assertNotContains('SA001', $visible);
        $this->assertNotContains('GA001', $visible);

        $page = $this->actingAs($this->manager)->get(route('uac.users'))->assertOk();
        $page->assertSee('EMP001')->assertDontSee('EMP003')->assertDontSee('GA001')->assertDontSee('SA001');

        $employees = app(RoleGrantPolicy::class)->employeesQueryFor($this->manager)->pluck('staff_id')->all();
        $this->assertContains('EMP001', $employees);
        $this->assertNotContains('EMP003', $employees);
    }

    public function test_a_head_office_ict_manager_works_in_head_office_only(): void
    {
        $hoManager = $this->person('ICM010', $this->headOffice, ['ict_manager']);
        $hoStaff = $this->person('HO001', $this->headOffice);

        $this->assertSame(['type' => 'head_office', 'region_id' => null], $hoManager->ictScope());

        $visible = app(RoleGrantPolicy::class)->usersQueryFor($hoManager)->pluck('staff_id')->all();

        $this->assertContains('HO001', $visible);
        $this->assertNotContains('EMP001', $visible);
        // Even at Head Office the Global Admin and super_admin accounts stay hidden.
        $this->assertNotContains('GA001', $visible);
        $this->assertNotContains('SA001', $visible);

        $this->patchRoles($hoManager, $hoStaff, ['secretary'])->assertSessionHasNoErrors();
        $this->assertTrue($hoStaff->fresh()->hasRoles('secretary'));
        // A Head Office-only role is still refused for an ICT Manager.
        $this->patchRoles($hoManager, $hoStaff, ['secretary', 'hr_headoffice'])->assertSessionHasErrors('roles.1');
        $this->assertFalse($hoStaff->fresh()->hasRoles('hr_headoffice'));
    }

    public function test_a_user_in_another_region_cannot_be_reached_by_id(): void
    {
        $this->patchRoles($this->manager, $this->kumasi, ['secretary'])->assertForbidden();
        $this->assertFalse($this->kumasi->fresh()->hasRoles('secretary'));

        $this->actingAs($this->manager)->get(route('uac.users.show', $this->kumasi))->assertForbidden();
    }

    // ---------------------------------------------------------------- Never above their own level

    public function test_super_admin_and_global_admin_accounts_are_invisible_everywhere(): void
    {
        // A direct id is a 404, not a 403: the account does not exist for them.
        foreach ([$this->super, $this->globalAdmin] as $hidden) {
            $this->actingAs($this->manager)->get(route('uac.users.show', $hidden))->assertNotFound();
            $this->actingAs($this->manager)->patch(route('uac.users.update', $hidden), ['roles' => []])->assertNotFound();
            $this->actingAs($this->manager)->patch(route('uac.users.toggle-status', $hidden))->assertNotFound();
        }

        $this->assertFalse(User::query()->visibleTo($this->manager)->whereKey($this->globalAdmin->id)->exists());
        $this->assertFalse(User::query()->visibleTo($this->manager)->whereKey($this->super->id)->exists());
        $this->assertTrue(User::query()->visibleTo($this->super)->whereKey($this->globalAdmin->id)->exists());
        $this->assertTrue(User::query()->visibleTo($this->globalAdmin)->whereKey($this->globalAdmin->id)->exists());

        // Their staff records are invisible too (employee picker, search).
        $this->assertFalse(Employee::query()->visibleTo($this->manager)->where('staff_id', 'GA001')->exists());
        $this->assertTrue(Employee::query()->visibleTo($this->globalAdmin)->where('staff_id', 'GA001')->exists());

        $this->actingAs($this->manager)
            ->getJson(route('uac.employees.search', ['q' => 'GA001']))
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_global_admin_and_super_admin_roles_are_invisible_and_counts_follow(): void
    {
        $visible = Role::query()->visibleTo($this->manager)->pluck('name')->all();

        $this->assertNotContains('admin', $visible);
        $this->assertNotContains('super_admin', $visible);
        $this->assertContains('secretary', $visible);
        $this->assertContains('admin', Role::query()->visibleTo($this->globalAdmin)->pluck('name')->all());

        $policy = app(RoleGrantPolicy::class);
        $roleNames = $policy->rolesQueryFor($this->manager)->pluck('name')->all();

        $this->assertNotContains('admin', $roleNames);
        $this->assertNotContains('ict_manager', $roleNames);
        $this->assertNotContains('ict_team', $roleNames);
        $this->assertContains('hr_region', $roleNames);

        // The UAC dashboard's role count is the same list.
        $stats = $this->actingAs($this->manager)->get(route('uac.index'))->assertOk()->viewData('stats');
        $this->assertSame(count($roleNames), $stats['roles']);
        $this->assertSame($policy->usersQueryFor($this->manager)->count(), $stats['users']);

        // The roles filter on the users screen lists none of them either.
        $listed = $this->actingAs($this->manager)->get(route('uac.users'))->viewData('roles')->pluck('name')->all();
        $this->assertNotContains('admin', $listed);
        $this->assertNotContains('super_admin', $listed);
    }

    public function test_audit_rows_about_or_by_global_admin_and_super_admin_are_hidden(): void
    {
        $adminRole = $this->role('admin');
        $rows = [
            'by_admin' => AuditLog::create(['user_id' => $this->globalAdmin->id, 'action' => 'create_user', 'module' => 'uac.users', 'target_type' => 'users', 'target_id' => $this->regional->id, 'actor_is_super_admin' => false]),
            'about_admin_user' => AuditLog::create(['user_id' => $this->ictTeam->id, 'action' => 'update_user', 'module' => 'uac.users', 'target_type' => 'users', 'target_id' => $this->globalAdmin->id, 'actor_is_super_admin' => false]),
            'about_admin_role' => AuditLog::create(['user_id' => $this->ictTeam->id, 'action' => 'update_role_access', 'module' => 'uac.roles', 'target_type' => 'roles', 'target_id' => $adminRole->id, 'actor_is_super_admin' => false]),
            'by_super' => AuditLog::create(['user_id' => $this->super->id, 'action' => 'export_assets_excel', 'module' => 'assets', 'target_type' => 'ict_assets', 'target_id' => null, 'actor_is_super_admin' => true]),
            'ordinary' => AuditLog::create(['user_id' => $this->ictTeam->id, 'action' => 'role_assigned', 'module' => 'uac.users', 'target_type' => 'users', 'target_id' => $this->regional->id, 'actor_is_super_admin' => false]),
        ];

        $seenByManager = AuditLog::query()->visibleTo($this->manager)->pluck('id')->all();

        $this->assertSame([$rows['ordinary']->id], array_values(array_intersect($seenByManager, collect($rows)->pluck('id')->all())));

        // super_admin sees everything; a Global Admin keeps what they always saw.
        $this->assertCount(5, array_intersect(AuditLog::query()->visibleTo($this->super)->pluck('id')->all(), collect($rows)->pluck('id')->all()));
        $this->assertContains($rows['by_admin']->id, AuditLog::query()->visibleTo($this->globalAdmin)->pluck('id')->all());
    }

    public function test_an_ict_manager_cannot_change_their_own_roles_even_by_a_crafted_request(): void
    {
        // The request class refuses it...
        $this->patchRoles($this->manager, $this->manager, ['ict_manager', 'secretary'])->assertForbidden();
        $this->patchRoles($this->manager, $this->manager, [])->assertForbidden();
        $this->assertEqualsCanonicalizing(['ict_manager'], $this->manager->fresh()->roles->pluck('name')->all());

        // ...and so does the service, which is what the request class only repeats.
        $this->actingAs($this->manager);
        $this->expectException(AuthorizationException::class);
        app(RoleAssignmentService::class)->sync($this->manager, $this->manager, [$this->role('secretary')->id]);
    }

    public function test_an_ict_manager_cannot_grant_the_tiered_roles_whatever_the_flags_say(): void
    {
        // Even if someone flags every one of them ICT-assignable, nothing at or above the ICT tier can be given.
        Role::query()->whereIn('name', ['ict_manager', 'ict_team', 'admin', 'super_admin'])->update(['ict_assignable' => true]);

        foreach (['ict_manager', 'ict_team', 'admin', 'super_admin'] as $slug) {
            $this->patchRoles($this->manager, $this->regional, [$slug])->assertSessionHasErrors('roles.0');
            $this->assertFalse($this->regional->fresh()->hasRoles($slug), $slug);
        }

        // Protected roles can't be handed out either.
        $custom = Role::query()->create(['name' => 'locked_role', 'display_name' => 'Locked Role', 'is_system' => false, 'ict_assignable' => true, 'is_protected' => true]);
        $this->patchRoles($this->manager, $this->regional, ['locked_role'])->assertSessionHasErrors('roles.0');
        $this->assertFalse($this->regional->fresh()->hasRoles($custom->name));

        // Head Office-only roles: refused for a regional user whatever the flags say.
        Role::query()->whereIn('name', ['hr_headoffice', 'chief_manager'])->update(['ict_assignable' => true]);
        $this->patchRoles($this->manager, $this->regional, ['hr_headoffice'])->assertSessionHasErrors('roles.0');

        $policy = app(RoleGrantPolicy::class);
        $assignable = $policy->assignableRolesFor($this->manager, $this->regional)->pluck('name')->all();

        foreach (['ict_manager', 'ict_team', 'admin', 'super_admin', 'hr_headoffice', 'chief_manager', 'locked_role'] as $slug) {
            $this->assertNotContains($slug, $assignable, $slug);
        }

        $this->assertContains('secretary', $assignable);
        $this->assertContains('hr_region', $assignable);
    }

    public function test_an_ict_manager_grants_and_removes_ordinary_roles_in_their_region_and_it_is_audited(): void
    {
        $this->patchRoles($this->manager, $this->regional, ['secretary', 'driver'])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['secretary', 'driver'], $this->regional->fresh()->roles->pluck('name')->all());

        $grants = AuditLog::query()->where('action', 'role_assigned')->where('target_id', $this->regional->id)->get();
        $this->assertEqualsCanonicalizing(['secretary', 'driver'], $grants->pluck('metadata.role')->all());
        $this->assertSame([$this->manager->id], $grants->pluck('user_id')->unique()->values()->all());
        $this->assertSame([$this->manager->id], $grants->pluck('metadata.actor_id')->unique()->values()->all());

        $this->patchRoles($this->manager, $this->regional, ['secretary'])->assertSessionHasNoErrors();
        $this->assertSame(['secretary'], $this->regional->fresh()->roles->pluck('name')->all());
        $this->assertSame(1, AuditLog::query()->where('action', 'role_removed')->where('target_id', $this->regional->id)->count());
    }

    public function test_an_ict_manager_cannot_change_another_ict_managers_roles_or_status(): void
    {
        $this->patchRoles($this->manager, $this->otherManager, ['ict_manager', 'secretary'])->assertForbidden();
        $this->assertSame(['ict_manager'], $this->otherManager->fresh()->roles->pluck('name')->all());

        $this->actingAs($this->manager)->patch(route('uac.users.toggle-status', $this->otherManager))->assertForbidden();
        $this->assertTrue($this->otherManager->fresh()->is_active);

        // The ICT team ranks below an ICT Manager and cannot touch one either.
        $this->patchRoles($this->ictTeam, $this->otherManager, ['ict_manager', 'secretary'])->assertForbidden();

        $this->assertSame([], app(RoleGrantPolicy::class)->assignableRolesFor($this->manager, $this->otherManager)->all());
        $this->assertSame([], app(RoleGrantPolicy::class)->manageableRoleIdsFor($this->manager, $this->otherManager));
    }

    public function test_only_a_global_admin_or_super_admin_gives_or_changes_the_ict_manager_role(): void
    {
        $this->patchRoles($this->super, $this->regional, ['ict_manager'])->assertSessionHasNoErrors();
        $this->assertTrue($this->regional->fresh()->hasRoles('ict_manager'));

        $this->patchRoles($this->globalAdmin, $this->tema, ['ict_manager'])->assertSessionHasNoErrors();
        $this->assertTrue($this->tema->fresh()->hasRoles('ict_manager'));

        // ...and either of them changes an existing ICT Manager's roles.
        $this->patchRoles($this->globalAdmin, $this->otherManager, ['ict_manager', 'secretary'])->assertSessionHasNoErrors();
        $this->assertTrue($this->otherManager->fresh()->hasRoles('secretary'));
        $this->patchRoles($this->globalAdmin, $this->otherManager, ['secretary'])->assertSessionHasNoErrors();
        $this->assertFalse($this->otherManager->fresh()->hasRoles('ict_manager'));

        $this->assertSame(2, AuditLog::query()->where('action', 'role_assigned')->where('metadata->role', 'ict_manager')->count());
    }

    public function test_the_service_refuses_what_the_screens_would_hide(): void
    {
        $service = app(RoleAssignmentService::class);

        try {
            $service->sync($this->manager, $this->regional, [$this->role('ict_team')->id]);
            $this->fail('An ICT Manager granted ict_team.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('roles.0', $e->errors());
        }

        try {
            $service->sync($this->manager, $this->kumasi, [$this->role('secretary')->id]);
            $this->fail('An ICT Manager changed a user in another region.');
        } catch (AuthorizationException) {
            $this->assertFalse($this->kumasi->fresh()->hasRoles('secretary'));
        }
    }

    // ---------------------------------------------------------------- The roles screen

    public function test_the_roles_screen_lists_only_the_roles_an_ict_manager_may_hand_out_and_is_read_only(): void
    {
        $this->actingAs($this->manager);

        $component = Livewire::test(RoleAccessManager::class);
        $names = collect($component->get('roles'))->pluck('name')->all();

        $this->assertContains('hr_region', $names);
        foreach (['admin', 'super_admin', 'ict_manager', 'ict_team', 'hr_headoffice'] as $hidden) {
            $this->assertNotContains($hidden, $names, $hidden);
        }

        // Read only: saving is refused, and an excluded role cannot even be selected.
        $component->call('selectRole', $this->role('hr_region')->id)->assertSet('canEdit', false);
        Livewire::test(RoleAccessManager::class)->call('selectRole', $this->role('admin')->id)->assertStatus(404);
    }

    public function test_the_roles_screen_still_refuses_the_ict_team_and_changes_nothing_for_a_global_admin(): void
    {
        $this->actingAs($this->ictTeam)->get(route('uac.roles'))->assertForbidden();
        $this->actingAs($this->manager)->get(route('uac.roles'))->assertOk();

        $this->actingAs($this->globalAdmin);
        $names = collect(Livewire::test(RoleAccessManager::class)->get('roles'))->pluck('name')->all();
        $this->assertContains('admin', $names);
        $this->assertContains('ict_manager', $names);
    }

    // ---------------------------------------------------------------- Assets keeps working

    public function test_an_ict_manager_reaches_the_assets_module_like_the_ict_team(): void
    {
        $this->actingAs($this->manager)->get(route('assets.assets'))->assertOk();
        $this->actingAs($this->manager)->get(route('uac.index'))->assertOk();
    }
}
