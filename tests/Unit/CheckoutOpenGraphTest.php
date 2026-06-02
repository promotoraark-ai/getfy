<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Support\CheckoutOpenGraph;
use Illuminate\Http\Request;
use Tests\TestCase;

class CheckoutOpenGraphTest extends TestCase
{
    public function test_uses_product_cover_when_no_custom_og_image(): void
    {
        $product = new Product([
            'name' => 'Meu Produto',
            'description' => 'Descrição do produto',
            'image' => 'products/cover.jpg',
            'tenant_id' => 1,
        ]);

        $request = Request::create('https://loja.test/c/meu-produto', 'GET');

        $meta = CheckoutOpenGraph::forProduct($product, [], $request);

        $this->assertSame('Meu Produto', $meta['title']);
        $this->assertStringContainsString('Descrição do produto', $meta['description']);
        $this->assertSame('https://loja.test/storage/products/cover.jpg', $meta['image']);
    }

    public function test_absolute_url_helper(): void
    {
        $request = Request::create('https://example.com/c/slug', 'GET');

        $this->assertSame(
            'https://cdn.example/img.jpg',
            CheckoutOpenGraph::absoluteUrl('https://cdn.example/img.jpg', $request)
        );
        $this->assertSame(
            'https://example.com/storage/x.png',
            CheckoutOpenGraph::absoluteUrl('/storage/x.png', $request)
        );
    }
}
