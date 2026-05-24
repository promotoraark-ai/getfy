<?php

namespace Tests\Unit;

use App\Support\CheckoutPaymentMethodOrder;
use PHPUnit\Framework\TestCase;

class CheckoutPaymentMethodOrderTest extends TestCase
{
    public function test_br_or_null_keeps_order(): void
    {
        $methods = [
            ['id' => 'pix', 'label' => 'PIX'],
            ['id' => 'card', 'label' => 'Cartão'],
        ];
        $this->assertSame($methods, CheckoutPaymentMethodOrder::applyForCountry($methods, 'BR'));
        $this->assertSame($methods, CheckoutPaymentMethodOrder::applyForCountry($methods, null));
    }

    public function test_non_br_puts_card_before_pix(): void
    {
        $methods = [
            ['id' => 'pix', 'label' => 'PIX'],
            ['id' => 'card', 'label' => 'Cartão'],
        ];
        $out = CheckoutPaymentMethodOrder::applyForCountry($methods, 'US');
        $this->assertSame(['card', 'pix'], array_column($out, 'id'));
    }

    public function test_non_br_order_card_wallets_boleto_then_pix_tail(): void
    {
        $methods = [
            ['id' => 'pix', 'label' => 'PIX'],
            ['id' => 'boleto', 'label' => 'Boleto'],
            ['id' => 'card', 'label' => 'Cartão'],
            ['id' => 'apple_pay', 'label' => 'Apple Pay'],
        ];
        $out = CheckoutPaymentMethodOrder::applyForCountry($methods, 'DE');
        $this->assertSame(['card', 'apple_pay', 'boleto', 'pix'], array_column($out, 'id'));
    }

    public function test_pix_auto_before_pix_at_end(): void
    {
        $methods = [
            ['id' => 'pix', 'label' => 'PIX'],
            ['id' => 'pix_auto', 'label' => 'PIX auto'],
            ['id' => 'card', 'label' => 'Cartão'],
        ];
        $out = CheckoutPaymentMethodOrder::applyForCountry($methods, 'FR');
        $this->assertSame(['card', 'pix_auto', 'pix'], array_column($out, 'id'));
    }
}
