<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Product;
use App\Services\TeamAccessService;

trait AuthorizesTenantProduct
{
    protected function authorizeTenantProduct(Product $produto): void
    {
        $user = auth()->user();
        $tenantId = $user?->tenant_id;
        if ($produto->tenant_id !== $tenantId) {
            abort(403);
        }

        if ($user?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor($user);
            if (! in_array($produto->id, $allowed, true)) {
                abort(403);
            }
        }
    }
}
