<?php

namespace App\Listeners;

use App\Events\OrderRefunded;
use App\Services\RefundService;

class RevokeAccessOnOrderRefunded
{
    public function __construct(
        protected RefundService $refundService
    ) {}

    public function handle(OrderRefunded $event): void
    {
        $order = $event->order;
        $order->revokePurchasedProductAccessToBuyer();
        $this->refundService->markCompletedFromWebhook($order);
    }
}
