<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CancelStalePendingOrdersCommand extends Command
{
    protected $signature = 'orders:cancel-stale-pending
                            {--hours= : Horas sem atividade antes de cancelar (default: config checkout_security.stale_pending_hours)}
                            {--limit=500 : Máximo de pedidos por execução}';

    protected $description = 'Cancela pedidos pendentes antigos sem confirmação de pagamento (órfãos / tentativas abortadas).';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?: config('checkout_security.stale_pending_hours', 24));
        $hours = max(1, $hours);
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subHours($hours);

        $declinedMethods = ['card'];

        $query = Order::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->where(function ($q) use ($declinedMethods) {
                $q->whereNull('gateway_id')
                    ->orWhere('gateway_id', '')
                    ->orWhere(function ($q2) use ($declinedMethods) {
                        foreach ($declinedMethods as $method) {
                            $q2->orWhere('metadata->checkout_payment_method', $method);
                        }
                    });
            })
            ->orderBy('id')
            ->limit($limit);

        $cancelled = 0;
        foreach ($query->cursor() as $order) {
            $meta = $order->metadata ?? [];
            $meta['cancelled_reason'] = 'stale_pending_cleanup';
            $meta['cancelled_at'] = now()->toIso8601String();
            $order->update([
                'status' => 'cancelled',
                'metadata' => $meta,
            ]);
            $cancelled++;
        }

        if ($cancelled > 0) {
            Log::info('orders:cancel-stale-pending', ['count' => $cancelled, 'hours' => $hours]);
        }

        $this->info("Cancelados {$cancelled} pedido(s) pendente(s) com mais de {$hours}h.");

        return self::SUCCESS;
    }
}
