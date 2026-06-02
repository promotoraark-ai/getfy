<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\ProductAffiliateProgram;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class CommissionAllocator
{
    public function __construct(
        private readonly NetAmountCalculator $netCalculator,
        private readonly CommissionBeneficiaryResolver $beneficiaryResolver,
        private readonly CommissionSplitService $commissionSplitService,
    ) {}

    public function allocateForCompletedOrder(Order $order): void
    {
        if ($order->status !== 'completed') {
            return;
        }

        if ($order->commissionEntries()->exists()) {
            return;
        }

        $order->loadMissing('product');
        $product = $order->product;
        if (! $product) {
            return;
        }

        $amounts = $this->netCalculator->forOrder($order);
        $gross = $amounts['gross'];
        $fee = $amounts['fee'];
        $net = $amounts['net'];
        $method = $order->checkoutPaymentMethod();
        $tenantId = (int) $order->tenant_id;

        $program = ProductAffiliateProgram::firstOrCreate(
            ['product_id' => $product->id],
            [
                'enabled' => false,
                'default_commission_percent' => 0,
                'manual_approval' => true,
            ]
        );

        $beneficiaries = $this->beneficiaryResolver->resolve($order, $product, $program);
        $allocatedPartners = $this->beneficiaryResolver->allocateAmounts($net, $beneficiaries);
        $totalPercent = array_sum(array_column($beneficiaries, 'percent'));

        $producerUserId = User::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', [User::ROLE_INFOPRODUTOR, User::ROLE_ADMIN])
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [User::ROLE_INFOPRODUTOR])
            ->value('id');

        $producerShare = $this->beneficiaryResolver->producerShare($net, $allocatedPartners);

        DB::transaction(function () use (
            $order,
            $tenantId,
            $gross,
            $fee,
            $net,
            $method,
            $allocatedPartners,
            $producerUserId,
            $producerShare,
            $totalPercent,
        ) {
            foreach ($allocatedPartners as $b) {
                $commissionAmount = (float) $b['commission_amount'];
                $paidViaCajupaySplit = $this->commissionSplitService->orderUsedCajupaySplitForPartner($order, $b);

                $entryMetadata = array_filter([
                    'affiliate_id' => $b['affiliate_id'] ?? null,
                    'coproducer_id' => $b['coproducer_id'] ?? null,
                    'cajupay_split' => $paidViaCajupaySplit ? true : null,
                ]);

                $entry = CommissionEntry::create([
                    'order_id' => $order->id,
                    'tenant_id' => $tenantId,
                    'beneficiary_user_id' => $b['user_id'],
                    'role' => $b['role'],
                    'gross_amount' => $gross,
                    'gateway_fee_amount' => $fee,
                    'net_amount' => $net,
                    'commission_percent' => $b['percent'],
                    'commission_amount' => $commissionAmount,
                    'status' => $paidViaCajupaySplit
                        ? CommissionEntry::STATUS_SETTLED_EXTERNALLY
                        : CommissionEntry::STATUS_PENDING,
                    'payment_method' => $method,
                    'available_at' => $paidViaCajupaySplit ? now() : now()->addDays((int) $b['settlement_days']),
                    'paid_at' => $paidViaCajupaySplit ? now() : null,
                    'metadata' => $entryMetadata,
                ]);

                if (! $paidViaCajupaySplit) {
                    WalletTransaction::create([
                        'user_id' => $b['user_id'],
                        'tenant_id' => $tenantId,
                        'type' => WalletTransaction::TYPE_CREDIT,
                        'source' => 'commission',
                        'amount' => $commissionAmount,
                        'description' => 'Comissão pedido #'.$order->id,
                        'commission_entry_id' => $entry->id,
                    ]);
                }
            }

            if ($producerUserId && $producerShare > 0) {
                CommissionEntry::create([
                    'order_id' => $order->id,
                    'tenant_id' => $tenantId,
                    'beneficiary_user_id' => $producerUserId,
                    'role' => CommissionEntry::ROLE_PRODUTOR,
                    'gross_amount' => $gross,
                    'gateway_fee_amount' => $fee,
                    'net_amount' => $net,
                    'commission_percent' => max(0, round(100 - min(100, $totalPercent), 4)),
                    'commission_amount' => $producerShare,
                    'status' => CommissionEntry::STATUS_AVAILABLE,
                    'payment_method' => $method,
                    'available_at' => now(),
                    'metadata' => ['internal' => true],
                ]);
            }
        });
    }
}
