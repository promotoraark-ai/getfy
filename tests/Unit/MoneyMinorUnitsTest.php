<?php

namespace Tests\Unit;

use App\Support\MoneyMinorUnits;
use PHPUnit\Framework\TestCase;

class MoneyMinorUnitsTest extends TestCase
{
    public function test_usd_to_minor_units(): void
    {
        $this->assertSame(1999, MoneyMinorUnits::toMinorUnits(19.99, 'USD'));
        $this->assertSame(19.99, MoneyMinorUnits::fromMinorUnits(1999, 'usd'));
    }

    public function test_jpy_zero_decimal(): void
    {
        $this->assertSame(1500, MoneyMinorUnits::toMinorUnits(1500, 'JPY'));
        $this->assertSame(1500.0, MoneyMinorUnits::fromMinorUnits(1500, 'JPY'));
    }

    public function test_normalize_currency_code(): void
    {
        $this->assertSame('BRL', MoneyMinorUnits::normalizeCurrencyCode(''));
        $this->assertSame('USD', MoneyMinorUnits::normalizeCurrencyCode('usd'));
    }
}
