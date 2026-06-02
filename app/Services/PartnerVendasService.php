<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAffiliate;
use App\Models\ProductAffiliateProgram;
use App\Models\User;
use App\Support\AffiliateAttribution;
use App\Support\PartnerBuyerPresentation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PartnerVendasService
{
    private const STATUS_FILTERS = ['aprovadas', 'med', 'todas'];

    public function __construct(
        private readonly PartnerCommissionEstimate $commissionEstimate,
    ) {}

    /**
     * @return array{0: Builder<Order>, 1: string}
     */
    public function buildFilteredQuery(User $user, Request $request): array
    {
        $statusFilter = $request->query('status_filter', 'todas');
        if (! in_array($statusFilter, self::STATUS_FILTERS, true)) {
            $statusFilter = 'todas';
        }

        $orderIdsFromCommission = CommissionEntry::query()
            ->where('commission_entries.beneficiary_user_id', $user->id)
            ->whereIn('commission_entries.role', [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR])
            ->where('commission_entries.status', '!=', CommissionEntry::STATUS_CANCELLED)
            ->pluck('commission_entries.order_id');

        $approvedAffiliates = app(PartnerAccessService::class)
            ->affiliateMembershipsFor($user)
            ->where('status', ProductAffiliate::STATUS_APPROVED);

        $query = Order::query()->where(function (Builder $outer) use ($orderIdsFromCommission, $approvedAffiliates) {
            if ($orderIdsFromCommission->isNotEmpty()) {
                $outer->whereIn('orders.id', $orderIdsFromCommission);
            }

            if ($approvedAffiliates->isNotEmpty()) {
                $outer->orWhere(function (Builder $attributed) use ($approvedAffiliates) {
                    foreach ($approvedAffiliates as $affiliate) {
                        $code = AffiliateAttribution::normalizeRef($affiliate->affiliate_code);
                        if ($code === null) {
                            continue;
                        }
                        $attributed->orWhere(function (Builder $sub) use ($affiliate, $code) {
                            $sub->where('orders.product_id', $affiliate->product_id)
                                ->where('orders.metadata->affiliate_code', $code)
                                ->where('orders.metadata->sale_channel', 'affiliate');
                        });
                    }
                });
            }

            if ($orderIdsFromCommission->isEmpty() && $approvedAffiliates->isEmpty()) {
                $outer->whereRaw('1 = 0');
            }
        });

        $query = match ($statusFilter) {
            'aprovadas' => $query->where('orders.status', 'completed'),
            'med' => $query->where('orders.status', 'disputed'),
            default => $query,
        };

        $query = $this->applyPeriodFilter($query, $request);
        $query = $this->applySearchFilter($query, $request);
        $query = $this->applyProductFilter($query, $request, $user);
        $query = $this->applyPaymentFilters($query, $request);

        return [$query, $statusFilter];
    }

    /**
     * @return array<string, mixed>
     */
    public function statsForQuery(Builder $query, User $user): array
    {
        $clone = clone $query;

        $count = (clone $clone)->count();
        $orderIdsInScope = (clone $clone)->select('orders.id');

        $commissionTotal = (float) CommissionEntry::query()
            ->where('beneficiary_user_id', $user->id)
            ->whereIn('role', [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR])
            ->where('status', '!=', CommissionEntry::STATUS_CANCELLED)
            ->whereIn('order_id', $orderIdsInScope)
            ->sum('commission_amount');

        $orderIdsWithEntry = CommissionEntry::query()
            ->where('beneficiary_user_id', $user->id)
            ->whereIn('role', [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR])
            ->where('status', '!=', CommissionEntry::STATUS_CANCELLED)
            ->whereIn('order_id', $orderIdsInScope)
            ->pluck('order_id');

        if ($orderIdsWithEntry->count() < $count) {
            $estimateQuery = (clone $clone)->with('product');
            if ($orderIdsWithEntry->isNotEmpty()) {
                $estimateQuery->whereNotIn('orders.id', $orderIdsWithEntry);
            }
            $estimateQuery->get()->each(function (Order $order) use ($user, &$commissionTotal) {
                $commissionTotal += $this->commissionEstimate->forOrder($order, $user)['amount'];
            });
        }

        return [
            'vendas_encontradas' => $count,
            'comissao_total' => round($commissionTotal, 2),
        ];
    }

    /**
     * @return Collection<string, bool>
     */
    public function shareBuyerDataByProductIds(array $productIds): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return ProductAffiliateProgram::query()
            ->whereIn('product_id', $productIds)
            ->pluck('share_buyer_data', 'product_id')
            ->map(fn ($v) => (bool) $v);
    }

    /**
     * @return array<string, mixed>
     */
    public function orderToPartnerArray(Order $order, User $user, ?bool $shareBuyerData = null): array
    {
        $entry = $order->commissionEntries
            ->where('beneficiary_user_id', $user->id)
            ->whereIn('role', [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR])
            ->first();
        $buyer = PartnerBuyerPresentation::forOrder($order, $shareBuyerData);

        $commissionStatus = $entry?->status;
        $commissionAmount = $entry ? (float) $entry->commission_amount : 0.0;
        $commissionPercent = $entry ? (float) $entry->commission_percent : null;
        $commissionIsEstimated = false;

        if ($entry === null) {
            $estimate = $this->commissionEstimate->forOrder($order, $user);
            $commissionAmount = $estimate['amount'];
            $commissionPercent = $estimate['percent'];
            $commissionIsEstimated = $commissionAmount > 0;
            if ($commissionIsEstimated) {
                $commissionStatus = $order->status === 'completed' ? 'allocating' : 'awaiting_payment';
            }
        }

        return [
            'id' => $order->id,
            'created_at' => $order->created_at?->toIso8601String(),
            'status' => $order->status,
            'amount' => (float) $order->amount,
            'amount_total' => $order->lineItemsTotalAmount(),
            'currency' => $order->getCurrencyOrDefault(),
            'gateway_label' => $order->paymentMethodDisplayLabel(),
            'product_display_name' => $order->product?->name,
            'product' => $order->product ? ['id' => $order->product->id, 'name' => $order->product->name] : null,
            'commission_amount' => $commissionAmount,
            'commission_is_estimated' => $commissionIsEstimated,
            'commission_status' => $commissionStatus,
            'commission_percent' => $commissionPercent,
            'buyer_name' => $buyer['name'],
            'buyer_email' => $buyer['email'],
            'buyer_phone' => $buyer['phone'],
            'buyer_cpf' => $buyer['cpf'],
            'buyer_masked' => $buyer['masked'],
        ];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function productsForFilter(User $user): array
    {
        $ids = app(PartnerAccessService::class)->allowedProductIdsFor($user);

        return Product::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])
            ->values()
            ->all();
    }

    private function applyPeriodFilter(Builder $query, Request $request): Builder
    {
        $period = trim((string) $request->query('period', 'all'));
        if ($period === '' || $period === 'all') {
            return $query;
        }

        $tz = config('app.timezone', 'America/Sao_Paulo');
        $now = now($tz);
        $start = null;
        $end = null;

        if ($period === 'today') {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === '7d') {
            $start = $now->copy()->subDays(6)->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === '30d') {
            $start = $now->copy()->subDays(29)->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === 'this_month') {
            $start = $now->copy()->startOfMonth()->startOfDay();
            $end = $now->copy()->endOfDay();
        } elseif ($period === 'last_month') {
            $start = $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $end = $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay();
        } elseif ($period === 'custom') {
            $from = trim((string) $request->query('date_from', ''));
            $to = trim((string) $request->query('date_to', ''));
            $start = $from !== '' ? \Illuminate\Support\Carbon::parse($from, $tz)->startOfDay() : null;
            $end = $to !== '' ? \Illuminate\Support\Carbon::parse($to, $tz)->endOfDay() : null;
        }

        if ($start && $end) {
            $query->whereBetween('orders.created_at', [$start, $end]);
        } elseif ($start) {
            $query->where('orders.created_at', '>=', $start);
        } elseif ($end) {
            $query->where('orders.created_at', '<=', $end);
        }

        return $query;
    }

    private function applySearchFilter(Builder $query, Request $request): Builder
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '' || mb_strlen($q) < 3) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        return $query->where(function ($sub) use ($like) {
            $sub->where('orders.id', 'like', $like)
                ->orWhere('orders.email', 'like', $like)
                ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', $like));
        });
    }

    private function applyProductFilter(Builder $query, Request $request, User $user): Builder
    {
        $productId = trim((string) $request->query('product_id', ''));
        if ($productId === '') {
            return $query;
        }

        $allowed = app(PartnerAccessService::class)->allowedProductIdsFor($user);
        if (! in_array($productId, $allowed, true)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('orders.product_id', $productId);
    }

    private function applyPaymentFilters(Builder $query, Request $request): Builder
    {
        $paymentStatus = trim((string) $request->query('payment_status', 'all'));
        if ($paymentStatus !== '' && $paymentStatus !== 'all') {
            $query->where('orders.status', $paymentStatus);
        }

        return $query;
    }
}
