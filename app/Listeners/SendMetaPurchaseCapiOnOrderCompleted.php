<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\SendMetaPurchaseCapiJob;
use App\Support\IntegrationsDispatch;
use Illuminate\Support\Facades\Log;

/**
 * Síncrono: apenas enfileira SendMetaPurchaseCapiJob.
 * Não implementar ShouldQueue — handle() deve receber só OrderCompleted ($event).
 * Versão antiga com handle($event, MetaConversionsApiService) + ShouldQueue quebrava
 * aprovação manual e impedia webhooks/Utmify de rodarem no mesmo OrderCompleted.
 */
class SendMetaPurchaseCapiOnOrderCompleted
{
    public function handle(OrderCompleted $event): void
    {
        try {
            $orderId = $event->order->id;

            if (IntegrationsDispatch::shouldDispatchAfterResponse()) {
                SendMetaPurchaseCapiJob::dispatchAfterResponse($orderId);

                return;
            }

            SendMetaPurchaseCapiJob::dispatch($orderId);
        } catch (\Throwable $e) {
            Log::error('SendMetaPurchaseCapiOnOrderCompleted: falha ao enfileirar CAPI', [
                'order_id' => $event->order->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
