<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\TeamRole;
use App\Models\User;
use App\Services\PartnerPayoutApprovalService;
use App\Services\PayoutService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceiroSecurityTest extends TestCase
{
    public function test_execute_cajupay_payout_rejects_pending_approval_status(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $payout = PayoutRequest::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'sec-pending-approval',
            'user_id' => User::factory()->create(['tenant_id' => 1])->id,
            'tenant_id' => 1,
            'wallet_bucket' => 'card',
            'amount_cents' => 1000,
            'status' => PayoutRequest::STATUS_PENDING_APPROVAL,
            'pix_key' => 'test@test.com',
            'pix_key_type' => 'email',
            'requested_at' => now(),
        ]);

        $mock = $this->mock(\App\Gateways\CajuPay\CajuPayDriver::class);
        $mock->shouldNotReceive('createPayout');

        $this->expectException(ValidationException::class);

        app(PayoutService::class)->executeCajupayPayout($payout);
    }

    public function test_cross_tenant_approve_returns_404(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $producer = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create(['role' => User::ROLE_AFILIADO, 'tenant_id' => 2]);

        $payout = PayoutRequest::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'sec-cross-tenant',
            'user_id' => $partner->id,
            'tenant_id' => 2,
            'wallet_bucket' => 'card',
            'amount_cents' => 2000,
            'status' => PayoutRequest::STATUS_PENDING_APPROVAL,
            'pix_key' => 'other@test.com',
            'pix_key_type' => 'email',
            'requested_at' => now(),
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        try {
            app(PartnerPayoutApprovalService::class)->approve($producer, $payout);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(404, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_team_with_view_only_cannot_approve_via_http(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $role = TeamRole::create([
            'tenant_id' => 1,
            'name' => 'Financeiro leitura',
            'permissions' => ['financeiro.view' => true],
        ]);
        $teamUser = User::factory()->create([
            'role' => User::ROLE_TEAM,
            'tenant_id' => 1,
            'team_role_id' => $role->id,
        ]);

        $partner = User::factory()->create(['role' => User::ROLE_AFILIADO, 'tenant_id' => 1]);
        $payout = PayoutRequest::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'sec-team-view',
            'user_id' => $partner->id,
            'tenant_id' => 1,
            'wallet_bucket' => 'card',
            'amount_cents' => 1500,
            'status' => PayoutRequest::STATUS_PENDING_APPROVAL,
            'pix_key' => 'team@test.com',
            'pix_key_type' => 'email',
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($teamUser)->postJson(
            route('financeiro.partner-payouts.approve', ['payout' => $payout->id]),
        );

        $response->assertForbidden();
    }

    public function test_approve_rejects_producer_own_payout(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $producer = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
            'pix_key' => 'prod@test.com',
            'pix_key_type' => 'email',
        ]);

        $payout = PayoutRequest::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'sec-producer-payout',
            'user_id' => $producer->id,
            'tenant_id' => 1,
            'wallet_bucket' => 'card',
            'amount_cents' => 5000,
            'status' => PayoutRequest::STATUS_PENDING_APPROVAL,
            'pix_key' => 'prod@test.com',
            'pix_key_type' => 'email',
            'requested_at' => now(),
        ]);

        CommissionEntry::create([
            'order_id' => Order::create([
                'tenant_id' => 1,
                'user_id' => $producer->id,
                'product_id' => $this->createTestProduct()->id,
                'status' => 'completed',
                'amount' => 100,
                'currency' => 'BRL',
                'email' => 'buyer@test.com',
            ])->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $producer->id,
            'role' => CommissionEntry::ROLE_PRODUTOR,
            'gross_amount' => 100,
            'gateway_fee_amount' => 0,
            'net_amount' => 100,
            'commission_percent' => 50,
            'commission_amount' => 50,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_RESERVED,
            'payment_method' => 'card',
            'payout_request_id' => $payout->id,
            'available_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(PartnerPayoutApprovalService::class)->approve($producer, $payout);
    }

    public function test_scoped_route_binding_blocks_other_tenant_payout(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $producer = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create(['role' => User::ROLE_AFILIADO, 'tenant_id' => 2]);

        $payout = PayoutRequest::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'sec-route-bind',
            'user_id' => $partner->id,
            'tenant_id' => 2,
            'wallet_bucket' => 'card',
            'amount_cents' => 1000,
            'status' => PayoutRequest::STATUS_PENDING_APPROVAL,
            'pix_key' => 'bind@test.com',
            'pix_key_type' => 'email',
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($producer)->postJson(
            route('financeiro.partner-payouts.reject', ['payout' => $payout->id]),
            ['reason' => 'test'],
        );

        $response->assertNotFound();
    }
}
