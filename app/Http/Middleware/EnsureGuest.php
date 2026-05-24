<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }
        $user = $request->user();
        if ($user->canAccessPanel()) {
            return redirect('/dashboard');
        }
        return redirect('/area-membros');
    }
}
