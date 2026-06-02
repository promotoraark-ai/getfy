<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductAffiliate;
use App\Models\ProductCoproducer;
use App\Services\PartnerAccessService;
use App\Services\StorageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartnerProductsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        if (! $user->usesPartnerPanel()) {
            abort(403);
        }

        $access = app(PartnerAccessService::class);
        $storage = app(StorageService::class);

        if ($user->isAfiliado()) {
            $memberships = $access->affiliateMembershipsFor($user);
            $productIds = $memberships->pluck('product_id')->all();
            $productsById = Product::query()
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            $products = $memberships
                ->sortBy(fn (ProductAffiliate $a) => $productsById->get($a->product_id)?->name ?? '')
                ->map(function (ProductAffiliate $affiliate) use ($productsById, $storage, $access) {
                    $p = $productsById->get($affiliate->product_id);
                    if (! $p) {
                        return null;
                    }

                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'checkout_slug' => $p->checkout_slug,
                        'image_url' => $p->image ? $storage->url($p->image) : null,
                        'price' => (float) $p->price,
                        'currency' => $p->currency ?? 'BRL',
                        'partner_type' => 'afiliado',
                        'affiliate_status' => $affiliate->status,
                        'commission_percent' => $access->commissionPercentForAffiliate($affiliate, $p),
                    ];
                })
                ->filter()
                ->values();
        } else {
            $productIds = $access->allowedProductIdsFor($user);
            $coproducers = ProductCoproducer::query()
                ->where('user_id', $user->id)
                ->where('status', ProductCoproducer::STATUS_ACTIVE)
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy('product_id');

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('name')
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'checkout_slug' => $p->checkout_slug,
                    'image_url' => $p->image ? $storage->url($p->image) : null,
                    'price' => (float) $p->price,
                    'currency' => $p->currency ?? 'BRL',
                    'partner_type' => 'coprodutor',
                    'affiliate_status' => null,
                    'commission_percent' => (float) ($coproducers->get($p->id)?->commission_percent ?? 0),
                ]);
        }

        return Inertia::render('Partner/Products', [
            'products' => $products,
            'partner_role' => $user->role,
        ]);
    }
}
