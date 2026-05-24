<?php

namespace App\Jobs;

use App\Models\ApiApplication;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendApiApplicationWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $orderId,
        public string $eventName
    ) {}

    public function handle(): void
    {
        $order = Order::with('apiApplication')->find($this->orderId);
        if (! $order || ! $order->api_application_id) {
            return;
        }

        $app = $order->apiApplication;
        if (! $app || ! $app->webhook_url || ! $app->is_active) {
            return;
        }

        $payload = [
            'event' => $this->eventName,
            'order_id' => $order->id,
            'amount' => (float) $order->amount,
            'status' => $order->status,
            'email' => $order->email,
            'metadata' => $order->metadata ?? [],
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];

        $body = json_encode($payload);
        $headers = ['Content-Type' => 'application/json'];

        if ($app->webhook_secret !== null && $app->webhook_secret !== '') {
            $signature = hash_hmac('sha256', $body, $app->webhook_secret);
            $headers['X-Getfy-Signature'] = $signature;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($app->webhook_url);

            if (! $response->successful()) {
                Log::warning('ApiApplication webhook non-2xx', [
                    'api_application_id' => $app->id,
                    'order_id' => $order->id,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('ApiApplication webhook failed', [
                'api_application_id' => $app->id,
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
