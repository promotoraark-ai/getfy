<?php

namespace Tests\Unit;

use App\Support\CajuPayLocale;
use PHPUnit\Framework\TestCase;

class CajuPayLocaleTest extends TestCase
{
    public function test_maps_checkout_locales_to_bcp47(): void
    {
        $this->assertSame('pt-BR', CajuPayLocale::fromCheckoutLocale('pt_BR'));
        $this->assertSame('en', CajuPayLocale::fromCheckoutLocale('en'));
        $this->assertSame('es', CajuPayLocale::fromCheckoutLocale('es'));
    }

    public function test_unknown_locale_falls_back_to_pt_br(): void
    {
        $this->assertSame('pt-BR', CajuPayLocale::fromCheckoutLocale('fr'));
        $this->assertSame('pt-BR', CajuPayLocale::fromCheckoutLocale(null));
        $this->assertSame('pt-BR', CajuPayLocale::fromCheckoutLocale(''));
    }
}
