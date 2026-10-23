<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SystemOwner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EnsureSystemOwner
{
    public function __construct(private readonly SystemOwner $systemOwner) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $isSystemOwner = $this->systemOwner->is($user);
        } catch (Throwable) {
            abort(403);
        }

        if (! $isSystemOwner) {
            abort(403);
        }

        return $next($request);
    }
}
