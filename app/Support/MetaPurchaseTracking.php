<?php

namespace App\Support;

use App\Models\Order;

class MetaPurchaseTracking
{
    public const EVENT_ID_PREFIX = 'getfy_purchase_';

    public static function purchaseEventId(int $orderId): string
    {
        return self::EVENT_ID_PREFIX . $orderId;
    }

    /**
     * @return list<array{id: string, quantity: int, item_price: float}>
     */
    public static function purchaseContentsFromOrder(Order $order, bool $excludeOrderBumps = false): array
    {
        $order->loadMissing('orderItems');

        $items = $order->orderItems;
        if ($excludeOrderBumps) {
            $items = $items->filter(fn ($it) => (int) ($it->position ?? 0) === 0)->values();
        }

        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'id' => (string) ($item->product_id ?? ''),
                'quantity' => 1,
                'item_price' => round((float) ($item->amount ?? 0), 2),
            ];
        }

        if ($out === []) {
            $out[] = [
                'id' => (string) ($order->product_id ?? ''),
                'quantity' => 1,
                'item_price' => round((float) $order->amount, 2),
            ];
        }

        return $out;
    }
}
