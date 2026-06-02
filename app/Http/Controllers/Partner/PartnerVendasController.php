<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PartnerVendasService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartnerVendasController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        if (! $user->usesPartnerPanel()) {
            abort(403);
        }

        $service = app(PartnerVendasService::class);
        [$filteredQuery, $statusFilter] = $service->buildFilteredQuery($user, $request);

        $stats = $service->statsForQuery($filteredQuery, $user);

        $vendas = $filteredQuery
            ->with([
                'product:id,name',
                'user:id,name,email',
                'commissionEntries' => fn ($q) => $q->where('beneficiary_user_id', $user->id),
            ])
            ->orderByDesc('orders.created_at')
            ->paginate(20)
            ->withQueryString();

        $productIds = $vendas->getCollection()->pluck('product_id')->filter()->unique()->values()->all();
        $shareMap = $service->shareBuyerDataByProductIds($productIds);

        return Inertia::render('Partner/Vendas', [
            'vendas' => $vendas->through(function (Order $order) use ($user, $service, $shareMap) {
                $share = (bool) ($shareMap->get($order->product_id) ?? false);

                return $service->orderToPartnerArray($order, $user, $share);
            }),
            'stats' => $stats,
            'status_filter' => $statusFilter,
            'filters' => [
                'q' => $request->query('q', ''),
                'period' => $request->query('period', 'all'),
                'date_from' => $request->query('date_from', ''),
                'date_to' => $request->query('date_to', ''),
                'product_id' => $request->query('product_id', ''),
                'payment_status' => $request->query('payment_status', 'all'),
            ],
            'products' => $service->productsForFilter($user),
        ]);
    }
}
