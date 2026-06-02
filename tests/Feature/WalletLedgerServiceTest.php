<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\User;
use App\Services\WalletLedgerService;
use Tests\TestCase;

class WalletLedgerServiceTest extends TestCase
{
    public function test_balances_grouped_by_wallet_bucket(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $this->createTestProduct()->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'b@test.com',
        ]);

        CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $user->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 100,
            'gateway_fee_amount' => 1,
            'net_amount' => 99,
            'commission_percent' => 10,
            'commission_amount' => 10,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_AVAILABLE,
            'payment_method' => 'pix',
            'available_at' => now(),
        ]);

        CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $user->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 100,
            'gateway_fee_amount' => 1,
            'net_amount' => 99,
            'commission_percent' => 20,
            'commission_amount' => 20,
            'amount_paid' => 0,
            'status' => CommissionEntry::STATUS_PENDING,
            'payment_method' => 'card',
            'available_at' => now()->addDays(30),
        ]);

        $balances = app(WalletLedgerService::class)->balancesForUser($user, [
            CommissionEntry::ROLE_AFILIADO,
        ]);

        $this->assertEquals(10.0, $balances['by_wallet']['pix']['available']);
        $this->assertEquals(20.0, $balances['by_wallet']['card']['pending']);
        $this->assertEquals(10.0, $balances['totals']['available']);
        $this->assertEquals(20.0, $balances['totals']['pending']);
    }

    public function test_partial_paid_entry_reduces_available(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_AFILIADO,
            'tenant_id' => 1,
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $this->createTestProduct()->id,
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => 'b2@test.com',
        ]);

        CommissionEntry::create([
            'order_id' => $order->id,
            'tenant_id' => 1,
            'beneficiary_user_id' => $user->id,
            'role' => CommissionEntry::ROLE_AFILIADO,
            'gross_amount' => 50,
            'gateway_fee_amount' => 0,
            'net_amount' => 50,
            'commission_percent' => 100,
            'commission_amount' => 15,
            'amount_paid' => 5,
            'status' => CommissionEntry::STATUS_AVAILABLE,
            'payment_method' => 'pix',
            'available_at' => now(),
        ]);

        $available = app(WalletLedgerService::class)->availableForBucket(
            $user,
            'pix',
            [CommissionEntry::ROLE_AFILIADO]
        );

        $this->assertEquals(10.0, $available);
    }
}
