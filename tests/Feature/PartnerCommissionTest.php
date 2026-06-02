<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\GatewayFeeSetting;
use App\Models\Order;
use App\Models\ProductAffiliate;
use App\Models\ProductAffiliateProgram;
use App\Models\ProductCoproducer;
use App\Models\User;
use App\Services\CommissionAllocator;
use Tests\TestCase;

class PartnerCommissionTest extends TestCase
{
    public function test_affiliate_commission_allocated_on_completed_order(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);
        $product = $this->createTestProduct(['tenant_id' => $owner->tenant_id]);
        $affiliateUser = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => $owner->tenant_id,
        ]);

        ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => true,
            'default_commission_percent' => 20,
            'manual_approval' => false,
        ]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $affiliateUser->id,
            'affiliate_code' => 'abc12345',
            'status' => ProductAffiliate::STATUS_APPROVED,
        ]);

        $order = Order::create([
            'tenant_id' => $owner->tenant_id,
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'affiliate_code' => 'abc12345',
                'sale_channel' => 'affiliate',
            ],
        ]);

        GatewayFeeSetting::create([
            'tenant_id' => $owner->tenant_id,
            'gateway_slug' => 'cajupay',
            'method' => 'pix',
            'percent' => 1,
            'fixed_cents' => 0,
        ]);

        app(CommissionAllocator::class)->allocateForCompletedOrder($order->fresh());

        $this->assertDatabaseHas('commission_entries', [
            'order_id' => $order->id,
            'role' => 'afiliado',
            'beneficiary_user_id' => $affiliateUser->id,
        ]);
    }

    public function test_partner_cannot_access_product_edit(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
        ]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $partner->id,
            'affiliate_code' => 'xyz99999',
            'status' => ProductAffiliate::STATUS_APPROVED,
        ]);

        $this->actingAs($partner)
            ->get('/produtos/'.$product->id.'/edit')
            ->assertForbidden();
    }

    public function test_partner_can_access_parceiro_dashboard_metrics(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
        ]);

        $this->actingAs($partner)
            ->get('/parceiro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('comissao_total')
                ->has('grafico_comissoes')
                ->has('vendas_recentes')
            );
    }

    public function test_partner_can_access_parceiro_dashboard(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_COPRODUTOR,
            'tenant_id' => 1,
        ]);

        ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $partner->id,
            'email' => $partner->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 15,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => true,
        ]);

        $this->actingAs($partner)
            ->get('/parceiro')
            ->assertOk();
    }

    public function test_coprodutor_can_access_parceiro_financeiro(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_COPRODUTOR,
            'tenant_id' => 1,
        ]);

        ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $partner->id,
            'email' => $partner->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 10,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => false,
        ]);

        $this->actingAs($partner)
            ->get('/parceiro/financeiro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Partner/Financeiro')
                ->has('balances.by_wallet')
                ->has('balances.totals')
                ->has('wallet_labels')
                ->has('commissions')
            );
    }

    public function test_team_member_with_coproducer_membership_can_access_parceiro_financeiro(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $teamUser = User::factory()->create([
            'role' => User::ROLE_TEAM,
            'tenant_id' => 1,
        ]);

        ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $teamUser->id,
            'email' => $teamUser->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => 20,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => true,
        ]);

        $this->actingAs($teamUser)
            ->get('/parceiro/financeiro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Partner/Financeiro'));
    }

    public function test_approved_affiliate_sees_product_on_dashboard(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1, 'name' => 'Curso Teste']);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
        ]);

        ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => true,
            'default_commission_percent' => 25,
            'manual_approval' => false,
        ]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $partner->id,
            'affiliate_code' => 'aff11111',
            'status' => ProductAffiliate::STATUS_APPROVED,
        ]);

        $this->actingAs($partner)
            ->get('/parceiro/produtos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('products.0.id', $product->id)
                ->where('products.0.affiliate_status', ProductAffiliate::STATUS_APPROVED)
            );
    }

    public function test_pending_affiliate_sees_product_on_dashboard(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
        ]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $partner->id,
            'affiliate_code' => 'pend1234',
            'status' => ProductAffiliate::STATUS_PENDING,
        ]);

        $this->actingAs($partner)
            ->get('/parceiro/produtos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('products.0.affiliate_status', ProductAffiliate::STATUS_PENDING)
            );
    }

    public function test_pending_affiliate_cannot_update_pixels(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
        ]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $partner->id,
            'affiliate_code' => 'pend5678',
            'status' => ProductAffiliate::STATUS_PENDING,
        ]);

        $this->actingAs($partner)
            ->get('/parceiro/produtos/'.$product->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_edit_pixels', false)
                ->where('can_use_links', false)
            );

        $this->actingAs($partner)
            ->put('/parceiro/produtos/'.$product->id.'/pixels', [
                'affiliate_pixels' => ['meta' => ['enabled' => true, 'entries' => []]],
            ])
            ->assertForbidden();
    }

    public function test_approved_affiliate_can_update_pixels(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
        ]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $partner->id,
            'affiliate_code' => 'appr9999',
            'status' => ProductAffiliate::STATUS_APPROVED,
        ]);

        $pixels = [
            'meta' => ['enabled' => true, 'entries' => [['id' => '1', 'pixel_id' => '123', 'access_token' => 'tok']]],
            'tiktok' => ['enabled' => false, 'entries' => []],
            'google_ads' => ['enabled' => false, 'entries' => []],
            'google_analytics' => ['enabled' => false, 'entries' => []],
            'custom_script' => [],
        ];

        $this->actingAs($partner)
            ->put('/parceiro/produtos/'.$product->id.'/pixels', [
                'affiliate_pixels' => $pixels,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('product_affiliates', [
            'product_id' => $product->id,
            'user_id' => $partner->id,
        ]);
    }

    public function test_partner_vendas_lists_pending_pix_attributed_to_affiliate(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => $owner->tenant_id]);
        $affiliateUser = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => $owner->tenant_id,
        ]);

        ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => true,
            'default_commission_percent' => 20,
            'manual_approval' => false,
        ]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $affiliateUser->id,
            'affiliate_code' => 'refpix01',
            'status' => ProductAffiliate::STATUS_APPROVED,
        ]);

        $order = Order::create([
            'tenant_id' => $owner->tenant_id,
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'affiliate_code' => 'refpix01',
                'sale_channel' => 'affiliate',
            ],
        ]);

        $this->actingAs($affiliateUser)
            ->get('/parceiro/vendas')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Partner/Vendas')
                ->has('vendas.data', 1)
                ->where('vendas.data.0.id', $order->id)
                ->where('vendas.data.0.commission_status', 'awaiting_payment')
                ->where('vendas.data.0.commission_amount', 10)
                ->where('vendas.data.0.commission_is_estimated', true)
            );
    }
}
