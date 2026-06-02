<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAffiliate;
use App\Models\ProductAffiliateProgram;
use App\Models\ProductCoproducer;
use App\Support\AffiliateAttribution;
use Illuminate\Support\Facades\Log;

class CommissionBeneficiaryResolver
{
    /**
     * Beneficiários externos (afiliado + co-produtores) elegíveis para o pedido.
     * Percentuais já proporcionalmente ajustados se a soma ultrapassar 100%.
     *
     * @return list<array{
     *     user_id: int,
     *     role: string,
     *     percent: float,
     *     settlement_days: int,
     *     affiliate_id?: int,
     *     coproducer_id?: int,
     *     affiliate?: ProductAffiliate,
     *     coproducer?: ProductCoproducer,
     * }>
     */
    public function resolve(Order $order, Product $product, ProductAffiliateProgram $program): array
    {
        $method = $order->checkoutPaymentMethod();
        $saleChannel = $order->saleChannel();
        $beneficiaries = [];

        if ($saleChannel === 'affiliate') {
            $affiliateCode = $order->affiliateCode();
            if ($affiliateCode) {
                $affiliate = AffiliateAttribution::approvedAffiliateForCode($product->id, $affiliateCode);
                if ($affiliate) {
                    $beneficiaries[] = [
                        'user_id' => (int) $affiliate->user_id,
                        'role' => CommissionEntry::ROLE_AFILIADO,
                        'percent' => $affiliate->effectiveCommissionPercent($program),
                        'settlement_days' => $affiliate->settlementDaysForMethod($method, $program),
                        'affiliate_id' => $affiliate->id,
                        'affiliate' => $affiliate,
                    ];
                }
            }
        }

        $coproducers = ProductCoproducer::query()
            ->where('product_id', $product->id)
            ->where('status', ProductCoproducer::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->get()
            ->filter(fn (ProductCoproducer $c) => $c->isActive());

        foreach ($coproducers as $coproducer) {
            $applies = $saleChannel === 'affiliate'
                ? $coproducer->commission_on_affiliate_sales
                : $coproducer->commission_on_producer_sales;
            if (! $applies) {
                continue;
            }
            $beneficiaries[] = [
                'user_id' => (int) $coproducer->user_id,
                'role' => CommissionEntry::ROLE_COPRODUTOR,
                'percent' => (float) $coproducer->commission_percent,
                'settlement_days' => $coproducer->settlementDaysForMethod($method, $program),
                'coproducer_id' => $coproducer->id,
                'coproducer' => $coproducer,
            ];
        }

        $this->scalePercentsIfNeeded($beneficiaries, $order->id);

        return $beneficiaries;
    }

    /**
     * Divide o líquido entre parceiros; o produtor recebe o restante (centavos de arredondamento).
     *
     * @param  list<array{percent: float, ...}>  $beneficiaries
     * @return list<array{commission_amount: float, ...}>
     */
    public function allocateAmounts(float $net, array $beneficiaries): array
    {
        if ($beneficiaries === []) {
            return [];
        }

        $net = round(max(0, $net), 2);
        $allocated = 0.0;
        $result = [];
        $lastIndex = count($beneficiaries) - 1;

        foreach ($beneficiaries as $i => $b) {
            if ($i === $lastIndex) {
                $amount = round($net - $allocated, 2);
            } else {
                $amount = round($net * ((float) $b['percent'] / 100), 2);
                $allocated += $amount;
            }
            $result[] = array_merge($b, ['commission_amount' => max(0, $amount)]);
        }

        return $result;
    }

    public function producerShare(float $net, array $allocatedPartners): float
    {
        $partnerTotal = round(array_sum(array_column($allocatedPartners, 'commission_amount')), 2);

        return round(max(0, round($net, 2) - $partnerTotal), 2);
    }

    /**
     * @param  list<array{percent: float, ...}>  $beneficiaries
     */
    private function scalePercentsIfNeeded(array &$beneficiaries, int|string $orderId): void
    {
        $totalPercent = array_sum(array_column($beneficiaries, 'percent'));
        if ($totalPercent <= 100 || $beneficiaries === []) {
            return;
        }

        Log::warning('CommissionBeneficiaryResolver: total commission percent exceeds 100%', [
            'order_id' => $orderId,
            'total_percent' => $totalPercent,
        ]);

        $scale = 100 / $totalPercent;
        foreach ($beneficiaries as &$b) {
            $b['percent'] = round((float) $b['percent'] * $scale, 4);
        }
        unset($b);
    }
}
