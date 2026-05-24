<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\Plugin;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Plugins\WebhookEntrada\Models\InboundWebhookEndpoint;
use Tests\TestCase;

class WebhookEntradaInboundTest extends TestCase
{
    public function test_post_creates_completed_order_and_product_access(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);
        Mail::fake();

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => 1,
        ]);

        Plugin::query()->updateOrCreate(
            ['slug' => 'webhook-entrada'],
            ['name' => 'Webhook de entrada', 'version' => '1.0.0', 'is_enabled' => true]
        );

        $token = str_repeat('a', 64);
        $endpoint = InboundWebhookEndpoint::create([
            'tenant_id' => 1,
            'name' => 'Test',
            'is_active' => true,
            'url_token' => $token,
            'product_id' => $product->id,
            'product_offer_id' => null,
            'subscription_plan_id' => null,
            'field_map' => [
                'email' => 'buyer.email',
                'name' => 'buyer.name',
                'external_id' => 'sale_id',
            ],
            'signing_secret' => null,
        ]);

        $payload = [
            'buyer' => [
                'email' => 'extbuyer@example.com',
                'name' => 'Comprador Externo',
            ],
            'sale_id' => 'EXT-1001',
        ];

        $res = $this->postJson('/webhooks/inbound/'.$token, $payload);
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['order_id']);

        $orderId = $res->json('order_id');
        $this->assertNotNull($orderId);

        $order = Order::query()->find($orderId);
        $this->assertNotNull($order);
        $this->assertSame('completed', $order->status);
        $this->assertSame($product->id, $order->product_id);
        $this->assertSame('inbound_webhook', $order->gateway);

        $user = User::query()->where('email', 'extbuyer@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->products()->where('products.id', $product->id)->exists());

        $dup = $this->postJson('/webhooks/inbound/'.$token, $payload);
        $dup->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, Order::query()->where('product_id', $product->id)->where('email', 'extbuyer@example.com')->count());
    }

    public function test_signature_required_when_secret_set(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => 1,
        ]);

        Plugin::query()->updateOrCreate(
            ['slug' => 'webhook-entrada'],
            ['name' => 'Webhook de entrada', 'version' => '1.0.0', 'is_enabled' => true]
        );

        $token = str_repeat('b', 64);
        $secret = 'test-secret';
        InboundWebhookEndpoint::create([
            'tenant_id' => 1,
            'name' => 'Signed',
            'is_active' => true,
            'url_token' => $token,
            'product_id' => $product->id,
            'field_map' => ['email' => 'email'],
            'signing_secret' => $secret,
        ]);

        $body = json_encode(['email' => 'signed@example.com'], JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $body, $secret);

        $bad = $this->call('POST', '/webhooks/inbound/'.$token, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
        $bad->assertStatus(401);

        $ok = $this->call('POST', '/webhooks/inbound/'.$token, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256='.$sig,
        ], $body);
        $ok->assertOk()->assertJsonPath('success', true);
    }
}
