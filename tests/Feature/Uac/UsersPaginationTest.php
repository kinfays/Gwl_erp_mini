<?php

namespace Tests\Feature\Uac;

use App\Models\ModuleAccess;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsersPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_page_has_pagination_and_per_page_control(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $this->createUsers(25);

        $this->get(route('uac.users'))
            ->assertOk()
            ->assertSee('15 per page')
            // 25 seeded users plus the super_admin viewing the list (super_admin accounts are visible to super_admin).
            ->assertSee('Showing 1 - 15 of 26 users');

        $this->get(route('uac.users', ['per_page' => 10]))
            ->assertOk()
            ->assertSee('10 per page')
            ->assertSee('Showing 1 - 10 of 26 users');
    }

    protected function createSuperAdmin(): User
    {
        $role = Role::query()->create([
            'name' => 'super_admin',
            'display_name' => 'Super Admin',
            'is_system' => true,
        ]);

        ModuleAccess::query()->create([
            'role_id' => $role->id,
            'module' => 'uac',
            'can_access' => true,
        ]);

        $user = User::query()->create([
            'full_name' => 'Super Admin',
            'email' => 'super.admin@example.com',
            'staff_id' => 'SA001',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->roles()->attach($role);

        return $user;
    }

    protected function createUsers(int $count): void
    {
        foreach (range(1, $count) as $index) {
            User::query()->create([
                'full_name' => sprintf('User %03d', $index),
                'email' => sprintf('user%03d@example.com', $index),
                'staff_id' => sprintf('USR%03d', $index),
                'password' => Hash::make('password'),
                'is_active' => true,
            ]);
        }
    }
}
