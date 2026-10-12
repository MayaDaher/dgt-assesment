<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a Sanctum bearer token when one is sent, but still allows guests.
 * A missing token is a guest; a present but invalid token is 401.
 *
 * Implements AuthenticatesRequests so this runs before `throttle:*` and rate
 * limiters can key off the authenticated user rather than the shared IP.
 */
class OptionalSanctum implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null) {
            return $next($request);
        }

        if ($request->bearerToken() === null) {
            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        Auth::shouldUse('sanctum');
        Auth::guard('sanctum')->setUser($user);
        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
