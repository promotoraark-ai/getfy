<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CajuPayMultiCurrencyTest extends TestCase
{
    public function test_cajupay_session_sends_display_currency_usd_to_api(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        Http::fake([
            '*/api/sdk/v1/checkout/sessions' => Http::response([
                'token' => 'tok_test_public',
                'checkout_session_id' => 'sess-usd-1',
                'methods_available' => ['card'],
            ], 201),
            '*/api/sdk/public/checkout/sessions/*' => Http::response([
                'methods_available' => ['card'],
            ], 200),
        ]);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        Setting::set('currencies', [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'USD', 'rate_to_brl' => 0.18],
        ], 1);

        $product = $this->createTestProduct([
            'price' => 100,
            'checkout_config' => array_merge(Product::defaultCheckoutConfig(), [
                'payment_gateways' => ['card' => 'cajupay'],
                'custom_prices_by_currency' => [
                    'enabled' => true,
                    'amounts' => ['USD' => 19.99],
                ],
            ]),
        ]);

        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);
        $cred->save();

        $response = $this->postJson(route('checkout.cajupay.session'), [
            'product_id' => $product->id,
            'payment_method' => 'card',
            'display_currency' => 'USD',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/sdk/v1/checkout/sessions')) {
                return false;
            }
            $body = $request->data();

            return ($body['currency'] ?? null) === 'USD'
                && (int) ($body['amount_cents'] ?? 0) === 1999;
        });
    }

    public function test_cajupay_session_sends_brl_when_display_currency_is_brl(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        Http::fake([
            '*/api/sdk/v1/checkout/sessions' => Http::response([
                'token' => 'tok_brl',
                'checkout_session_id' => 'sess-brl-1',
                'methods_available' => ['card'],
            ], 201),
            '*/api/sdk/public/checkout/sessions/*' => Http::response(['methods_available' => ['card']], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $product = $this->createTestProduct([
            'price' => 100,
            'checkout_config' => array_merge(Product::defaultCheckoutConfig(), [
                'payment_gateways' => ['card' => 'cajupay'],
            ]),
        ]);

        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['public_key' => 'pk_test', 'secret_key' => 'sk_test']);
        $cred->save();

        $this->postJson(route('checkout.cajupay.session'), [
            'product_id' => $product->id,
            'payment_method' => 'card',
            'display_currency' => 'BRL',
        ])->assertOk();

        Http::assertSent(fn ($request) => ($request->data()['currency'] ?? null) === 'BRL');
    }

    public function test_cajupay_confirm_order_does_not_require_cpf_when_charge_currency_is_usd(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'checkout_config' => array_merge(Product::defaultCheckoutConfig(), [
                'payment_gateways' => ['card' => 'cajupay'],
                'customer_fields' => [
                    'name' => true,
                    'cpf' => true,
                    'phone' => false,
                    'coupon' => false,
                ],
            ]),
        ]);

        $pollingToken = 'abcdefghijklmnopqrstuvwxyz123456';
        \Illuminate\Support\Facades\Cache::put('cajupay_draft.'.$pollingToken, [
            'product_id' => $product->id,
            'product_offer_id' => null,
            'subscription_plan_id' => null,
            'order_bump_ids' => [],
            'payment_method' => 'card',
            'coupon_code' => null,
            'total_amount' => 19.99,
            'base_amount' => 19.99,
            'charge_currency' => 'USD',
            'charge_amount' => 19.99,
            'display_currency' => 'USD',
            'display_amount' => 19.99,
            'cajupay_token' => 'tok_test',
            'checkout_session_id' => 'sess-usd-confirm',
            'tenant_id' => 1,
            'external_id' => 'ext-1',
            'methods_available' => ['card'],
            'created_at' => time(),
        ], now()->addMinutes(30));

        $response = $this->postJson(route('checkout.cajupay.confirm-order'), [
            'polling_token' => $pollingToken,
            'email' => 'buyer@example.com',
            'name' => 'John Buyer',
            'cpf' => '',
            'phone' => '',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('orders', [
            'email' => 'buyer@example.com',
            'currency' => 'USD',
            'gateway' => 'cajupay',
        ]);
    }

    public function test_cajupay_webhook_paid_updates_order_currency_and_amount(): void
    {
        \Illuminate\Support\Facades\Event::fake();

        $user = User::factory()->create();
        $product = $this->createTestProduct(['price' => 100]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 19.99,
            'currency' => 'USD',
            'email' => $user->email,
            'gateway' => 'cajupay',
            'gateway_id' => 'charge-usd-1',
            'metadata' => ['cajupay_checkout_session_id' => 'sess-usd-1'],
        ]);

        $cred = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
            'webhook_signing_secret' => 'cwhsec_testsecret123456789012345678901234',
        ]);
        $cred->save();

        $payload = [
            'type' => 'checkout.payment.paid',
            'data' => [
                'object' => [
                    'checkout_session_id' => 'sess-usd-1',
                    'cajupay_charge_id' => 'charge-usd-1',
                    'amount_cents' => 1999,
                    'currency' => 'usd',
                ],
            ],
        ];
        $raw = json_encode($payload);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, 'cwhsec_testsecret123456789012345678901234');

        $job = new ProcessPaymentWebhook(
            'cajupay',
            'charge-usd-1',
            'order.paid',
            'paid',
            array_merge($payload, ['webhook_source' => 'cajupay_hmac_verified'])
        );
        $job->handle();

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame('USD', $order->currency);
        $this->assertEquals(19.99, (float) $order->amount);
    }
}
