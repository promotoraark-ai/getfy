<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

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
        $hasCurrencyColumn = Schema::hasTable('orders') && Schema::hasColumn('orders', 'currency');

        $columns = ['orders.id', 'orders.amount', 'orders.status'];
        if ($hasCurrencyColumn) {
            $columns[] = 'orders.currency';
        }

        $orders = (clone $statsQuery)
            ->where('orders.status', 'completed')
            ->with(['orderItems:id,order_id,amount'])
            ->get($columns);

        if ($orders->isEmpty()) {
            return [];
        }

        $merged = [];
        foreach ($orders as $order) {
            $code = $hasCurrencyColumn
                ? MoneyMinorUnits::normalizeCurrencyCode($order->getCurrencyOrDefault())
                : 'BRL';
            $amount = $order->lineItemsTotalAmount();
            $merged[$code] = ($merged[$code] ?? 0.0) + $amount;
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
