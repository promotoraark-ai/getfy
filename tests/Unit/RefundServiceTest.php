<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligibility_disabled_when_refund_not_enabled(): void
    {
        [$product, $user] = $this->seedProductAndUser(['enabled' => false, 'days' => 7, 'mode' => 'manual']);

        $result = app(RefundService::class)->eligibility($product, $user);

        $this->assertFalse($result['can_request']);
        $this->assertSame('disabled', $result['reason_code']);
    }

    public function test_eligibility_allows_request_within_deadline(): void
    {
        [$product, $user, $order] = $this->seedProductAndUser([
            'enabled' => true,
            'days' => 7,
            'mode' => 'manual',
        ], completed: true);

        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $result = app(RefundService::class)->eligibility($product, $user);

        $this->assertTrue($result['can_request']);
        $this->assertSame($order->id, $result['order_id']);
    }

    public function test_submit_creates_pending_request_for_manual_mode(): void
    {
        [$product, $user] = $this->seedProductAndUser([
            'enabled' => true,
            'days' => 7,
            'mode' => 'manual',
        ], completed: true);

        DB::table('product_user')->insert([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = app(RefundService::class)->submitRequest($product, $user, 'Motivo válido com mais de dez caracteres.');

        $this->assertDatabaseHas('refund_requests', [
            'id' => $request->id,
            'status' => RefundRequest::STATUS_PENDING,
            'mode' => RefundRequest::MODE_MANUAL,
        ]);
    }

    /**
     * @param  array<string, mixed>  $refundConfig
     * @return array{0: Product, 1: User, 2?: Order}
     */
    private function seedProductAndUser(array $refundConfig, bool $completed = false): array
    {
        $tenantId = 1;
        $user = User::factory()->create(['tenant_id' => $tenantId, 'role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct([
            'name' => 'Curso Teste',
            'type' => Product::TYPE_AREA_MEMBROS,
            'member_area_config' => ['refund' => $refundConfig],
        ]);

        $order = null;
        if ($completed) {
            $order = Order::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'product_id' => $product->id,
                'amount' => 100,
                'currency' => 'BRL',
                'email' => $user->email,
                'status' => 'completed',
                'gateway' => 'cajupay',
                'gateway_id' => (string) Str::uuid(),
                'metadata' => ['checkout_payment_method' => 'pix'],
            ]);
        }

        return $order ? [$product, $user, $order] : [$product, $user];
    }
}
