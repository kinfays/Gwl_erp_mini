<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\PasswordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', PasswordRules::account(), 'confirmed'],
        ]);

        $payload = [
            'password' => Hash::make($validated['password']),
        ];

        if (Schema::hasColumn('users', 'must_change_password')) {
            $payload['must_change_password'] = false;
        }

        $request->user()->update($payload);

        return back()->with('status', 'password-updated');
    }
}
