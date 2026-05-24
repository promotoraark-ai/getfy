<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\User;
use App\Services\MetaConversionsApiService;
use App\Support\MetaPurchaseTracking;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaConversionsApiServiceTest extends TestCase
{
    public function test_send_purchase_uses_order_currency_value_and_attribution(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
        ]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        [
                            'id' => 'meta-1',
                            'pixel_id' => '123456789',
                            'access_token' => 'test_access_token',
                        ],
                    ],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99.9,
            'currency' => 'USD',
            'email' => 'buyer@example.com',
            'metadata' => [
                'fbc' => 'fb.1.test',
                'fbp' => 'fb.1.p.test',
            ],
        ]);

        Cache::forget('meta_capi_purchase_sent:' . $order->id);

        app(MetaConversionsApiService::class)->sendPurchaseForCompletedOrder($order);

        Http::assertSent(function ($request) use ($order) {
            $body = $request->data();
            $event = $body['data'][0] ?? [];
            $custom = $event['custom_data'] ?? [];
            $userData = $event['user_data'] ?? [];

            return $event['event_name'] === 'Purchase'
                && $event['event_id'] === MetaPurchaseTracking::purchaseEventId($order->id)
                && ($custom['currency'] ?? '') === 'USD'
                && ($userData['fbc'] ?? '') === 'fb.1.test'
                && ($userData['fbp'] ?? '') === 'fb.1.p.test';
        });

        $this->assertTrue(Cache::has('meta_capi_purchase_sent:' . $order->id));
    }

    public function test_send_purchase_skips_when_missing_access_token_and_logs(): void
    {
        Http::fake();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        [
                            'id' => 'meta-1',
                            'pixel_id' => '123456789',
                            'access_token' => '',
                        ],
                    ],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer2@example.com',
        ]);

        Cache::forget('meta_capi_purchase_sent:' . $order->id);

        app(MetaConversionsApiService::class)->sendPurchaseForCompletedOrder($order);

        Http::assertNothingSent();
        $this->assertFalse(Cache::has('meta_capi_purchase_sent:' . $order->id));
    }
}
