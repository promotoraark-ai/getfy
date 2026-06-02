<?php

namespace App\Services;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Models\CommissionEntry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAffiliate;
use App\Models\ProductAffiliateProgram;
use App\Models\ProductCoproducer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CommissionSplitService
{
    public function __construct(
        private readonly CajuPayDriver $cajuPayDriver,
        private readonly CommissionBeneficiaryResolver $beneficiaryResolver,
    ) {}

    /**
     * Resolve split na cobrança CajuPay (1 split_id por PIX).
     * Co-produtores: cada um cadastra seu UUID; na venda usamos o split de um co-produtor elegível.
     * Afiliado na mesma venda não recebe split na cobrança (só conta única / ledger interno).
     *
     * @return array{split_id: string, beneficiary_role: string, beneficiary_id: int, multiple_coproducer_splits?: bool}|null
     */
    public function resolveSplitForOrder(Order $order, Product $product): ?array
    {
        if (! $product->cajupay_split_payout_enabled) {
            return null;
        }

        $program = ProductAffiliateProgram::firstOrCreate(
            ['product_id' => $product->id],
            ['enabled' => false, 'default_commission_percent' => 0, 'manual_approval' => true]
        );

        $beneficiaries = $this->beneficiaryResolver->resolve($order, $product, $program);
        $coproducersWithSplit = [];

        foreach ($beneficiaries as $b) {
            if (($b['role'] ?? null) !== CommissionEntry::ROLE_COPRODUTOR) {
                continue;
            }
            $coproducer = $b['coproducer'] ?? null;
            if ($coproducer instanceof ProductCoproducer && $coproducer->usesCajupaySplitPayout()) {
                $coproducersWithSplit[] = $b;
            }
        }

        if ($coproducersWithSplit === []) {
            return null;
        }

        usort($coproducersWithSplit, function (array $a, array $b): int {
            $percentCmp = ((float) ($b['percent'] ?? 0)) <=> ((float) ($a['percent'] ?? 0));
            if ($percentCmp !== 0) {
                return $percentCmp;
            }

            return ((int) ($a['coproducer_id'] ?? 0)) <=> ((int) ($b['coproducer_id'] ?? 0));
        });

        $chosen = $coproducersWithSplit[0];
        $coproducer = $chosen['coproducer'];
        if (! $coproducer instanceof ProductCoproducer) {
            return null;
        }

        $result = [
            'split_id' => $coproducer->cajupay_split_id,
            'beneficiary_role' => CommissionEntry::ROLE_COPRODUTOR,
            'beneficiary_id' => (int) $coproducer->id,
        ];

        if (count($coproducersWithSplit) > 1) {
            $result['multiple_coproducer_splits'] = true;
        }

        return $result;
    }

    public function resolveSplitIdForOrder(Order $order, Product $product): ?string
    {
        return $this->resolveSplitForOrder($order, $product)['split_id'] ?? null;
    }

    public function orderUsedCajupaySplitForPartner(Order $order, array $allocatedPartner): bool
    {
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $splitId = $metadata['cajupay_split_id'] ?? null;
        if (! $splitId) {
            return false;
        }

        $role = $metadata['cajupay_split_beneficiary_role'] ?? null;
        $beneficiaryId = isset($metadata['cajupay_split_beneficiary_id'])
            ? (int) $metadata['cajupay_split_beneficiary_id']
            : null;

        if ($role !== ($allocatedPartner['role'] ?? null) || ! $beneficiaryId) {
            return false;
        }

        if ($role === CommissionEntry::ROLE_COPRODUTOR) {
            return (int) ($allocatedPartner['coproducer_id'] ?? 0) === $beneficiaryId;
        }

        if ($role === CommissionEntry::ROLE_AFILIADO) {
            return (int) ($allocatedPartner['affiliate_id'] ?? 0) === $beneficiaryId;
        }

        return false;
    }

    public function syncCoproducerSplit(ProductCoproducer $coproducer, int $tenantId): void
    {
        $this->syncPartnerSplit(
            $tenantId,
            (float) $coproducer->commission_percent,
            $coproducer->cajupay_split_id,
            function (?string $splitId) use ($coproducer) {
                $coproducer->update(['cajupay_split_id' => $splitId]);
            },
            'Co-produtor '.$coproducer->email
        );
    }

    public function syncAffiliateSplit(ProductAffiliate $affiliate, int $tenantId, float $percent): void
    {
        $this->syncPartnerSplit(
            $tenantId,
            $percent,
            $affiliate->cajupay_split_id,
            function (?string $splitId) use ($affiliate) {
                $affiliate->update(['cajupay_split_id' => $splitId]);
            },
            'Afiliado '.$affiliate->affiliate_code
        );
    }

    /**
     * @param  callable(?string): void  $persistSplitId
     */
    private function syncPartnerSplit(
        int $tenantId,
        float $percent,
        ?string $existingSplitId,
        callable $persistSplitId,
        string $name,
    ): void {
        $credential = GatewayCredential::forTenant($tenantId)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->first();

        if (! $credential) {
            return;
        }

        $credentials = $credential->getDecryptedCredentials();
        $bps = (int) round(min(100, max(0, $percent)) * 100);

        try {
            if ($existingSplitId) {
                $this->cajuPayDriver->updateSplit($credentials, $existingSplitId, $name, $bps);
            } else {
                $splitId = $this->cajuPayDriver->createSplit($credentials, $name.' '.Str::random(4), $bps);
                $persistSplitId($splitId);
            }
        } catch (\Throwable $e) {
            Log::warning('CommissionSplitService: failed to sync CajuPay split', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
        }
    }

    /**
     * @deprecated Use resolveSplitIdForOrder()
     */
    public function resolveSplitIdForCheckout(int $tenantId, ?ProductAffiliate $affiliate, $coproducers): ?string
    {
        $splitIds = [];
        if ($affiliate?->cajupay_split_id) {
            $splitIds[] = $affiliate->cajupay_split_id;
        }
        foreach ($coproducers as $c) {
            if ($c->cajupay_split_id) {
                $splitIds[] = $c->cajupay_split_id;
            }
        }
        $unique = array_unique(array_filter($splitIds));

        return count($unique) === 1 ? $unique[0] : null;
    }
}
