<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductAffiliate;
use App\Models\ProductAffiliateProgram;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AffiliatesHubController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantId = $request->user()->tenant_id;

        return Inertia::render('Afiliados/Index', $this->affiliatesHubPayload($request, $tenantId));
    }

    public function coproducersIndex(Request $request): Response
    {
        $tenantId = $request->user()->tenant_id;

        $items = \App\Models\ProductCoproducer::query()
            ->whereHas('product', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with(['product:id,name', 'user:id,name,email'])
            ->orderByDesc('created_at')
            ->paginate(30);

        return Inertia::render('Produtos/Coproducers', [
            'coproducers' => $items->through(fn (\App\Models\ProductCoproducer $c) => [
                'id' => $c->id,
                'email' => $c->email,
                'status' => $c->status,
                'commission_percent' => (float) $c->commission_percent,
                'created_at' => $c->created_at?->toIso8601String(),
                'product_name' => $c->product?->name,
                'product_id' => $c->product_id,
                'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name, 'email' => $c->user->email] : null,
                'product' => $c->product ? ['id' => $c->product->id, 'name' => $c->product->name] : null,
            ]),
        ]);
    }

    public function affiliatesProductsIndex(Request $request): Response
    {
        $tenantId = $request->user()->tenant_id;

        $programs = ProductAffiliateProgram::query()
            ->whereHas('product', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with('product:id,name,checkout_slug,is_active')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (ProductAffiliateProgram $p) => [
                'product_id' => $p->product_id,
                'product_name' => $p->product?->name,
                'enabled' => $p->enabled,
                'default_commission_percent' => (float) $p->default_commission_percent,
                'public_slug' => $p->public_slug,
                'public_page_url' => $p->public_slug ? url('/afiliar/'.$p->public_slug) : null,
                'affiliates_count' => ProductAffiliate::where('product_id', $p->product_id)
                    ->where('status', ProductAffiliate::STATUS_APPROVED)->count(),
                'pending_count' => ProductAffiliate::where('product_id', $p->product_id)
                    ->where('status', ProductAffiliate::STATUS_PENDING)->count(),
            ]);

        return Inertia::render('Produtos/Affiliates', array_merge(
            $this->affiliatesHubPayload($request, $tenantId),
            ['programs' => $programs],
        ));
    }

    /**
     * @return array{affiliates: mixed, products: \Illuminate\Support\Collection, filters: array<string, mixed>}
     */
    private function affiliatesHubPayload(Request $request, int $tenantId): array
    {
        $status = $request->query('status');
        $productId = $request->query('product_id');

        $query = ProductAffiliate::query()
            ->whereHas('product', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with(['user:id,name,email', 'product:id,name,checkout_slug']);

        if ($status) {
            $query->where('status', $status);
        }
        if ($productId) {
            $query->where('product_id', $productId);
        }

        $affiliates = $query->orderByDesc('created_at')->paginate(30)->withQueryString();

        $products = Product::forTenant($tenantId)->orderBy('name')->get(['id', 'name']);

        return [
            'affiliates' => $affiliates->through(fn (ProductAffiliate $a) => $this->affiliateToHubArray($a)),
            'products' => $products,
            'filters' => ['status' => $status, 'product_id' => $productId],
        ];
    }

    private function affiliateToHubArray(ProductAffiliate $a): array
    {
        return [
            'id' => $a->id,
            'product_id' => $a->product_id,
            'product_name' => $a->product?->name,
            'affiliate_code' => $a->affiliate_code,
            'status' => $a->status,
            'commission_percent' => $a->commission_percent !== null ? (float) $a->commission_percent : null,
            'affiliate_link' => $a->product ? url('/c/'.$a->product->checkout_slug.'?ref='.$a->affiliate_code) : null,
            'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name, 'email' => $a->user->email] : null,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
