<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\User;
use Tests\TestCase;

class CheckoutGeoSuggestionsTest extends TestCase
{
    public function test_cf_ip_country_header_sets_suggestions_on_checkout_page(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Geo test product',
            'price' => 10,
        ]);

        $slug = $product->checkout_slug;
        $this->assertNotEmpty($slug);

        $response = $this->withHeader('CF-IPCountry', 'US')
            ->get('/c/'.$slug);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('suggested_country_code', 'US')
            ->where('suggested_locale', 'en')
            ->where('suggested_currency', 'USD'));
    }

    public function test_vercel_country_header_sets_suggestions(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Geo Vercel',
            'price' => 10,
        ]);

        $response = $this->withHeader('X-Vercel-IP-Country', 'ES')
            ->get('/c/'.$product->checkout_slug);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('suggested_country_code', 'ES')
            ->where('suggested_locale', 'es')
            ->where('suggested_currency', 'EUR'));
    }

    public function test_checkout_force_overrides_geo_suggestions(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Force locale product',
            'price' => 10,
            'checkout_config' => [
                'checkout_force' => [
                    'enabled' => true,
                    'locale' => 'pt_BR',
                    'currency' => 'BRL',
                ],
            ],
        ]);

        $response = $this->withHeader('CF-IPCountry', 'US')
            ->get('/c/'.$product->checkout_slug);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('suggested_country_code', 'US')
            ->where('suggested_locale', 'pt_BR')
            ->where('suggested_currency', 'BRL'));
    }
}
