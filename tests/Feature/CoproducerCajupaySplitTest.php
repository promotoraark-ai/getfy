<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\CommissionEntry;
use App\Models\GatewayCredential;
use App\Models\GatewayFeeSetting;
use App\Models\Order;
use App\Models\ProductCoproducer;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CommissionAllocator;
use App\Services\CommissionSplitService;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class CoproducerCajupaySplitTest extends TestCase
{
    private const SPLIT_UUID = '550e8400-e29b-41d4-a716-446655440000';

    public function test_resolve_split_returns_uuid_for_single_coproducer_with_split_payout(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'cajupay_split_payout_enabled' => true,
        ]);
        $coprodUser = User::factory()->create(['role' => User::ROLE_COPRODUTOR, 'tenant_id' => 1]);

        ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $coprodUser->id,
            'email' => $coprodUser->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 15,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => false,
            'payout_method' => ProductCoproducer::PAYOUT_CAJUPAY_SPLIT,
            'cajupay_split_id' => self::SPLIT_UUID,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => ['checkout_payment_method' => 'pix', 'sale_channel' => 'producer'],
        ]);

        $split = app(CommissionSplitService::class)->resolveSplitForOrder($order, $product);

        $this->assertNotNull($split);
        $this->assertSame(self::SPLIT_UUID, $split['split_id']);
        $this->assertSame(CommissionEntry::ROLE_COPRODUTOR, $split['beneficiary_role']);
    }

    public function test_resolve_split_uses_coproducer_on_affiliate_sale(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'cajupay_split_payout_enabled' => true,
        ]);

        $affiliateUser = User::factory()->create(['role' => User::ROLE_AFILIADO, 'tenant_id' => 1]);
        $coprodUser = User::factory()->create(['role' => User::ROLE_COPRODUTOR, 'tenant_id' => 1]);

        \App\Models\ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => true,
            'default_commission_percent' => 20,
        ]);

        \App\Models\ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $affiliateUser->id,
            'affiliate_code' => 'splittest1',
            'status' => \App\Models\ProductAffiliate::STATUS_APPROVED,
            'cajupay_split_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $coprodUser->id,
            'email' => $coprodUser->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 10,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => true,
            'payout_method' => ProductCoproducer::PAYOUT_CAJUPAY_SPLIT,
            'cajupay_split_id' => self::SPLIT_UUID,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'affiliate_code' => 'splittest1',
                'sale_channel' => 'affiliate',
            ],
        ]);

        $split = app(CommissionSplitService::class)->resolveSplitForOrder($order, $product->fresh());

        $this->assertNotNull($split);
        $this->assertSame(self::SPLIT_UUID, $split['split_id']);
        $this->assertSame(CommissionEntry::ROLE_COPRODUTOR, $split['beneficiary_role']);
    }

    public function test_resolve_split_picks_highest_percent_when_multiple_coproducers_with_split(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'cajupay_split_payout_enabled' => true,
        ]);

        $userA = User::factory()->create(['role' => User::ROLE_COPRODUTOR, 'tenant_id' => 1]);
        $userB = User::factory()->create(['role' => User::ROLE_COPRODUTOR, 'tenant_id' => 1]);

        ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $userA->id,
            'email' => $userA->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 5,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => false,
            'payout_method' => ProductCoproducer::PAYOUT_CAJUPAY_SPLIT,
            'cajupay_split_id' => '11111111-1111-4111-8111-111111111111',
        ]);

        ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $userB->id,
            'email' => $userB->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 15,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => false,
            'payout_method' => ProductCoproducer::PAYOUT_CAJUPAY_SPLIT,
            'cajupay_split_id' => self::SPLIT_UUID,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'metadata' => ['checkout_payment_method' => 'pix', 'sale_channel' => 'producer'],
        ]);

        $split = app(CommissionSplitService::class)->resolveSplitForOrder($order, $product->fresh());

        $this->assertNotNull($split);
        $this->assertSame(self::SPLIT_UUID, $split['split_id']);
        $this->assertTrue($split['multiple_coproducer_splits'] ?? false);
    }

    public function test_affiliate_gets_wallet_credit_when_coproducer_uses_split_on_same_order(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'cajupay_split_payout_enabled' => true,
        ]);

        $affiliateUser = User::factory()->create(['role' => User::ROLE_AFILIADO, 'tenant_id' => 1]);
        $coprodUser = User::factory()->create(['role' => User::ROLE_COPRODUTOR, 'tenant_id' => 1]);

        \App\Models\ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => true,
            'default_commission_percent' => 20,
        ]);

        \App\Models\ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $affiliateUser->id,
            'affiliate_code' => 'affmix1',
            'status' => \App\Models\ProductAffiliate::STATUS_APPROVED,
        ]);

        $coproducer = ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $coprodUser->id,
            'email' => $coprodUser->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 10,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => true,
            'payout_method' => ProductCoproducer::PAYOUT_CAJUPAY_SPLIT,
            'cajupay_split_id' => self::SPLIT_UUID,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'affiliate_code' => 'affmix1',
                'sale_channel' => 'affiliate',
                'cajupay_split_id' => self::SPLIT_UUID,
                'cajupay_split_beneficiary_role' => CommissionEntry::ROLE_COPRODUTOR,
                'cajupay_split_beneficiary_id' => $coproducer->id,
            ],
        ]);

        GatewayFeeSetting::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'method' => 'pix',
            'percent' => 0,
            'fixed_cents' => 0,
        ]);

        app(CommissionAllocator::class)->allocateForCompletedOrder($order->fresh());

        $affiliateEntry = CommissionEntry::query()
            ->where('order_id', $order->id)
            ->where('role', CommissionEntry::ROLE_AFILIADO)
            ->first();

        $coprodEntry = CommissionEntry::query()
            ->where('order_id', $order->id)
            ->where('role', CommissionEntry::ROLE_COPRODUTOR)
            ->first();

        $this->assertNotNull($affiliateEntry);
        $this->assertSame(CommissionEntry::STATUS_PENDING, $affiliateEntry->status);
        $this->assertTrue(
            WalletTransaction::query()->where('commission_entry_id', $affiliateEntry->id)->exists()
        );

        $this->assertNotNull($coprodEntry);
        $this->assertSame(CommissionEntry::STATUS_SETTLED_EXTERNALLY, $coprodEntry->status);
        $this->assertFalse(
            WalletTransaction::query()->where('commission_entry_id', $coprodEntry->id)->exists()
        );
    }

    public function test_allocator_skips_wallet_credit_when_order_used_cajupay_split(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'cajupay_split_payout_enabled' => true,
        ]);
        $coprodUser = User::factory()->create(['role' => User::ROLE_COPRODUTOR, 'tenant_id' => 1]);

        $coproducer = ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $coprodUser->id,
            'email' => $coprodUser->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 20,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => false,
            'payout_method' => ProductCoproducer::PAYOUT_CAJUPAY_SPLIT,
            'cajupay_split_id' => self::SPLIT_UUID,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'sale_channel' => 'producer',
                'cajupay_split_id' => self::SPLIT_UUID,
                'cajupay_split_beneficiary_role' => CommissionEntry::ROLE_COPRODUTOR,
                'cajupay_split_beneficiary_id' => $coproducer->id,
            ],
        ]);

        GatewayFeeSetting::create([
            'tenant_id' => 1,
            'gateway_slug' => 'cajupay',
            'method' => 'pix',
            'percent' => 0,
            'fixed_cents' => 0,
        ]);

        app(CommissionAllocator::class)->allocateForCompletedOrder($order->fresh());

        $entry = CommissionEntry::query()
            ->where('order_id', $order->id)
            ->where('role', CommissionEntry::ROLE_COPRODUTOR)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(CommissionEntry::STATUS_SETTLED_EXTERNALLY, $entry->status);
        $this->assertTrue((bool) ($entry->metadata['cajupay_split'] ?? false));

        $this->assertFalse(
            WalletTransaction::query()
                ->where('commission_entry_id', $entry->id)
                ->exists()
        );
    }

    public function test_assign_coproducer_with_split_requires_uuid(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'cajupay_split_payout_enabled' => true,
        ]);
        $teamUser = User::factory()->create(['role' => User::ROLE_TEAM, 'tenant_id' => 1]);

        $this->seedCajupayCredential(1);

        $this->actingAs($owner)
            ->postJson("/produtos/{$product->id}/coproducers/assign", [
                'user_id' => $teamUser->id,
                'commission_percent' => 10,
                'payout_method' => 'cajupay_split',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cajupay_split_id']);
    }

    public function test_assign_coproducer_with_split_succeeds_with_uuid(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'cajupay_split_payout_enabled' => true,
        ]);
        $teamUser = User::factory()->create(['role' => User::ROLE_TEAM, 'tenant_id' => 1]);

        $this->seedCajupayCredential(1);

        $this->actingAs($owner)
            ->postJson("/produtos/{$product->id}/coproducers/assign", [
                'user_id' => $teamUser->id,
                'commission_percent' => 10,
                'payout_method' => 'cajupay_split',
                'cajupay_split_id' => self::SPLIT_UUID,
            ])
            ->assertOk();

        $this->assertDatabaseHas('product_coproducers', [
            'product_id' => $product->id,
            'user_id' => $teamUser->id,
            'payout_method' => ProductCoproducer::PAYOUT_CAJUPAY_SPLIT,
            'cajupay_split_id' => self::SPLIT_UUID,
        ]);
    }

    private function seedCajupayCredential(int $tenantId): void
    {
        GatewayCredential::create([
            'tenant_id' => $tenantId,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
            'credentials' => Crypt::encryptString(json_encode(['api_key' => 'test'])),
        ]);
    }
}
