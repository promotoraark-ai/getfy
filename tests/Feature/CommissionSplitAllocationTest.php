<?php

namespace Tests\Feature;

use App\Models\CommissionEntry;
use App\Models\GatewayFeeSetting;
use App\Models\Order;
use App\Models\ProductAffiliate;
use App\Models\ProductAffiliateProgram;
use App\Models\ProductCoproducer;
use App\Models\User;
use App\Services\CommissionAllocator;
use App\Services\CommissionBeneficiaryResolver;
use Tests\TestCase;

class CommissionSplitAllocationTest extends TestCase
{
    public function test_affiliate_and_coproducer_split_net_on_affiliate_sale(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1, 'price' => 100]);
        $affiliateUser = User::factory()->create(['role' => User::ROLE_AFILIADO, 'tenant_id' => 1]);
        $coprodUser = User::factory()->create(['role' => User::ROLE_COPRODUTOR, 'tenant_id' => 1]);

        ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => true,
            'default_commission_percent' => 20,
            'manual_approval' => false,
        ]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $affiliateUser->id,
            'affiliate_code' => 'aff20pct',
            'status' => ProductAffiliate::STATUS_APPROVED,
        ]);

        ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $coprodUser->id,
            'email' => $coprodUser->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 10,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => true,
        ]);

        $order = $this->completedOrder($owner, $product, [
            'affiliate_code' => 'aff20pct',
            'sale_channel' => 'affiliate',
        ]);

        app(CommissionAllocator::class)->allocateForCompletedOrder($order->fresh());

        $entries = CommissionEntry::where('order_id', $order->id)->get();
        $this->assertCount(3, $entries);

        $affiliateEntry = $entries->firstWhere('role', CommissionEntry::ROLE_AFILIADO);
        $coprodEntry = $entries->firstWhere('role', CommissionEntry::ROLE_COPRODUTOR);
        $producerEntry = $entries->firstWhere('role', CommissionEntry::ROLE_PRODUTOR);

        $this->assertSame(20.0, (float) $affiliateEntry->commission_amount);
        $this->assertSame(10.0, (float) $coprodEntry->commission_amount);
        $this->assertSame(70.0, (float) $producerEntry->commission_amount);
        $this->assertSame(100.0, (float) $affiliateEntry->net_amount);
    }

    public function test_no_affiliate_commission_when_sale_channel_is_producer(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $affiliateUser = User::factory()->create(['role' => User::ROLE_AFILIADO, 'tenant_id' => 1]);

        ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => true,
            'default_commission_percent' => 30,
        ]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $affiliateUser->id,
            'affiliate_code' => 'aff30pct',
            'status' => ProductAffiliate::STATUS_APPROVED,
        ]);

        $order = $this->completedOrder($owner, $product, [
            'affiliate_code' => 'aff30pct',
            'sale_channel' => 'producer',
        ]);

        app(CommissionAllocator::class)->allocateForCompletedOrder($order->fresh());

        $this->assertDatabaseMissing('commission_entries', [
            'order_id' => $order->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
        ]);
    }

    public function test_coproducer_skipped_on_affiliate_sale_when_flag_disabled(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $coprodUser = User::factory()->create(['role' => User::ROLE_COPRODUTOR, 'tenant_id' => 1]);

        ProductAffiliateProgram::create(['product_id' => $product->id, 'enabled' => true, 'default_commission_percent' => 0]);

        ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $coprodUser->id,
            'email' => $coprodUser->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 25,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => false,
        ]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'affiliate_code' => 'anycode1',
            'status' => ProductAffiliate::STATUS_APPROVED,
        ]);

        $order = $this->completedOrder($owner, $product, [
            'affiliate_code' => 'anycode1',
            'sale_channel' => 'affiliate',
        ]);

        app(CommissionAllocator::class)->allocateForCompletedOrder($order->fresh());

        $this->assertDatabaseMissing('commission_entries', [
            'order_id' => $order->id,
            'role' => CommissionEntry::ROLE_COPRODUTOR,
        ]);
    }

    public function test_pending_affiliate_does_not_receive_commission(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        ProductAffiliateProgram::create(['product_id' => $product->id, 'enabled' => true, 'default_commission_percent' => 50]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'affiliate_code' => 'pending1',
            'status' => ProductAffiliate::STATUS_PENDING,
        ]);

        $order = $this->completedOrder($owner, $product, [
            'affiliate_code' => 'pending1',
            'sale_channel' => 'affiliate',
        ]);

        app(CommissionAllocator::class)->allocateForCompletedOrder($order->fresh());

        $this->assertDatabaseMissing('commission_entries', [
            'order_id' => $order->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
        ]);
    }

    public function test_percentages_over_100_are_scaled(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $program = ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => true,
            'default_commission_percent' => 60,
        ]);

        $affiliate = ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'affiliate_code' => 'bigaff',
            'status' => ProductAffiliate::STATUS_APPROVED,
        ]);

        ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'email' => 'c@test.com',
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 60,
            'commission_on_affiliate_sales' => true,
            'commission_on_producer_sales' => true,
        ]);

        $order = $this->completedOrder($owner, $product, [
            'affiliate_code' => 'bigaff',
            'sale_channel' => 'affiliate',
        ]);

        $resolver = app(CommissionBeneficiaryResolver::class);
        $beneficiaries = $resolver->resolve($order, $product, $program);
        $allocated = $resolver->allocateAmounts(100, $beneficiaries);
        $producer = $resolver->producerShare(100, $allocated);

        $partnerSum = array_sum(array_column($allocated, 'commission_amount'));
        $this->assertEqualsWithDelta(100, $partnerSum + $producer, 0.01);
        $this->assertLessThanOrEqual(100, array_sum(array_column($beneficiaries, 'percent')));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function completedOrder(User $owner, $product, array $metadata): Order
    {
        GatewayFeeSetting::create([
            'tenant_id' => $owner->tenant_id,
            'gateway_slug' => 'cajupay',
            'method' => 'pix',
            'percent' => 0,
            'fixed_cents' => 0,
        ]);

        return Order::create([
            'tenant_id' => $owner->tenant_id,
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => array_merge(['checkout_payment_method' => 'pix'], $metadata),
        ]);
    }
}
