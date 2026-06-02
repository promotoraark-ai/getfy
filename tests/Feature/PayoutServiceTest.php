<?php

namespace Tests\Feature;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Http\Middleware\EnsureInstalled;
use App\Models\CommissionEntry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Services\PartnerPayoutApprovalService;
use App\Services\PayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayoutServiceTest extends TestCase
{
    public function test_successful_payout_marks_entries_paid(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
            'pix_key' => 'partner@test.com',
            'pix_key_type' => 'email',
            'pix_owner_document' => '12345678901',
        ]);

        $this->seedCajupayCredential(1);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $this->createTestProduct()->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
        ]);

        CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $partner->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 100,
            'gateway_fee_amount' => 0,
            'net_amount' => 100,
            'commission_percent' => 25,
            'commission_amount' => 25,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_AVAILABLE,
            'payment_method' => 'pix',
            'available_at' => now(),
        ]);

        $mock = $this->mockCajuPayDriver();
        $mock->shouldReceive('createPayout')->once()->andReturn(['id' => 'payout-test-1', 'status' => 'paid']);

        $service = app(PayoutService::class);
        $httpRequest = Request::create('/parceiro/financeiro/payout', 'POST');

        $result = $service->requestPayout(
            $partner,
            'pix',
            25.0,
            false,
            $httpRequest,
            [CommissionEntry::ROLE_AFILIADO],
            'test-idem-1',
        );

        $this->assertFalse($result['replayed'] ?? false);
        $this->assertEquals(PayoutRequest::STATUS_COMPLETED, $result['payout_request']->status);

        $this->assertDatabaseHas('commission_entries', [
            'beneficiary_user_id' => $partner->id,
            'status' => CommissionEntry::STATUS_PAID,
            'commission_amount' => 25,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $partner->id,
            'type' => 'debit',
            'source' => 'payout',
        ]);
    }

    public function test_payout_rejects_amount_above_available(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
            'pix_key' => 'a@test.com',
            'pix_key_type' => 'email',
            'pix_owner_document' => '12345678901',
        ]);

        $this->seedCajupayCredential(1);

        $mock = $this->mockCajuPayDriver();
        $mock->shouldNotReceive('createPayout');

        $this->expectException(ValidationException::class);

        app(PayoutService::class)->requestPayout(
            $partner,
            'pix',
            999.0,
            false,
            Request::create('/', 'POST'),
            [CommissionEntry::ROLE_AFILIADO],
        );
    }

    public function test_partial_payout_updates_amount_paid(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
            'pix_key' => 'partial@test.com',
            'pix_key_type' => 'email',
            'pix_owner_document' => '12345678901',
        ]);

        $this->seedCajupayCredential(1);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $this->createTestProduct()->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer2@test.com',
        ]);

        CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $partner->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 100,
            'gateway_fee_amount' => 0,
            'net_amount' => 100,
            'commission_percent' => 50,
            'commission_amount' => 50,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_AVAILABLE,
            'payment_method' => 'pix',
            'available_at' => now(),
        ]);

        $mock = $this->mockCajuPayDriver();
        $mock->shouldReceive('createPayout')->once()->andReturn(['id' => 'payout-partial', 'status' => 'paid']);

        app(PayoutService::class)->requestPayout(
            $partner,
            'pix',
            20.0,
            false,
            Request::create('/', 'POST'),
            [CommissionEntry::ROLE_AFILIADO],
            'test-idem-partial',
        );

        $entry = CommissionEntry::query()->where('beneficiary_user_id', $partner->id)->first();
        $this->assertEquals(20.0, (float) $entry->amount_paid);
        $this->assertEquals(CommissionEntry::STATUS_AVAILABLE, $entry->status);
        $this->assertEquals(30.0, $entry->remainingAmount());
    }

    public function test_idempotency_replay_returns_existing_completed(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
            'pix_key' => 'idem@test.com',
            'pix_key_type' => 'email',
            'pix_owner_document' => '12345678901',
        ]);

        PayoutRequest::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'replay-key-1',
            'user_id' => $partner->id,
            'tenant_id' => 1,
            'wallet_bucket' => 'pix',
            'amount_cents' => 1000,
            'status' => PayoutRequest::STATUS_COMPLETED,
            'pix_key' => 'idem@test.com',
            'pix_key_type' => 'email',
            'cajupay_response' => ['id' => 'existing'],
            'requested_at' => now(),
            'completed_at' => now(),
        ]);

        $mock = $this->mock(CajuPayDriver::class);
        $mock->shouldNotReceive('createPayout');

        $result = app(PayoutService::class)->requestPayout(
            $partner,
            'pix',
            10.0,
            false,
            Request::create('/', 'POST'),
            [CommissionEntry::ROLE_AFILIADO],
            'replay-key-1',
        );

        $this->assertTrue($result['replayed']);
    }

    public function test_cajupay_pending_response_leaves_awaiting_payout(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
            'pix_key' => 'pending@test.com',
            'pix_key_type' => 'email',
            'pix_owner_document' => '12345678901',
        ]);

        $this->seedCajupayCredential(1);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $this->createTestProduct()->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer-pending@test.com',
        ]);

        CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $partner->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 100,
            'gateway_fee_amount' => 0,
            'net_amount' => 100,
            'commission_percent' => 10,
            'commission_amount' => 10,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_AVAILABLE,
            'payment_method' => 'pix',
            'available_at' => now(),
        ]);

        $mock = $this->mockCajuPayDriver();
        $mock->shouldReceive('createPayout')->once()->andReturn([
            'id' => 'payout-pending-1',
            'status' => 'processing',
        ]);

        $result = app(PayoutService::class)->requestPayout(
            $partner,
            'pix',
            10.0,
            false,
            Request::create('/', 'POST'),
            [CommissionEntry::ROLE_AFILIADO],
            'test-idem-pending',
        );

        $this->assertEquals(PayoutRequest::STATUS_AWAITING_PAYOUT, $result['payout_request']->status);
        $this->assertDatabaseHas('commission_entries', [
            'beneficiary_user_id' => $partner->id,
            'status' => CommissionEntry::STATUS_RESERVED,
        ]);
        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $partner->id,
            'source' => 'payout',
        ]);
    }

    public function test_reconcile_command_completes_awaiting_payout(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
            'pix_key' => 'reconcile@test.com',
            'pix_key_type' => 'email',
            'pix_owner_document' => '12345678901',
        ]);

        $this->seedCajupayCredential(1);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $this->createTestProduct()->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer-reconcile@test.com',
        ]);

        CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $partner->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 100,
            'gateway_fee_amount' => 0,
            'net_amount' => 100,
            'commission_percent' => 15,
            'commission_amount' => 15,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_AVAILABLE,
            'payment_method' => 'pix',
            'available_at' => now(),
        ]);

        $mock = $this->mockCajuPayDriver();
        $mock->shouldReceive('createPayout')->once()->andReturn([
            'id' => 'payout-reconcile-1',
            'status' => 'processing',
        ]);
        $mock->shouldReceive('getPayout')->once()->andReturn([
            'id' => 'payout-reconcile-1',
            'status' => 'paid',
        ]);

        $result = app(PayoutService::class)->requestPayout(
            $partner,
            'pix',
            15.0,
            false,
            Request::create('/', 'POST'),
            [CommissionEntry::ROLE_AFILIADO],
            'test-idem-reconcile',
        );

        $payout = $result['payout_request'];
        $this->assertEquals(PayoutRequest::STATUS_AWAITING_PAYOUT, $payout->status);

        Artisan::call('payouts:reconcile');

        $payout->refresh();
        $this->assertEquals(PayoutRequest::STATUS_COMPLETED, $payout->status);
        $this->assertDatabaseHas('commission_entries', [
            'beneficiary_user_id' => $partner->id,
            'status' => CommissionEntry::STATUS_PAID,
        ]);
    }

    public function test_partner_card_payout_requires_approval_without_cajupay(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
            'pix_key' => 'card@test.com',
            'pix_key_type' => 'email',
            'pix_owner_document' => '12345678901',
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'product_id' => $this->createTestProduct()->id,
            'status' => 'completed',
            'amount' => 200,
            'currency' => 'BRL',
            'email' => 'buyer-card@test.com',
        ]);

        CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $partner->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 200,
            'gateway_fee_amount' => 0,
            'net_amount' => 200,
            'commission_percent' => 20,
            'commission_amount' => 40,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_AVAILABLE,
            'payment_method' => 'card',
            'available_at' => now(),
        ]);

        $mock = $this->mock(CajuPayDriver::class);
        $mock->shouldNotReceive('createPayout');
        $mock->shouldNotReceive('getWalletBalance');

        $result = app(PayoutService::class)->requestPayout(
            $partner,
            'card',
            40.0,
            false,
            Request::create('/', 'POST'),
            [CommissionEntry::ROLE_AFILIADO],
            'test-idem-card',
        );

        $this->assertEquals(PayoutRequest::STATUS_PENDING_APPROVAL, $result['payout_request']->status);
        $this->assertStringContainsString('aprovação', (string) ($result['message'] ?? ''));
        $this->assertDatabaseHas('commission_entries', [
            'beneficiary_user_id' => $partner->id,
            'status' => CommissionEntry::STATUS_RESERVED,
        ]);
    }

    public function test_producer_approves_partner_card_payout(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $producer = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
            'pix_key' => 'approve@test.com',
            'pix_key_type' => 'email',
            'pix_owner_document' => '12345678901',
        ]);

        $this->seedCajupayCredential(1);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $producer->id,
            'product_id' => $this->createTestProduct()->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer-approve@test.com',
        ]);

        CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $partner->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 100,
            'gateway_fee_amount' => 0,
            'net_amount' => 100,
            'commission_percent' => 30,
            'commission_amount' => 30,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_AVAILABLE,
            'payment_method' => 'card',
            'available_at' => now(),
        ]);

        $mock = $this->mock(CajuPayDriver::class);
        $mock->shouldNotReceive('getWalletBalance');
        $mock->shouldNotReceive('createPayout');

        $pending = app(PayoutService::class)->requestPayout(
            $partner,
            'card',
            30.0,
            false,
            Request::create('/', 'POST'),
            [CommissionEntry::ROLE_AFILIADO],
            'test-idem-approve',
        );

        $payout = $pending['payout_request'];
        $this->assertEquals(PayoutRequest::STATUS_PENDING_APPROVAL, $payout->status);

        $mock = $this->mockCajuPayDriver();
        $mock->shouldReceive('createPayout')->once()->andReturn(['id' => 'payout-approved', 'status' => 'paid']);

        $result = app(PartnerPayoutApprovalService::class)->approve($producer, $payout);

        $this->assertEquals(PayoutRequest::STATUS_COMPLETED, $result['payout_request']->status);
        $this->assertEquals($producer->id, $result['payout_request']->approved_by_user_id);
    }

    public function test_producer_rejects_partner_payout_releases_reservation(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $producer = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $partner = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
            'pix_key' => 'reject@test.com',
            'pix_key_type' => 'email',
            'pix_owner_document' => '12345678901',
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $producer->id,
            'product_id' => $this->createTestProduct()->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'buyer-reject@test.com',
        ]);

        $entry = CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $partner->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 100,
            'gateway_fee_amount' => 0,
            'net_amount' => 100,
            'commission_percent' => 20,
            'commission_amount' => 20,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_AVAILABLE,
            'payment_method' => 'boleto',
            'available_at' => now(),
        ]);

        $mock = $this->mock(CajuPayDriver::class);
        $mock->shouldNotReceive('createPayout');

        $pending = app(PayoutService::class)->requestPayout(
            $partner,
            'boleto',
            20.0,
            false,
            Request::create('/', 'POST'),
            [CommissionEntry::ROLE_AFILIADO],
            'test-idem-reject',
        );

        $payout = $pending['payout_request'];

        $rejected = app(PartnerPayoutApprovalService::class)->reject($producer, $payout, 'Sem saldo');

        $this->assertEquals(PayoutRequest::STATUS_CANCELLED, $rejected->status);
        $entry->refresh();
        $this->assertEquals(CommissionEntry::STATUS_AVAILABLE, $entry->status);
        $this->assertNull($entry->payout_request_id);
    }

    private function seedCajupayCredential(int $tenantId): void
    {
        GatewayCredential::create([
            'tenant_id' => $tenantId,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
            'credentials' => Crypt::encryptString(json_encode([
                'public_key' => 'pk_test',
                'secret_key' => 'sk_test',
            ])),
        ]);
    }

    /**
     * Partial mock keeps real extractPayoutId / normalizePayoutStatus behavior.
     */
    private function mockCajuPayDriver(): \Mockery\MockInterface
    {
        $mock = $this->mock(CajuPayDriver::class)->makePartial();
        $mock->shouldReceive('getWalletBalance')->andReturn(['available_cents' => 100000]);

        return $mock;
    }
}
