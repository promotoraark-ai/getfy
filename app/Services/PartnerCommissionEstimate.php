<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAffiliate;
use App\Models\ProductAffiliateProgram;
use App\Models\User;
use App\Support\AffiliateAttribution;

class PartnerCommissionEstimate
{
    public function __construct(
        private readonly NetAmountCalculator $netCalculator,
        private readonly PartnerAccessService $access,
        private readonly CommissionBeneficiaryResolver $beneficiaryResolver,
    ) {}

    /**
     * @return array{amount: float, percent: float|null}
     */
    public function forOrder(Order $order, User $user): array
    {
        $order->loadMissing('product');
        $product = $order->product;
        if (! $product) {
            return ['amount' => 0.0, 'percent' => null];
        }

        $program = ProductAffiliateProgram::firstOrCreate(
            ['product_id' => $product->id],
            ['enabled' => false, 'default_commission_percent' => 0, 'manual_approval' => true]
        );

        $beneficiaries = $this->beneficiaryResolver->resolve($order, $product, $program);
        $match = $this->matchingBeneficiary($beneficiaries, $order, $user, $product);

        if ($match === null) {
            return ['amount' => 0.0, 'percent' => null];
        }

        $net = $this->netCalculator->forOrder($order)['net'];
        $allocated = $this->beneficiaryResolver->allocateAmounts($net, [$match]);

        return [
            'amount' => (float) ($allocated[0]['commission_amount'] ?? 0),
            'percent' => (float) $match['percent'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $beneficiaries
     * @return array<string, mixed>|null
     */
    private function matchingBeneficiary(array $beneficiaries, Order $order, User $user, Product $product): ?array
    {
        foreach ($beneficiaries as $b) {
            if ((int) $b['user_id'] !== (int) $user->id) {
                continue;
            }
            if ($b['role'] === CommissionEntry::ROLE_AFILIADO) {
                $affiliate = $this->access->affiliateMembershipForProduct($user, $product->id);
                if ($affiliate && $affiliate->status === ProductAffiliate::STATUS_APPROVED) {
                    $orderCode = $order->affiliateCode();
                    $memberCode = AffiliateAttribution::normalizeRef($affiliate->affiliate_code);
                    if ($orderCode && $memberCode && $orderCode === $memberCode) {
                        return $b;
                    }
                }

                continue;
            }
            if ($b['role'] === CommissionEntry::ROLE_COPRODUTOR) {
                return $b;
            }
        }

        return null;
    }
}
