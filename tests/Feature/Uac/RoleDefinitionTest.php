<?php

namespace Tests\Feature\Uac;

use App\Livewire\Uac\RoleAccessManager;
use App\Models\AuditLog;
use App\Models\ModuleAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Uac\RoleGrantPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Uac\Concerns\BuildsUacOrg;
use Tests\TestCase;

/**
 * Roles are shared definitions: only a Global Admin (or super_admin) creates them or edits their permissions, the
 * protected roles only a super_admin, and deleting is a super_admin job. Every change is audited with old and new.
 */
class RoleDefinitionTest extends TestCase
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

        $this->super = $this->person('SA001', $this->headOffice, ['super_admin']);
        $this->globalAdmin = $this->person('GA001', $this->headOffice, ['admin']);
        $this->ict = $this->person('ICT001', $this->headOffice, ['ict_team']);
    }

    protected function roles(User $viewer)
    {
        return Livewire::actingAs($viewer)->test(RoleAccessManager::class);
    }

    protected function permissionId(string $name): int
    {
        return Permission::query()->where('name', $name)->value('id');
    }

    // ---------------------------------------------------------------- Editing permissions

    public function test_a_global_admin_can_edit_a_roles_permissions_and_the_change_is_audited(): void
    {
        $role = $this->role('hr_region');
        $letters = $this->permissionId('letters.view');
        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        $this->assertNotContains('letters.view', $before);

        $this->roles($this->globalAdmin)
            ->call('selectRole', $role->id)
            ->assertViewHas('canEdit', true)
            ->call('togglePermission', $letters)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertContains('letters.view', $role->fresh()->permissions()->pluck('name')->all());

        $row = AuditLog::query()->where('action', 'update_role_access')->sole();
        $this->assertSame($this->globalAdmin->id, $row->user_id);
        $this->assertSame($before, $row->old_values['permissions']);
        $this->assertContains('letters.view', $row->new_values['permissions']);
        $this->assertSame('hr_region', $row->metadata['role']);
        $this->assertSame($this->globalAdmin->id, $row->metadata['actor_id']);
    }

    public function test_the_ict_team_cannot_open_the_roles_screen_or_edit_permissions(): void
    {
        $hr = $this->person('HR001', $this->headOffice, ['hr_headoffice']);

        $this->actingAs($this->ict)->get(route('uac.roles'))->assertForbidden();
        $this->roles($this->ict)->assertForbidden();
        $this->roles($hr)->assertForbidden();

        $policy = app(RoleGrantPolicy::class);
        $this->assertFalse($policy->canEditRolePermissions($this->ict, $this->role('hr_region')));
        $this->assertFalse($policy->canCreateRole($this->ict));
        $this->assertFalse($policy->canDeleteRole($this->ict, Role::query()->create(['name' => 'custom', 'display_name' => 'Custom', 'is_system' => false])));
        $this->assertTrue($policy->canEditRolePermissions($this->globalAdmin, $this->role('hr_region')));
        $this->assertTrue($policy->canCreateRole($this->globalAdmin));
    }

    public function test_the_ict_team_role_itself_can_be_edited_by_a_global_admin(): void
    {
        $this->assertTrue(app(RoleGrantPolicy::class)->canEditRolePermissions($this->globalAdmin, $this->role('ict_team')));
    }

    public function test_protected_roles_can_only_be_changed_by_a_super_admin_and_super_admin_is_locked_for_everyone(): void
    {
        $admin = $this->role('admin');
        $permission = $this->permissionId('letters.view');

        $screen = $this->roles($this->globalAdmin)
            ->call('selectRole', $admin->id)
            ->assertViewHas('canEdit', false)
            ->assertViewHas('lockReason', 'This is a protected role: only a Super Admin can change its permissions.');

        $before = $screen->get('selectedPermissionIds');
        $screen->call('togglePermission', $permission)->assertSet('selectedPermissionIds', $before);
        $screen->call('save')->assertForbidden();
        $this->assertNotContains('letters.view', $admin->fresh()->permissions()->pluck('name')->all());

        $this->roles($this->super)
            ->call('selectRole', $admin->id)
            ->assertViewHas('canEdit', true)
            ->call('togglePermission', $permission)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertContains('letters.view', $admin->fresh()->permissions()->pluck('name')->all());

        // The Super Admin role: visible to a super_admin, editable by nobody.
        $this->roles($this->super)
            ->call('selectRole', $this->role('super_admin')->id)
            ->assertViewHas('canEdit', false)
            ->assertViewHas('lockReason', 'This role is locked and cannot be modified.')
            ->call('save')
            ->assertForbidden();
    }

    public function test_a_global_admin_cannot_add_governance_permissions_they_do_not_hold(): void
    {
        $role = $this->role('hr_region');
        $auditLog = $this->permissionId('uac.view_audit_log');
        $this->assertFalse($this->globalAdmin->hasPermission('uac.view_audit_log'));

        $this->roles($this->globalAdmin)
            ->call('selectRole', $role->id)
            ->call('togglePermission', $auditLog)
            ->call('save')
            ->assertHasErrors('selectedPermissionIds');

        $this->assertNotContains('uac.view_audit_log', $role->fresh()->permissions()->pluck('name')->all());

        // A super_admin may.
        $this->roles($this->super)
            ->call('selectRole', $role->id)
            ->call('togglePermission', $auditLog)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertContains('uac.view_audit_log', $role->fresh()->permissions()->pluck('name')->all());
    }

    // ---------------------------------------------------------------- Creating, renaming, deleting

    public function test_a_global_admin_can_create_a_role_and_it_starts_global_admin_only(): void
    {
        $this->roles($this->globalAdmin)
            ->set('newRoleSlug', 'finance_officer')
            ->set('newRoleDisplayName', 'Finance Officer')
            ->call('createRole')
            ->assertHasNoErrors();

        $role = Role::query()->where('name', 'finance_officer')->firstOrFail();
        $this->assertFalse($role->is_system);
        $this->assertFalse($role->ict_assignable);
        $this->assertFalse($role->is_protected);
        $this->assertSame(count(Permission::MODULES), ModuleAccess::query()->where('role_id', $role->id)->count());

        $row = AuditLog::query()->where('action', 'create_role')->sole();
        $this->assertNull($row->old_values);
        $this->assertSame('finance_officer', $row->new_values['name']);
        $this->assertSame($this->globalAdmin->id, $row->user_id);

        // ICT can't hand it out until a Global Admin flags it.
        $target = $this->person('EMP001', $this->headOffice);
        $this->patchRoles($this->ict, $target, ['finance_officer'])->assertSessionHasErrors('roles.0');

        $this->roles($this->globalAdmin)->call('selectRole', $role->id)->call('toggleIctAssignable')->assertSet('ictAssignable', true)->call('save');
        $this->assertTrue($role->fresh()->ict_assignable);

        $this->patchRoles($this->ict, $target, ['finance_officer'])->assertSessionHasNoErrors();
        $this->assertTrue($target->fresh()->hasRoles('finance_officer'));
    }

    public function test_the_ict_assignable_flag_is_audited_and_never_settable_on_tiered_roles(): void
    {
        $hrRegion = $this->role('hr_region');
        $this->assertTrue($hrRegion->ict_assignable);

        $this->roles($this->globalAdmin)
            ->call('selectRole', $hrRegion->id)
            ->call('toggleIctAssignable')
            ->assertSet('ictAssignable', false)
            ->call('save');

        $this->assertFalse($hrRegion->fresh()->ict_assignable);

        $row = AuditLog::query()->where('action', 'update_role_access')->sole();
        $this->assertTrue($row->old_values['ict_assignable']);
        $this->assertFalse($row->new_values['ict_assignable']);

        foreach (['admin', 'ict_team'] as $tiered) {
            $this->roles($this->super)->call('selectRole', $this->role($tiered)->id)->call('toggleIctAssignable')->assertSet('ictAssignable', false);
        }
    }

    public function test_only_super_admin_deletes_a_custom_role_and_only_when_nobody_holds_it(): void
    {
        $custom = Role::query()->create(['name' => 'temp_role', 'display_name' => 'Temp Role', 'is_system' => false]);
        $held = Role::query()->create(['name' => 'held_role', 'display_name' => 'Held Role', 'is_system' => false]);
        $this->person('EMP001', $this->headOffice, ['held_role']);

        $this->roles($this->globalAdmin)->call('selectRole', $custom->id)->call('openDeleteRole')->assertForbidden();

        $screen = $this->roles($this->super);
        $screen->call('selectRole', $held->id)->call('openDeleteRole')->assertSet('showDeleteRole', false);
        $this->assertNotNull($held->fresh());

        // A system role can't be deleted even if a request is forged for it (a refused request ends that component).
        $this->roles($this->super)
            ->set('deleteRoleId', $this->role('hr_region')->id)
            ->set('deleteConfirmText', 'DELETE')
            ->call('deleteRole')
            ->assertForbidden();
        $this->assertNotNull($this->role('hr_region'));

        $screen->call('selectRole', $custom->id)
            ->call('openDeleteRole')
            ->assertSet('showDeleteRole', true)
            ->set('deleteConfirmText', 'DELETE')
            ->call('deleteRole');

        $this->assertNull($custom->fresh());

        $row = AuditLog::query()->where('action', 'delete_role')->sole();
        $this->assertSame('temp_role', $row->old_values['name']);
        $this->assertNull($row->new_values);
        $this->assertSame($this->super->id, $row->user_id);
    }

    public function test_renaming_a_custom_role_is_audited_and_system_roles_keep_their_names(): void
    {
        $custom = Role::query()->create(['name' => 'temp_role', 'display_name' => 'Temp Role', 'is_system' => false]);

        $this->roles($this->globalAdmin)
            ->call('selectRole', $custom->id)
            ->call('openEditRole')
            ->set('editRoleDisplayName', 'Temporary Role')
            ->call('updateRole')
            ->assertHasNoErrors();

        $this->assertSame('Temporary Role', $custom->fresh()->display_name);
        $row = AuditLog::query()->where('action', 'update_role')->sole();
        $this->assertSame('Temp Role', $row->old_values['display_name']);
        $this->assertSame('Temporary Role', $row->new_values['display_name']);

        $this->roles($this->globalAdmin)->call('selectRole', $this->role('hr_region')->id)->call('openEditRole')->assertSet('showEditRole', false);
        $this->roles($this->globalAdmin)->set('editRoleId', $this->role('hr_region')->id)->set('editRoleDisplayName', 'Renamed')->call('updateRole')->assertForbidden();
        $this->assertSame('Hr Region', $this->role('hr_region')->display_name);
    }
}
