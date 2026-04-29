<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = User::query()->where('staff_id', $this->input('staff_id'))->first();

        if (! $user || ! Hash::check((string) $this->input('password'), $user->password)) {
            $attempts = RateLimiter::hit($this->throttleKey(), 120);

            if ($attempts >= 5) {
                throw ValidationException::withMessages([
                    'staff_id' => __('try again in 2 minutes time'),
                ]);
            }

            throw ValidationException::withMessages([
                'staff_id' => __('Invalid Staff ID or Password'),
            ]);
        }

        if (! $user->is_active) {
            RateLimiter::clear($this->throttleKey());

            throw ValidationException::withMessages([
                'staff_id' => __("You don't have access. Please contact your Administrator."),
            ]);
        }

        Auth::login($user, $this->boolean('remember'));
        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'staff_id' => __('try again in 2 minutes time'),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::lower($this->input('staff_id')).'|'.$this->ip();
    }
}
