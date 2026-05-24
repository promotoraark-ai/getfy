<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\MetaConversionsApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMetaPurchaseCapiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $orderId
    ) {}

    public function handle(MetaConversionsApiService $metaConversionsApiService): void
    {
        $order = Order::find($this->orderId);
        if (! $order) {
            return;
        }

        $metaConversionsApiService->sendPurchaseForCompletedOrder($order);
    }
}
