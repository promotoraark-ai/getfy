<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\ProductAffiliateProgram;

class ProducerSaleAmount
{
    public function __construct(
        private readonly NetAmountCalculator $netCalculator,
        private readonly CommissionBeneficiaryResolver $beneficiaryResolver,
    ) {}

    /**
     * @return array{amount: float, is_producer_share: bool, is_estimated: bool, gross_total: float}
     */
    public function forOrder(Order $order): array
    {
        $grossTotal = $order->lineItemsTotalAmount();

        if ($order->saleChannel() !== 'affiliate' && ! $this->hasActiveCoproducerOnProducerSale($order)) {
            return [
                'amount' => $grossTotal,
                'is_producer_share' => false,
                'is_estimated' => false,
                'gross_total' => $grossTotal,
            ];
        }

        $order->loadMissing('commissionEntries');
        $producerEntry = $order->commissionEntries
            ->firstWhere('role', CommissionEntry::ROLE_PRODUTOR);

        if ($producerEntry) {
            return [
                'amount' => (float) $producerEntry->commission_amount,
                'is_producer_share' => true,
                'is_estimated' => false,
                'gross_total' => $grossTotal,
            ];
        }

        return [
            'amount' => $this->estimateProducerShare($order),
            'is_producer_share' => true,
            'is_estimated' => true,
            'gross_total' => $grossTotal,
        ];
    }

    private function hasActiveCoproducerOnProducerSale(Order $order): bool
    {
        if ($order->saleChannel() === 'affiliate') {
            return true;
        }

        $order->loadMissing('product');
        if (! $order->product) {
            return false;
        }

        $program = ProductAffiliateProgram::firstOrCreate(
            ['product_id' => $order->product->id],
            ['enabled' => false, 'default_commission_percent' => 0, 'manual_approval' => true]
        );

        return $this->beneficiaryResolver->resolve($order, $order->product, $program) !== [];
    }

    private function estimateProducerShare(Order $order): float
    {
        $order->loadMissing('product');
        $product = $order->product;
        if (! $product) {
            return 0.0;
        }

        $net = $this->netCalculator->forOrder($order)['net'];
        $program = ProductAffiliateProgram::firstOrCreate(
            ['product_id' => $product->id],
            ['enabled' => false, 'default_commission_percent' => 0, 'manual_approval' => true]
        );

        $beneficiaries = $this->beneficiaryResolver->resolve($order, $product, $program);
        $allocated = $this->beneficiaryResolver->allocateAmounts($net, $beneficiaries);

        return $this->beneficiaryResolver->producerShare($net, $allocated);
    }
}
