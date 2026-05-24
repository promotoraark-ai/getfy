<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Jobs\SendMetaPurchaseCapiJob;
use App\Jobs\UtmifySendOrderJob;
use App\Models\Order;
use App\Models\UtmifyIntegration;
use App\Models\User;
use App\Services\MetaConversionsApiService;
use App\Listeners\SendMetaPurchaseCapiOnOrderCompleted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderCompletedTrackingTest extends TestCase
{
    public function test_order_completed_dispatches_meta_capi_job(): void
    {
        Bus::fake();

        config(['queue.default' => 'redis']);
        config(['getfy.integrations.dispatch_after_response' => false]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'track@example.com',
        ]);

        app(SendMetaPurchaseCapiOnOrderCompleted::class)->handle(new OrderCompleted($order));

        Bus::assertDispatched(SendMetaPurchaseCapiJob::class, function (SendMetaPurchaseCapiJob $job) use ($order) {
            return $job->orderId === $order->id;
        });
    }

    public function test_order_completed_dispatches_utmify_paid_only_once(): void
    {
        Bus::fake();

        config(['queue.default' => 'sync']);
        config(['getfy.integrations.dispatch_after_response' => false]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 75,
            'email' => 'utmify@example.com',
        ]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'Test UTMify',
            'api_key' => 'utmify_test_key',
            'is_active' => true,
        ]);
        $integration->products()->sync([$product->id]);

        Cache::forget('utmify_paid_sent:' . $order->id);

        event(new OrderCompleted($order));
        event(new OrderCompleted($order->fresh()));

        Bus::assertDispatched(UtmifySendOrderJob::class, 1);
    }

    public function test_utmify_send_order_job_posts_paid_status(): void
    {
        Http::fake([
            'https://api.utmify.com.br/*' => Http::response([], 200),
        ]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 75,
            'email' => 'utmify-job@example.com',
            'metadata' => ['utm_source' => 'facebook'],
        ]);

        $integration = UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'Test UTMify job',
            'api_key' => 'utmify_test_key',
            'is_active' => true,
        ]);
        $integration->products()->sync([$product->id]);

        $job = new UtmifySendOrderJob(
            $integration->id,
            $order->id,
            'paid',
            now()->utc()->format('Y-m-d H:i:s'),
            null
        );
        $job->handle(app(\App\Services\UtmifyService::class));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'api.utmify.com.br')
                && ($body['status'] ?? '') === 'paid';
        });
    }

    public function test_order_completed_dispatches_utmify_after_response_when_sync_queue(): void
    {
        Bus::fake();

        config(['queue.default' => 'sync']);
        config(['getfy.integrations.dispatch_after_response' => true]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 40,
            'email' => 'after-response@example.com',
        ]);

        UtmifyIntegration::create([
            'tenant_id' => 1,
            'name' => 'UTMify after response',
            'api_key' => 'utmify_test_key',
            'is_active' => true,
        ]);

        Cache::forget('utmify_paid_sent:' . $order->id);

        event(new OrderCompleted($order));

        Bus::assertDispatchedAfterResponse(UtmifySendOrderJob::class);
    }

    public function test_meta_capi_job_sends_without_thank_you_page(): void
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
                            'id' => 'm1',
                            'pixel_id' => '999888777',
                            'access_token' => 'capitoken',
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
            'amount' => 120,
            'currency' => 'BRL',
            'email' => 'webhook-only@example.com',
        ]);

        Cache::forget('meta_capi_purchase_sent:' . $order->id);

        $job = new SendMetaPurchaseCapiJob($order->id);
        $job->handle(app(MetaConversionsApiService::class));

        Http::assertSentCount(1);
        $this->assertSame('completed', $order->fresh()->status);
    }
}
