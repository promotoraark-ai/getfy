<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberAreaOtherProductsLinkDeliverableTest extends TestCase
{
    public function test_other_products_link_deliverable_redirects_instead_of_404(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $hostSlug = 'host'.substr(md5(uniqid('host', true)), 0, 8);

        $hostProduct = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hostSlug,
            'name' => 'Hub',
        ]);

        $linkProduct = $this->createTestProduct([
            'type' => Product::TYPE_LINK,
            'name' => 'Entrega por link',
            'checkout_config' => array_replace_recursive(Product::defaultCheckoutConfig(), [
                'deliverable_link' => 'https://example.com/deliverable',
            ]),
        ]);

        $productsSection = MemberSection::create([
            'product_id' => $hostProduct->id,
            'title' => 'Outros produtos',
            'position' => 1,
            'section_type' => 'products',
        ]);

        MemberModule::create([
            'member_section_id' => $productsSection->id,
            'product_id' => $hostProduct->id,
            'title' => 'Abrir entrega',
            'position' => 1,
            'related_product_id' => $linkProduct->id,
            'access_type' => 'paid',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);

        $hostProduct->users()->attach($aluno->id);
        $linkProduct->users()->attach($aluno->id);

        $this->assertNotNull(\App\Models\Product::find((int) $linkProduct->id));

        $show = $this->actingAs($aluno)->get('/m/'.$hostSlug);
        $show->assertStatus(200);

        $url = route('member-area-app.products.deliverable', [
            'slug' => $hostSlug,
            'relatedProduct' => $linkProduct->id,
        ]);
        $this->assertStringContainsString('/m/'.$hostSlug.'/products/'.$linkProduct->id.'/deliverable', $url);

        $res = $this->actingAs($aluno)->get($url);
        $res->assertRedirect('https://example.com/deliverable');
    }
}
