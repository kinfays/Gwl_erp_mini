<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        $employee = $user?->employee ?? $user?->employeeByStaffId;

        if ($user && (! $user->is_active || ($employee && ! $employee->is_active))) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'staff_id' => "You don't have access, contact Administrator.",
            ]);
        }

        if ($user && $user->must_change_password && ! $this->isPasswordChangeRoute($request)) {
            return redirect()
                ->route('profile.edit')
                ->with('status', 'Please change your default password before continuing.');
        }

        return $next($request);
    }

    protected function isPasswordChangeRoute(Request $request): bool
    {
        return $request->routeIs(
            'profile.edit',
            'profile.update',
            'password.update',
            'logout',
            'verification.send'
        );
    }
}
