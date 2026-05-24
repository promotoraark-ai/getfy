<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\TeamRole;
use App\Models\User;
use App\Services\MetaCustomAudienceCsvService;
use Illuminate\Support\Str;
use Tests\TestCase;

class RelatoriosMetaExportTest extends TestCase
{
    public function test_cannot_export_product_from_other_tenant(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $productOther = $this->createTestProduct(['tenant_id' => 2, 'name' => 'Outro tenant']);

        $this->actingAs($user)
            ->get('/relatorios/export/meta-compradores?product_id='.$productOther->id)
            ->assertForbidden();
    }

    public function test_team_member_cannot_export_product_outside_role(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $productA = $this->createTestProduct(['tenant_id' => 1, 'name' => 'A']);
        $productB = $this->createTestProduct(['tenant_id' => 1, 'name' => 'B']);

        $role = TeamRole::create([
            'tenant_id' => 1,
            'name' => 'Só relatórios A',
            'permissions' => [
                'dashboard.view' => false,
                'vendas.view' => false,
                'produtos.view' => false,
                'relatorios.view' => true,
                'integracoes.view' => false,
                'email_marketing.view' => false,
                'api_pagamentos.view' => false,
                'configuracoes.view' => false,
                'equipe.manage' => false,
            ],
        ]);
        $role->products()->sync([$productA->id]);

        $team = User::factory()->create([
            'role' => User::ROLE_TEAM,
            'tenant_id' => 1,
            'team_role_id' => $role->id,
        ]);

        $this->actingAs($team)
            ->get('/relatorios/export/meta-compradores?product_id='.$productB->id)
            ->assertForbidden();
    }

    public function test_compradores_csv_contains_meta_header_and_row(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct(['tenant_id' => 1, 'name' => 'Curso X']);

        Order::create([
            'tenant_id' => 1,
            'user_id' => null,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99.5,
            'email' => 'comprador@example.com',
            'phone' => '11999990000',
            'gateway' => 'manual',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $response = $this->actingAs($user)
            ->get('/relatorios/export/meta-compradores?product_id='.$product->id);

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $body = substr($content, 3);
        $lines = preg_split("/\r\n|\n|\r/", trim($body)) ?: [];
        $this->assertGreaterThanOrEqual(2, count($lines));
        $this->assertSame(MetaCustomAudienceCsvService::HEADER_LINE, $lines[0]);
        $this->assertStringContainsString('comprador@example.com', $lines[1]);
        $this->assertStringContainsString('99.5', $lines[1]);
    }

    public function test_abandonos_excludes_email_when_completed_purchase_after_abandon(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct(['tenant_id' => 1, 'name' => 'Produto Abandono']);

        $tAbandon = now()->subHours(2);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'product_offer_id' => null,
            'subscription_plan_id' => null,
            'checkout_slug' => 'chk1',
            'session_token' => Str::random(32),
            'step' => CheckoutSession::STEP_FORM_STARTED,
            'email' => 'excluido@example.com',
            'name' => 'Lead Excluido',
            'form_started_at' => $tAbandon,
            'order_id' => null,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        Order::create([
            'tenant_id' => 1,
            'user_id' => null,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'excluido@example.com',
            'gateway' => 'manual',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'product_offer_id' => null,
            'subscription_plan_id' => null,
            'checkout_slug' => 'chk2',
            'session_token' => Str::random(32),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'mantido@example.com',
            'name' => 'Lead Mantido',
            'form_started_at' => $tAbandon,
            'form_filled_at' => $tAbandon,
            'order_id' => null,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($user)
            ->get('/relatorios/export/meta-abandonos?product_id='.$product->id);

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringNotContainsString('excluido@example.com', $content);
        $this->assertStringContainsString('mantido@example.com', $content);
    }

    public function test_missing_product_id_returns_validation_error(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->get('/relatorios/export/meta-compradores')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);
    }
}
