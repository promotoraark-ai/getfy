<?php

namespace Tests\Unit;

use App\Support\PartnerBuyerPresentation;
use PHPUnit\Framework\TestCase;

class PartnerBuyerPresentationTest extends TestCase
{
    public function test_masks_buyer_fields_when_not_shared(): void
    {
        $this->assertSame('j***@d***.com', PartnerBuyerPresentation::maskEmail('joao@empresa.com.br'));
        $this->assertSame('J*** S***', PartnerBuyerPresentation::maskName('João Silva'));
        $this->assertSame('(***) *****-1234', PartnerBuyerPresentation::maskPhone('11987651234'));
    }
}
