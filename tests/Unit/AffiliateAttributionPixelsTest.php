<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureInstalled;
use App\Models\ProductAffiliate;
use App\Models\User;
use App\Support\AffiliateAttribution;
use Tests\TestCase;

class AffiliateAttributionPixelsTest extends TestCase
{
    public function test_uses_affiliate_pixels_when_ref_valid_and_configured(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => ['enabled' => true, 'entries' => [['id' => 'p', 'pixel_id' => 'prod', 'access_token' => '']]],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [],
            ],
        ]);

        $user = User::factory()->create(['role' => User::ROLE_AFILIADO, 'tenant_id' => $product->tenant_id]);

        ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'affiliate_code' => 'myref123',
            'status' => ProductAffiliate::STATUS_APPROVED,
            'affiliate_pixels' => [
                'meta' => ['enabled' => true, 'entries' => [['id' => 'a', 'pixel_id' => 'aff', 'access_token' => 'tok']]],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [],
            ],
        ]);

        $result = AffiliateAttribution::conversionPixelsForCheckout($product->fresh(), 'myref123');

        $this->assertSame('aff', $result['meta']['entries'][0]['pixel_id']);
    }

    public function test_falls_back_to_product_pixels_without_ref(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct();

        $result = AffiliateAttribution::conversionPixelsForCheckout($product->fresh(), null);

        $this->assertArrayHasKey('meta', $result);
    }
}
