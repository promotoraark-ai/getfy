<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Support\CheckoutCustomPriceByCurrency;
use PHPUnit\Framework\TestCase;

class CheckoutCustomPriceByCurrencyTest extends TestCase
{
    public function test_amount_brl_from_custom_divides_foreign_amount_by_rate(): void
    {
        $product = new Product;
        $product->checkout_config = [
            'custom_prices_by_currency' => [
                'enabled' => true,
                'amounts' => ['USD' => 18.00],
            ],
        ];
        $tenantCurrencies = [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'USD', 'rate_to_brl' => 0.18],
        ];
        $amount = CheckoutCustomPriceByCurrency::amountBrlFromCustomIfApplicable(
            $product,
            null,
            null,
            100.0,
            'USD',
            $tenantCurrencies
        );
        $this->assertEquals(100.0, $amount);
    }

    public function test_disabled_or_offer_skips_custom(): void
    {
        $product = new Product;
        $product->checkout_config = [
            'custom_prices_by_currency' => [
                'enabled' => false,
                'amounts' => ['USD' => 1],
            ],
        ];
        $offer = $this->createMock(ProductOffer::class);
        $out = CheckoutCustomPriceByCurrency::amountBrlFromCustomIfApplicable(
            $product,
            $offer,
            null,
            50.0,
            'USD',
            [['code' => 'USD', 'rate_to_brl' => 0.2]]
        );
        $this->assertEquals(50.0, $out);
    }

    public function test_currency_codes_from_tenant_includes_brl_once(): void
    {
        $codes = CheckoutCustomPriceByCurrency::currencyCodesFromTenantSettings([
            ['code' => 'USD', 'rate_to_brl' => 0.18],
        ]);
        $this->assertSame(['BRL', 'USD'], $codes);
    }
}
