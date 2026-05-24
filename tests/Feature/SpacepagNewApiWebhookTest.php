<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpacepagNewApiWebhookTest extends TestCase
{
    public function test_new_webhook_payload_pix_in_confirmation_completes_order(): void
    {
        Http::fake([
            'https://api.spacepag.com/v1/payments/transactions/txn_webhook_test' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'txn_webhook_test',
                    'status' => 'APPROVED',
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'SP']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'sp@test.com',
            'gateway' => 'spacepag',
            'gateway_id' => 'txn_webhook_test',
        ]);

        $body = [
            'id' => 'evt_test',
            'type' => 'pix.in.confirmation',
            'created_at' => '2025-11-10T18:16:49-03:00',
            'data' => [
                'id' => '31c7a7bd-e115-42be-bc69-adc7698b5ef7',
                'transactionId' => 'txn_webhook_test',
                'status' => 'APPROVED',
                'type' => 'CHARGE',
            ],
        ];

        $response = $this->postJson('/webhooks/gateways/spacepag', $body);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_new_webhook_finds_order_by_uuid_when_gateway_id_is_uuid(): void
    {
        Http::fake([
            'https://api.spacepag.com/v1/payments/transactions/31c7a7bd-e115-42be-bc69-adc7698b5ef7' => Http::response([
                'success' => true,
                'data' => [
                    'id' => '31c7a7bd-e115-42be-bc69-adc7698b5ef7',
                    'status' => 'APPROVED',
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'SP2']);

        $uuid = '31c7a7bd-e115-42be-bc69-adc7698b5ef7';
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'sp2@test.com',
            'gateway' => 'spacepag',
            'gateway_id' => $uuid,
        ]);

        $body = [
            'id' => 'evt_test2',
            'type' => 'pix.in.confirmation',
            'created_at' => '2025-11-10T18:16:49-03:00',
            'data' => [
                'id' => $uuid,
                'transactionId' => 'txn_other',
                'status' => 'APPROVED',
            ],
        ];

        $response = $this->postJson('/webhooks/gateways/spacepag', $body);

        $response->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_legacy_webhook_still_works(): void
    {
        Http::fake([
            'https://api.spacepag.com/v1/payments/transactions/sp-legacy-1' => Http::response([
                'success' => true,
                'data' => ['id' => 'sp-legacy-1', 'status' => 'APPROVED'],
            ], 200),
        ]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'SP3']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'sp3@test.com',
            'gateway' => 'spacepag',
            'gateway_id' => 'sp-legacy-1',
        ]);

        $response = $this->postJson('/webhooks/gateways/spacepag', [
            'event' => 'order.paid',
            'transaction_id' => 'sp-legacy-1',
            'status' => 'paid',
        ]);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('completed', $order->fresh()->status);
    }
}
