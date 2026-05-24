<?php

namespace Tests;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Route;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerWebhookEntradaPublicRoutesIfMissing();
    }

    /**
     * Quando a tabela plugins existe e o plugin não está enabled no DB, o PluginServiceProvider não carrega
     * routes-public; re-registra aqui para testes (evita duplicar se o fallback já carregou o plugin).
     */
    protected function registerWebhookEntradaPublicRoutesIfMissing(): void
    {
        if (Route::has('webhook-entrada.inbound.post')) {
            return;
        }
        $path = base_path('plugins/webhook-entrada/routes-public.php');
        if (! is_file($path)) {
            return;
        }
        Route::middleware(['web', 'throttle:120,1'])
            ->prefix('webhooks/inbound')
            ->group($path);
    }

    /**
     * Em SQLite (phpunit) a coluna products.id continua inteira; o boot do model usaria UUID (migração só no MySQL).
     */
    protected function createTestProduct(array $overrides = []): Product
    {
        $nextId = (int) (Product::query()->max('id') ?? 0) + 1;

        $product = new Product;
        $product->forceFill(array_merge([
            'id' => (string) $nextId,
            'tenant_id' => 1,
            'name' => 'Test product',
            'slug' => 't-'.uniqid('', true),
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 10,
            'currency' => 'BRL',
            'is_active' => true,
        ], $overrides));
        $product->save();

        return $product->fresh();
    }
}
