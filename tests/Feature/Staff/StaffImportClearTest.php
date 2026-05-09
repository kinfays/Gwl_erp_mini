<?php

namespace Tests\Feature\Staff;

use App\Models\ModuleAccess;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffImportClearTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_import_upload_error_can_be_cleared(): void
    {
        $this->actingAs($this->createStaffImportUser())
            ->withSession([
                'import_preview.staff' => [
                    'type' => 'employees',
                    'preview_rows' => [],
                    'valid_rows' => [],
                    'errors' => [
                        ['row' => 2, 'message' => 'The job title must already exist in the system.'],
                    ],
                    'total_rows' => 1,
                    'valid_count' => 0,
                    'error_count' => 1,
                ],
            ]);

        $this->get(route('staff.import'))
            ->assertOk()
            ->assertSee('Clear Upload Error');

        $this->post(route('staff.import.clear'))
            ->assertRedirect()
            ->assertSessionMissing('import_preview.staff')
            ->assertSessionHas('success', 'Upload error cleared.');
    }

    protected function createStaffImportUser(): User
    {
        $role = Role::query()->create([
            'name' => 'hr_headoffice',
            'display_name' => 'HR Head Office',
            'is_system' => true,
        ]);

        ModuleAccess::query()->create([
            'role_id' => $role->id,
            'module' => 'staff',
            'can_access' => true,
        ]);

        $user = User::query()->create([
            'full_name' => 'HR Importer',
            'email' => 'hr.importer@example.com',
            'staff_id' => 'HR001',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->roles()->attach($role);

        return $user;
    }
}
