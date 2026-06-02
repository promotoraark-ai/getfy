<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\ProductCoproducer;
use App\Models\User;
use App\Services\CoproducerEnrollmentService;
use Tests\TestCase;

class CoproducerEnrollmentTest extends TestCase
{
    public function test_assign_team_member_as_coproducer(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $teamMember = User::factory()->create([
            'role' => User::ROLE_TEAM,
            'tenant_id' => 1,
            'team_role_id' => null,
        ]);

        $coproducer = app(CoproducerEnrollmentService::class)->assignUser($product, $teamMember, [
            'commission_percent' => 15,
            'duration_days' => null,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => true,
        ]);

        $this->assertSame(ProductCoproducer::STATUS_ACTIVE, $coproducer->status);
        $this->assertSame($teamMember->id, $coproducer->user_id);
        $this->assertSame(User::ROLE_TEAM, $teamMember->fresh()->role);
    }

    public function test_coproducer_invite_page_renders(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $invite = ProductCoproducer::create([
            'product_id' => $product->id,
            'email' => 'coprod@test.com',
            'invite_token' => 'testtoken123',
            'status' => ProductCoproducer::STATUS_PENDING,
            'commission_percent' => 20,
            'commission_on_producer_sales' => true,
            'commission_on_affiliate_sales' => true,
            'invite_expires_at' => now()->addDays(7),
        ]);

        $this->get('/convite/co-producao/'.$invite->invite_token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Convite/CoproducaoShow'));
    }
}
