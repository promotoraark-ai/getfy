<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Support\CheckoutCustomPriceByCurrency;
use PHPUnit\Framework\TestCase;

class CheckoutCustomPriceByCurrencyChargeTest extends TestCase
{
    public function test_amount_in_charge_currency_uses_custom_usd_directly(): void
    {
        $product = new Product;
        $product->checkout_config = [
            'custom_prices_by_currency' => [
                'enabled' => true,
                'amounts' => ['USD' => 19.99],
            ],
        ];
        $tenantCurrencies = [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'USD', 'rate_to_brl' => 0.18],
        ];
        $amount = CheckoutCustomPriceByCurrency::amountInChargeCurrency(
            $product,
            null,
            null,
            100.0,
            'USD',
            $tenantCurrencies
        );
        $this->assertEquals(19.99, $amount);
    }

    public function test_amount_in_charge_currency_converts_brl_via_rate(): void
    {
        $product = new Product;
        $product->checkout_config = ['custom_prices_by_currency' => ['enabled' => false]];
        $tenantCurrencies = [
            ['code' => 'USD', 'rate_to_brl' => 0.18],
        ];
        $amount = CheckoutCustomPriceByCurrency::amountInChargeCurrency(
            $product,
            null,
            null,
            100.0,
            'USD',
            $tenantCurrencies
        );
        $this->assertEquals(18.0, $amount);
    }

    public function test_bump_brl_to_charge_currency(): void
    {
        $amount = CheckoutCustomPriceByCurrency::bumpBrlToChargeCurrency(
            50.0,
            'USD',
            [['code' => 'USD', 'rate_to_brl' => 0.2]]
        );
        $this->assertEquals(10.0, $amount);
    }
}
