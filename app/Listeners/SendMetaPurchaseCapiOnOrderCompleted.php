<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\SendMetaPurchaseCapiJob;
use App\Support\IntegrationsDispatch;

class SendMetaPurchaseCapiOnOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        $orderId = $event->order->id;

        if (IntegrationsDispatch::shouldDispatchAfterResponse()) {
            SendMetaPurchaseCapiJob::dispatchAfterResponse($orderId);

            return;
        }

        SendMetaPurchaseCapiJob::dispatch($orderId);
    }
}
