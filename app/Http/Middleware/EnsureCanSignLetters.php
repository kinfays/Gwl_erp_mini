<?php

namespace App\Http\Middleware;

use App\Services\Leave\SignatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** My Signature is for the people who sign approval letters (and whoever is acting in a post right now). */
class EnsureCanSignLetters
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && app(SignatureService::class)->canSign($request->user()), 403);

        return $next($request);
    }
}
