<?php

namespace App\Http\Middleware;

use App\Services\PartnerAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePartnerPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! app(PartnerAccessService::class)->usesPartnerPanel($user)) {
            abort(403, 'Acesso não autorizado.');
        }

        return $next($request);
    }
}
