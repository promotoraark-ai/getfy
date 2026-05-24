<?php

namespace App\Listeners;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\OrderRefunded;
use App\Events\OrderRejected;
use App\Events\PixGenerated;
use App\Jobs\UtmifySendOrderJob;
use App\Models\UtmifyIntegration;
use App\Support\IntegrationsDispatch;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;

class UtmifyEventSubscriber
{
    /**
     * OrderPending não é assinado: no checkout/API o fluxo PIX/boleto já emite OrderPending e em seguida
     * PixGenerated/BoletoGenerated — ouvir os dois gerava waiting_payment duplicado na Utmify.
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PixGenerated::class => 'handlePixGenerated',
            BoletoGenerated::class => 'handleBoletoGenerated',
            OrderCompleted::class => 'handleOrderCompleted',
            OrderRefunded::class => 'handleOrderRefunded',
            OrderRejected::class => 'handleOrderRejected',
        ];
    }

    public function handlePixGenerated(PixGenerated $event): void
    {
        $this->dispatchForOrder($event->order, 'waiting_payment');
    }

    public function handleBoletoGenerated(BoletoGenerated $event): void
    {
        $this->dispatchForOrder($event->order, 'waiting_payment');
    }

    public function handleOrderCompleted(OrderCompleted $event): void
    {
        $order = $event->order;
        $order->syncUtmMetadataFromCheckoutSession();
        $approvedAt = $order->updated_at->utc()->format('Y-m-d H:i:s');
        $this->dispatchForOrder($order, 'paid', $approvedAt, null);
    }

    public function handleOrderRefunded(OrderRefunded $event): void
    {
        $refundedAt = $event->order->updated_at->utc()->format('Y-m-d H:i:s');
        $this->dispatchForOrder($event->order, 'refunded', null, $refundedAt);
    }

    public function handleOrderRejected(OrderRejected $event): void
    {
        $this->dispatchForOrder($event->order, 'refused');
    }

    private function dispatchForOrder(
        \App\Models\Order $order,
        string $utmifyStatus,
        ?string $approvedAt = null,
        ?string $refundedAt = null
    ): void {
        if ($utmifyStatus === 'paid') {
            $lockKey = 'utmify_paid_sent:' . $order->id;
            if (! Cache::add($lockKey, 1, now()->addDays(30))) {
                return;
            }
        }

        $tenantId = $order->tenant_id;
        $order->loadMissing('orderItems');

        $integrations = UtmifyIntegration::forTenant($tenantId)
            ->where('is_active', true)
            ->with('products:id', 'apiApplications:id')
            ->get();

        foreach ($integrations as $integration) {
            if (! $integration->api_key) {
                continue;
            }
            if (! $integration->appliesToOrder($order)) {
                continue;
            }

            if (IntegrationsDispatch::shouldDispatchAfterResponse()) {
                UtmifySendOrderJob::dispatchAfterResponse(
                    $integration->id,
                    $order->id,
                    $utmifyStatus,
                    $approvedAt,
                    $refundedAt
                );
            } else {
                UtmifySendOrderJob::dispatch(
                    $integration->id,
                    $order->id,
                    $utmifyStatus,
                    $approvedAt,
                    $refundedAt
                );
            }
        }
    }
}
