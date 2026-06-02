<?php

namespace App\Http\Middleware;

use App\Models\Product;
use App\Services\PartnerAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePartnerProductAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! app(PartnerAccessService::class)->usesPartnerPanel($user)) {
            abort(403, 'Acesso não autorizado.');
        }

        $product = $request->route('produto');
        if (! $product instanceof Product) {
            abort(404);
        }

        if (! app(PartnerAccessService::class)->canAccessProduct($user, $product)) {
            abort(403, 'Você não tem acesso a este produto.');
        }

        return $next($request);
    }
}
