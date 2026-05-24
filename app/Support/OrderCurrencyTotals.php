<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OrderCurrencyTotals
{
    /**
     * Soma valores de pedidos completed agrupados por moeda (sem misturar moedas).
     *
     * @param  Builder<Order>  $statsQuery  Query já filtrada (tenant, período, etc.)
     * @return list<array{currency: string, total: float}>
     */
    public static function valorPorMoedaFromQuery(Builder $statsQuery): array
    {
        $completedIds = (clone $statsQuery)
            ->where('status', 'completed')
            ->select('orders.id');

        $currencyExpr = "COALESCE(NULLIF(orders.currency, ''), 'BRL')";

        $fromItems = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.order_id', $completedIds)
            ->selectRaw("{$currencyExpr} as currency, SUM(order_items.amount) as total")
            ->groupBy(DB::raw($currencyExpr))
            ->pluck('total', 'currency');

        $fromOrdersWithoutItems = (clone $statsQuery)
            ->where('status', 'completed')
            ->whereDoesntHave('orderItems')
            ->selectRaw("{$currencyExpr} as currency, SUM(orders.amount) as total")
            ->groupBy(DB::raw($currencyExpr))
            ->pluck('total', 'currency');

        $merged = [];
        foreach ($fromItems as $currency => $total) {
            $code = MoneyMinorUnits::normalizeCurrencyCode((string) $currency);
            $merged[$code] = ($merged[$code] ?? 0.0) + (float) $total;
        }
        foreach ($fromOrdersWithoutItems as $currency => $total) {
            $code = MoneyMinorUnits::normalizeCurrencyCode((string) $currency);
            $merged[$code] = ($merged[$code] ?? 0.0) + (float) $total;
        }

        ksort($merged);

        $out = [];
        foreach ($merged as $currency => $total) {
            $out[] = [
                'currency' => $currency,
                'total' => round((float) $total, 2),
            ];
        }

        return $out;
    }
}
