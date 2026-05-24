<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\OrderRefunded;
use App\Jobs\SendApiApplicationWebhookJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendApiApplicationWebhookListener implements ShouldQueue
{
    public function handleOrderCompleted(OrderCompleted $event): void
    {
        $order = $event->order;
        if ($order->api_application_id === null) {
            return;
        }
        SendApiApplicationWebhookJob::dispatch($order->id, 'order.completed');
    }

    public function handleOrderPending(OrderPending $event): void
    {
        $order = $event->order;
        if ($order->api_application_id === null) {
            return;
        }
        SendApiApplicationWebhookJob::dispatch($order->id, 'order.pending');
    }

    public function handleOrderRefunded(OrderRefunded $event): void
    {
        $order = $event->order;
        if ($order->api_application_id === null) {
            return;
        }
        SendApiApplicationWebhookJob::dispatch($order->id, 'order.refunded');
    }

    public function subscribe($events): array
    {
        return [
            OrderCompleted::class => 'handleOrderCompleted',
            OrderPending::class => 'handleOrderPending',
            OrderRefunded::class => 'handleOrderRefunded',
        ];
    }
}
