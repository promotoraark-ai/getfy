<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Controllers\ProdutosController;
use App\Models\ConversionPixelIntegration;
use App\Models\Product;
use App\Services\ConversionPixelsResolver;
use App\Services\LegacyConversionPixelsMigrator;
use App\Support\AffiliateAttribution;
use Tests\TestCase;

class ConversionPixelsResolverTest extends TestCase
{
    public function test_legacy_inline_entries_unchanged(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [[
                        'id' => 'legacy-1',
                        'pixel_id' => '111222',
                        'access_token' => 'secret-token',
                        'fire_purchase_on_pix' => true,
                        'fire_purchase_on_boleto' => false,
                        'disable_order_bump_events' => true,
                    ]],
                ],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [],
            ],
        ]);

        $resolved = app(ConversionPixelsResolver::class)->resolve($product->fresh());

        $this->assertSame('111222', $resolved['meta']['entries'][0]['pixel_id']);
        $this->assertSame('secret-token', $resolved['meta']['entries'][0]['access_token']);
        $this->assertTrue($resolved['meta']['entries'][0]['disable_order_bump_events']);
    }

    public function test_resolves_entries_from_integration_ids_with_block_flags(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct();

        $integration = ConversionPixelIntegration::create([
            'tenant_id' => $product->tenant_id,
            'platform' => ConversionPixelIntegration::PLATFORM_META,
            'name' => 'Meta central',
            'config' => ['pixel_id' => '999888'],
            'access_token' => 'central-token',
            'is_active' => true,
        ]);

        $integration->update([
            'config' => [
                'pixel_id' => '999888',
                'fire_purchase_on_pix' => false,
                'fire_purchase_on_boleto' => true,
                'disable_order_bump_events' => false,
            ],
        ]);
        $integration->products()->sync([(string) $product->id]);

        $resolved = app(ConversionPixelsResolver::class)->resolve($product->fresh());

        $this->assertCount(1, $resolved['meta']['entries']);
        $this->assertSame('999888', $resolved['meta']['entries'][0]['pixel_id']);
        $this->assertSame('central-token', $resolved['meta']['entries'][0]['access_token']);
        $this->assertFalse($resolved['meta']['entries'][0]['fire_purchase_on_pix']);
        $this->assertTrue($resolved['meta']['entries'][0]['fire_purchase_on_boleto']);
    }

    public function test_order_resolved_pixels_use_integrations_for_meta_capi(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct();
        $integration = ConversionPixelIntegration::create([
            'tenant_id' => $product->tenant_id,
            'platform' => ConversionPixelIntegration::PLATFORM_META,
            'name' => 'Meta CAPI',
            'config' => ['pixel_id' => 'capipixel'],
            'access_token' => 'capi-token',
            'is_active' => true,
        ]);

        $integration->products()->sync([(string) $product->id]);

        $user = \App\Models\User::factory()->create(['tenant_id' => $product->tenant_id]);
        $order = \App\Models\Order::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'email' => 'buyer@test.com',
        ]);

        $pixels = $order->resolvedConversionPixels();

        $this->assertTrue($pixels['meta']['enabled']);
        $this->assertSame('capipixel', $pixels['meta']['entries'][0]['pixel_id']);
        $this->assertSame('capi-token', $pixels['meta']['entries'][0]['access_token']);
    }

    public function test_legacy_root_format_meta_resolves_for_checkout(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'pixel_id' => 'root-pixel-99',
                    'access_token' => 'root-token',
                    'fire_purchase_on_pix' => false,
                ],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [],
            ],
        ]);

        $checkout = AffiliateAttribution::conversionPixelsForCheckout($product->fresh(), null);

        $this->assertSame('root-pixel-99', $checkout['meta']['entries'][0]['pixel_id']);
        $this->assertSame('root-token', $checkout['meta']['entries'][0]['access_token']);
        $this->assertFalse($checkout['meta']['entries'][0]['fire_purchase_on_pix']);
    }

    public function test_legacy_inline_custom_scripts_preserved(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => ['enabled' => false, 'entries' => []],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [
                    ['id' => 's1', 'name' => 'GTM', 'script' => '<script>gtm();</script>'],
                ],
            ],
        ]);

        $resolved = app(ConversionPixelsResolver::class)->resolve($product->fresh());

        $this->assertCount(1, $resolved['custom_script']);
        $this->assertStringContainsString('gtm()', $resolved['custom_script'][0]['script']);
    }

    public function test_save_merge_preserves_legacy_inline_entries(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [[
                        'id' => 'keep-me',
                        'pixel_id' => '555666',
                        'access_token' => 'do-not-lose',
                    ]],
                ],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [],
            ],
        ]);

        $controller = app(ProdutosController::class);
        $method = new \ReflectionMethod($controller, 'mergeConversionPixelsForSave');
        $method->setAccessible(true);

        $merged = $method->invoke($controller, [
            'meta' => [
                'enabled' => true,
                'integration_ids' => [],
                'entries' => [[
                    'id' => 'keep-me',
                    'pixel_id' => '555666',
                    'access_token' => 'do-not-lose',
                ]],
            ],
            'tiktok' => ['enabled' => false, 'entries' => []],
            'google_ads' => ['enabled' => false, 'entries' => []],
            'google_analytics' => ['enabled' => false, 'entries' => []],
            'custom_script' => [],
        ], $product->tenant_id);

        $product->update(['conversion_pixels' => $merged]);
        $resolved = app(ConversionPixelsResolver::class)->resolve($product->fresh());

        $this->assertSame('555666', $resolved['meta']['entries'][0]['pixel_id']);
        $this->assertSame('do-not-lose', $resolved['meta']['entries'][0]['access_token']);
    }

    public function test_order_resolved_pixels_legacy_inline_for_meta_capi(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [[
                        'id' => 'capi-legacy',
                        'pixel_id' => 'legacy-capi-id',
                        'access_token' => 'legacy-capi-secret',
                    ]],
                ],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [],
            ],
        ]);

        $user = \App\Models\User::factory()->create(['tenant_id' => $product->tenant_id]);
        $order = \App\Models\Order::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'legacy@test.com',
        ]);

        $pixels = $order->resolvedConversionPixels();

        $this->assertTrue($pixels['meta']['enabled']);
        $this->assertSame('legacy-capi-id', $pixels['meta']['entries'][0]['pixel_id']);
        $this->assertSame('legacy-capi-secret', $pixels['meta']['entries'][0]['access_token']);
    }

    public function test_integration_without_products_does_not_apply_to_product(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct();
        ConversionPixelIntegration::create([
            'tenant_id' => $product->tenant_id,
            'platform' => ConversionPixelIntegration::PLATFORM_META,
            'name' => 'Meta sem produtos',
            'config' => ['pixel_id' => 'orphan-pixel'],
            'access_token' => 'tok',
            'is_active' => true,
        ]);

        $resolved = app(ConversionPixelsResolver::class)->resolve($product->fresh());

        $this->assertFalse($resolved['meta']['enabled'] ?? false);
        $this->assertSame([], $resolved['meta']['entries'] ?? []);
    }

    public function test_product_pivot_linked_integration_resolves_with_behavior_flags(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct();
        $integration = ConversionPixelIntegration::create([
            'tenant_id' => $product->tenant_id,
            'platform' => ConversionPixelIntegration::PLATFORM_META,
            'name' => 'Meta pivot',
            'config' => [
                'pixel_id' => 'pivot-pixel',
                'fire_purchase_on_pix' => false,
                'fire_purchase_on_boleto' => true,
                'disable_order_bump_events' => true,
            ],
            'access_token' => 'pivot-token',
            'is_active' => true,
        ]);
        $integration->products()->sync([(string) $product->id]);

        $resolved = app(ConversionPixelsResolver::class)->resolve($product->fresh());

        $this->assertTrue($resolved['meta']['enabled']);
        $this->assertSame('pivot-pixel', $resolved['meta']['entries'][0]['pixel_id']);
        $this->assertFalse($resolved['meta']['entries'][0]['fire_purchase_on_pix']);
        $this->assertTrue($resolved['meta']['entries'][0]['fire_purchase_on_boleto']);
        $this->assertTrue($resolved['meta']['entries'][0]['disable_order_bump_events']);
    }

    public function test_legacy_inline_migrates_to_integration_and_pivot(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [[
                        'id' => 'm1',
                        'pixel_id' => 'migrate-me',
                        'access_token' => 'migrate-token',
                        'fire_purchase_on_pix' => false,
                    ]],
                ],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [],
            ],
        ]);

        $count = app(LegacyConversionPixelsMigrator::class)->migrateTenant($product->tenant_id);
        $this->assertGreaterThan(0, $count);

        $integration = ConversionPixelIntegration::query()
            ->forTenant($product->tenant_id)
            ->where('platform', ConversionPixelIntegration::PLATFORM_META)
            ->get()
            ->first(fn (ConversionPixelIntegration $i) => ($i->config['pixel_id'] ?? '') === 'migrate-me');

        $this->assertNotNull($integration);
        $this->assertTrue($integration->products()->where('products.id', $product->id)->exists());

        $resolved = app(ConversionPixelsResolver::class)->resolve($product->fresh());
        $this->assertSame('migrate-me', $resolved['meta']['entries'][0]['pixel_id']);
        $this->assertSame('migrate-token', $resolved['meta']['entries'][0]['access_token']);
        $this->assertFalse($resolved['meta']['entries'][0]['fire_purchase_on_pix']);
    }

    public function test_checkout_resolves_pivot_linked_integration(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct();
        $integration = ConversionPixelIntegration::create([
            'tenant_id' => $product->tenant_id,
            'platform' => ConversionPixelIntegration::PLATFORM_META,
            'name' => 'Meta checkout pivot',
            'config' => ['pixel_id' => 'checkout-pivot-pixel'],
            'access_token' => 'checkout-pivot-token',
            'is_active' => true,
        ]);
        $integration->products()->sync([(string) $product->id]);

        $checkout = \App\Support\AffiliateAttribution::conversionPixelsForCheckout($product->fresh(), null);

        $this->assertTrue($checkout['meta']['enabled']);
        $this->assertSame('checkout-pivot-pixel', $checkout['meta']['entries'][0]['pixel_id']);
        $this->assertSame('checkout-pivot-token', $checkout['meta']['entries'][0]['access_token']);
    }

    public function test_checkout_resolves_post_migration_product_with_empty_inline_entries(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [],
                    'integration_ids' => [],
                ],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [],
            ],
        ]);

        $integration = ConversionPixelIntegration::create([
            'tenant_id' => $product->tenant_id,
            'platform' => ConversionPixelIntegration::PLATFORM_META,
            'name' => 'Meta migrado',
            'config' => ['pixel_id' => 'migrated-pixel-id'],
            'access_token' => 'migrated-token',
            'is_active' => true,
        ]);
        $integration->products()->sync([(string) $product->id]);

        $checkout = \App\Support\AffiliateAttribution::conversionPixelsForCheckout($product->fresh(), null);

        $this->assertTrue($checkout['meta']['enabled']);
        $this->assertCount(1, $checkout['meta']['entries']);
        $this->assertSame('migrated-pixel-id', $checkout['meta']['entries'][0]['pixel_id']);
    }

    public function test_checkout_resolves_integration_ids_on_product(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [],
                    'integration_ids' => [],
                ],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [],
            ],
        ]);

        $integration = ConversionPixelIntegration::create([
            'tenant_id' => $product->tenant_id,
            'platform' => ConversionPixelIntegration::PLATFORM_META,
            'name' => 'Meta via integration_ids',
            'config' => ['pixel_id' => 'ids-pixel'],
            'access_token' => 'ids-token',
            'is_active' => true,
        ]);

        $product->update([
            'conversion_pixels' => array_merge(
                app(ConversionPixelsResolver::class)->storedConversionPixels($product->fresh()),
                [
                    'meta' => [
                        'enabled' => true,
                        'entries' => [],
                        'integration_ids' => [$integration->id],
                    ],
                ]
            ),
        ]);

        $checkout = \App\Support\AffiliateAttribution::conversionPixelsForCheckout($product->fresh(), null);

        $this->assertSame('ids-pixel', $checkout['meta']['entries'][0]['pixel_id']);
    }

    public function test_checkout_resolves_pivot_linked_google_ads_integration(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => ['enabled' => false, 'entries' => []],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => true, 'entries' => [], 'integration_ids' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [],
            ],
        ]);

        $integration = ConversionPixelIntegration::create([
            'tenant_id' => $product->tenant_id,
            'platform' => ConversionPixelIntegration::PLATFORM_GOOGLE_ADS,
            'name' => 'Google Ads checkout',
            'config' => [
                'conversion_id' => 'AW-123456789',
                'conversion_label' => 'AbCdEfGh',
            ],
            'is_active' => true,
        ]);
        $integration->products()->sync([(string) $product->id]);

        $checkout = \App\Support\AffiliateAttribution::conversionPixelsForCheckout($product->fresh(), null);

        $this->assertTrue($checkout['google_ads']['enabled']);
        $this->assertSame('AW-123456789', $checkout['google_ads']['entries'][0]['conversion_id']);
        $this->assertSame('AbCdEfGh', $checkout['google_ads']['entries'][0]['conversion_label']);
    }

    public function test_checkout_resolves_pivot_linked_google_analytics_integration(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => ['enabled' => false, 'entries' => []],
                'tiktok' => ['enabled' => false, 'entries' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => true, 'entries' => [], 'integration_ids' => []],
                'custom_script' => [],
            ],
        ]);

        $integration = ConversionPixelIntegration::create([
            'tenant_id' => $product->tenant_id,
            'platform' => ConversionPixelIntegration::PLATFORM_GOOGLE_ANALYTICS,
            'name' => 'GA4 checkout',
            'config' => ['measurement_id' => 'G-ABCDEF123'],
            'is_active' => true,
        ]);
        $integration->products()->sync([(string) $product->id]);

        $checkout = \App\Support\AffiliateAttribution::conversionPixelsForCheckout($product->fresh(), null);

        $this->assertTrue($checkout['google_analytics']['enabled']);
        $this->assertSame('G-ABCDEF123', $checkout['google_analytics']['entries'][0]['measurement_id']);
    }

    public function test_checkout_resolves_pivot_linked_tiktok_integration(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => ['enabled' => false, 'entries' => []],
                'tiktok' => ['enabled' => true, 'entries' => [], 'integration_ids' => []],
                'google_ads' => ['enabled' => false, 'entries' => []],
                'google_analytics' => ['enabled' => false, 'entries' => []],
                'custom_script' => [],
            ],
        ]);

        $integration = ConversionPixelIntegration::create([
            'tenant_id' => $product->tenant_id,
            'platform' => ConversionPixelIntegration::PLATFORM_TIKTOK,
            'name' => 'TikTok checkout',
            'config' => ['pixel_id' => 'TIKTOKPIXEL99'],
            'is_active' => true,
        ]);
        $integration->products()->sync([(string) $product->id]);

        $checkout = \App\Support\AffiliateAttribution::conversionPixelsForCheckout($product->fresh(), null);

        $this->assertTrue($checkout['tiktok']['enabled']);
        $this->assertSame('TIKTOKPIXEL99', $checkout['tiktok']['entries'][0]['pixel_id']);
    }
}
