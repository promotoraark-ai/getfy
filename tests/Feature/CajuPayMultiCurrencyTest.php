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
            'billing_country' => 'US',
            'currency_user_selected' => false,
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

    public function test_cajupay_session_charges_brl_for_brazil_when_display_usd_not_manual(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        Http::fake([
            '*/api/sdk/v1/checkout/sessions' => Http::response([
                'token' => 'tok_brl_br',
                'checkout_session_id' => 'sess-brl-br',
                'methods_available' => ['card'],
            ], 201),
            '*/api/sdk/public/checkout/sessions/*' => Http::response(['methods_available' => ['card']], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        Setting::set('currencies', [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'USD', 'rate_to_brl' => 0.18],
        ], 1);

        $product = $this->createTestProduct([
            'price' => 27,
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
            'display_currency' => 'USD',
            'billing_country' => 'BR',
            'currency_user_selected' => false,
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/sdk/v1/checkout/sessions')) {
                return false;
            }
            $body = $request->data();

            return ($body['currency'] ?? null) === 'BRL'
                && (int) ($body['amount_cents'] ?? 0) === 2700;
        });
    }

    public function test_cajupay_session_charges_usd_for_brazil_when_currency_manual(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        Http::fake([
            '*/api/sdk/v1/checkout/sessions' => Http::response([
                'token' => 'tok_usd_br_manual',
                'checkout_session_id' => 'sess-usd-br-manual',
                'methods_available' => ['card'],
            ], 201),
            '*/api/sdk/public/checkout/sessions/*' => Http::response(['methods_available' => ['card']], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        Setting::set('currencies', [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'USD', 'rate_to_brl' => 0.18],
        ], 1);

        $product = $this->createTestProduct([
            'price' => 27,
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
            'display_currency' => 'USD',
            'billing_country' => 'BR',
            'currency_user_selected' => true,
        ])->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/sdk/v1/checkout/sessions')) {
                return false;
            }
            $body = $request->data();

            return ($body['currency'] ?? null) === 'USD'
                && (int) ($body['amount_cents'] ?? 0) === 486;
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
                    'settlement_amount_cents' => 11000,
                    'settlement_currency' => 'brl',
                    'fx_rate' => '5.502500',
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
        $meta = $order->metadata ?? [];
        $this->assertSame(11000, $meta['settlement_amount_cents'] ?? null);
        $this->assertSame('BRL', $meta['settlement_currency'] ?? null);
        $this->assertSame('5.502500', $meta['fx_rate'] ?? null);
    }

    public function test_cajupay_confirm_order_google_pay_links_gateway_id_to_checkout_session(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'checkout_config' => array_merge(Product::defaultCheckoutConfig(), [
                'payment_gateways' => ['google_pay' => 'cajupay', 'card' => 'cajupay'],
            ]),
        ]);

        $pollingToken = 'abcdefghijklmnopqrstuvwxyz123457';
        $sessionId = 'sess-google-pay-001';
        \Illuminate\Support\Facades\Cache::put('cajupay_session_by_checkout.'.$sessionId, $pollingToken, now()->addMinutes(30));
        \Illuminate\Support\Facades\Cache::put('cajupay_draft.'.$pollingToken, [
            'product_id' => $product->id,
            'product_offer_id' => null,
            'subscription_plan_id' => null,
            'order_bump_ids' => [],
            'payment_method' => 'google_pay',
            'coupon_code' => null,
            'total_amount' => 49.90,
            'base_amount' => 49.90,
            'charge_currency' => 'BRL',
            'charge_amount' => 49.90,
            'display_currency' => 'BRL',
            'display_amount' => 49.90,
            'cajupay_token' => 'tok_google',
            'checkout_session_id' => $sessionId,
            'tenant_id' => 1,
            'external_id' => 'ext-gpay',
            'methods_available' => ['google_pay', 'card'],
            'created_at' => time(),
        ], now()->addMinutes(30));

        $response = $this->postJson(route('checkout.cajupay.confirm-order'), [
            'polling_token' => $pollingToken,
            'email' => 'wallet@example.com',
            'name' => 'Wallet Buyer',
            'cpf' => '52998224725',
            'phone' => '',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('orders', [
            'email' => 'wallet@example.com',
            'gateway' => 'cajupay',
            'gateway_id' => $sessionId,
            'status' => 'pending',
        ]);
        $order = Order::where('email', 'wallet@example.com')->first();
        $this->assertNotNull($order);
        $meta = $order->metadata ?? [];
        $this->assertSame('google_pay', $meta['checkout_payment_method'] ?? null);
        $this->assertSame($sessionId, $meta['cajupay_checkout_session_id'] ?? null);
    }

    public function test_cajupay_session_sends_locale_en_to_api(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        Http::fake([
            '*/api/sdk/v1/checkout/sessions' => Http::response([
                'token' => 'tok_en',
                'checkout_session_id' => 'sess-en-1',
            ], 201),
            '*/api/sdk/public/checkout/sessions/*' => Http::response(['methods_available' => ['card']], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $product = $this->createTestProduct([
            'price' => 50,
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

        $response = $this->postJson(route('checkout.cajupay.session'), [
            'product_id' => $product->id,
            'payment_method' => 'card',
            'checkout_locale' => 'en',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/sdk/v1/checkout/sessions')) {
                return false;
            }

            return ($request->data()['locale'] ?? null) === 'en';
        });
    }

    public function test_cajupay_session_maps_pt_br_locale_to_bcp47(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        Http::fake([
            '*/api/sdk/v1/checkout/sessions' => Http::response([
                'token' => 'tok_pt',
                'checkout_session_id' => 'sess-pt-1',
            ], 201),
            '*/api/sdk/public/checkout/sessions/*' => Http::response(['methods_available' => ['card']], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $product = $this->createTestProduct([
            'price' => 50,
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

        $response = $this->postJson(route('checkout.cajupay.session'), [
            'product_id' => $product->id,
            'payment_method' => 'card',
            'checkout_locale' => 'pt_BR',
        ]);

        $response->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/sdk/v1/checkout/sessions')) {
                return false;
            }

            return ($request->data()['locale'] ?? null) === 'pt-BR';
        });
    }
}
