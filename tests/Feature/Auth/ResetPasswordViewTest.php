<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetPasswordViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_password_page_uses_set_password_copy_and_readonly_staff_id(): void
    {
        $user = $this->createPortalUser();

        $response = $this->get(route('password.reset', [
            'token' => 'invite-token',
            'email' => $user->email,
            'staff_id' => $user->staff_id,
            'set_password' => true,
        ]));

        $response
            ->assertOk()
            ->assertSee('Set Password')
            ->assertSee('Staff ID')
            ->assertSee('value="'.$user->staff_id.'"', false)
            ->assertSee('readonly', false)
            ->assertSee('type="hidden" name="email"', false)
            ->assertDontSee('type="email"', false)
            ->assertDontSee('Email')
            ->assertDontSee('Reset Password');
    }

    public function test_standard_password_reset_page_uses_reset_copy_and_readonly_staff_id(): void
    {
        $user = $this->createPortalUser();

        $response = $this->get(route('password.reset', [
            'token' => 'reset-token',
            'email' => $user->email,
        ]));

        $response
            ->assertOk()
            ->assertSee('Reset Password')
            ->assertSee('Staff ID')
            ->assertSee('value="'.$user->staff_id.'"', false)
            ->assertSee('readonly', false)
            ->assertSee('type="hidden" name="email"', false)
            ->assertDontSee('type="email"', false)
            ->assertDontSee('Set Password')
            ->assertDontSee('Email');
    }

    protected function createPortalUser(): User
    {
        return User::query()->create([
            'staff_id' => 'STF001',
            'email' => 'staff@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }
}
