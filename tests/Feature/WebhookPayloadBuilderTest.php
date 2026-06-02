<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Jobs\DispatchWebhookJob;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductOffer;
use App\Models\User;
use App\Models\Webhook;
use App\Support\WebhookPayloadBuilder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookPayloadBuilderTest extends TestCase
{
    public function test_order_payload_includes_offer_and_product_details(): void
    {
        $product = $this->createTestProduct([
            'name' => 'Curso Premium',
            'checkout_slug' => 'curso-premium',
        ]);

        $offer = ProductOffer::create([
            'product_id' => $product->id,
            'name' => 'Oferta Black Friday',
            'price' => 149.90,
            'currency' => 'BRL',
            'position' => 0,
        ]);

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'product_offer_id' => $offer->id,
            'status' => 'completed',
            'amount' => 149.90,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'utm_source' => 'meta',
            ],
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);

        $this->assertSame('Curso Premium', $payload['product']['name']);
        $this->assertSame('Oferta Black Friday', $payload['offer']['name']);
        $this->assertSame($offer->id, $payload['products'][0]['offer']['id']);
        $this->assertSame('pix', $payload['payment']['method']);
        $this->assertSame('meta', $payload['tracking']['utm_source']);
        $this->assertSame($product->id, $payload['order']['product_id']);
    }

    public function test_order_payload_includes_order_bump_line_items(): void
    {
        $main = $this->createTestProduct(['name' => 'Produto principal']);
        $bumpProduct = $this->createTestProduct(['name' => 'Order bump extra']);

        $order = Order::create([
            'tenant_id' => $main->tenant_id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 120,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 100,
            'position' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bumpProduct->id,
            'amount' => 20,
            'position' => 1,
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order->fresh());

        $this->assertCount(2, $payload['products']);
        $this->assertTrue($payload['products'][0]['is_main']);
        $this->assertFalse($payload['products'][0]['is_order_bump']);
        $this->assertTrue($payload['products'][1]['is_order_bump']);
        $this->assertSame('Order bump extra', $payload['products'][1]['name']);
    }

    public function test_tracking_merges_checkout_session_utms(): void
    {
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'metadata' => [],
        ]);

        CheckoutSession::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'tok-'.uniqid('', true),
            'step' => CheckoutSession::STEP_CONVERTED,
            'order_id' => $order->id,
            'utm_source' => 'google',
            'utm_campaign' => 'spring',
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);

        $this->assertSame('google', $payload['tracking']['utm_source']);
        $this->assertSame('spring', $payload['tracking']['utm_campaign']);
    }

    public function test_dispatch_webhook_job_sends_products_array(): void
    {
        Http::fake(['https://hook.example/*' => Http::response('ok', 200)]);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => $owner->tenant_id, 'name' => 'Webhook Product']);

        $webhook = Webhook::create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Test hook',
            'url' => 'https://hook.example/callback',
            'events' => [OrderCompleted::class],
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => $owner->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99,
            'currency' => 'BRL',
            'email' => 'hook@test.com',
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);
        (new DispatchWebhookJob($webhook->id, OrderCompleted::class, $payload))->handle();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['payload']['products'][0]['name'])
                && $body['payload']['products'][0]['name'] === 'Webhook Product'
                && $body['event'] === 'pedido_pago';
        });
    }
}
