<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:admin') or ->middleware('role:admin,customer').
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        $allowed = array_map(fn (string $role) => UserRole::from($role), $roles);

        if (! $user->hasRole(...$allowed)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
