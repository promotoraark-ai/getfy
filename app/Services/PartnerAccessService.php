<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAffiliate;
use App\Models\ProductAffiliateProgram;
use App\Models\ProductCoproducer;
use App\Models\User;
use Illuminate\Support\Collection;

class PartnerAccessService
{
    /**
     * @return array<string, bool>
     */
    public function usesPartnerPanel(User $user): bool
    {
        if ($user->isCoprodutor() || $user->isAfiliado()) {
            return true;
        }

        return $this->hasCoproducerMembership($user);
    }

    public function hasCoproducerMembership(User $user): bool
    {
        return ProductCoproducer::query()
            ->where('user_id', $user->id)
            ->where('status', ProductCoproducer::STATUS_ACTIVE)
            ->exists();
    }

    public function permissionsFor(User $user): array
    {
        if ($user->isCoprodutor() || $this->hasCoproducerMembership($user)) {
            return [
                'dashboard.view' => true,
                'vendas.view' => true,
                'financeiro.view' => true,
                'produto.view' => true,
            ];
        }

        if ($user->isAfiliado()) {
            return [
                'dashboard.view' => true,
                'vendas.view' => true,
                'financeiro.view' => true,
                'produto.view' => true,
                'produto.links' => true,
                'produto.pixels' => true,
            ];
        }

        return [];
    }

    public function can(User $user, string $permission): bool
    {
        return ! empty($this->permissionsFor($user)[$permission]);
    }

    /**
     * @return Collection<int, ProductAffiliate>
     */
    public function affiliateMembershipsFor(User $user): Collection
    {
        if (! $user->isAfiliado()) {
            return collect();
        }

        return ProductAffiliate::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                ProductAffiliate::STATUS_PENDING,
                ProductAffiliate::STATUS_APPROVED,
            ])
            ->get();
    }

    /**
     * @return list<string>
     */
    public function allowedProductIdsFor(User $user): array
    {
        $ids = [];

        if ($user->isCoprodutor() || $this->hasCoproducerMembership($user)) {
            $ids = array_merge($ids, ProductCoproducer::query()
                ->where('user_id', $user->id)
                ->where('status', ProductCoproducer::STATUS_ACTIVE)
                ->pluck('product_id')
                ->all());
        }

        if ($user->isAfiliado()) {
            $ids = array_merge($ids, $this->affiliateMembershipsFor($user)
                ->pluck('product_id')
                ->all());
        }

        return array_values(array_unique($ids));
    }

    public function canAccessProduct(User $user, Product $product): bool
    {
        return in_array($product->id, $this->allowedProductIdsFor($user), true);
    }

    public function affiliateStatusForProduct(User $user, string $productId): ?string
    {
        if (! $user->isAfiliado()) {
            return null;
        }

        $row = ProductAffiliate::query()
            ->where('product_id', $productId)
            ->where('user_id', $user->id)
            ->whereIn('status', [
                ProductAffiliate::STATUS_PENDING,
                ProductAffiliate::STATUS_APPROVED,
            ])
            ->first();

        return $row?->status;
    }

    public function canUseAffiliateLinks(User $user, string $productId): bool
    {
        return $this->affiliateStatusForProduct($user, $productId) === ProductAffiliate::STATUS_APPROVED;
    }

    public function canEditAffiliatePixels(User $user, string $productId): bool
    {
        return $this->canUseAffiliateLinks($user, $productId);
    }

    public function affiliateMembershipForProduct(User $user, string $productId): ?ProductAffiliate
    {
        if (! $user->isAfiliado()) {
            return null;
        }

        return ProductAffiliate::query()
            ->where('product_id', $productId)
            ->where('user_id', $user->id)
            ->whereIn('status', [
                ProductAffiliate::STATUS_PENDING,
                ProductAffiliate::STATUS_APPROVED,
            ])
            ->first();
    }

    public function commissionPercentForAffiliate(ProductAffiliate $affiliate, Product $product): float
    {
        $program = ProductAffiliateProgram::query()
            ->where('product_id', $product->id)
            ->first();

        if ($program) {
            return $affiliate->effectiveCommissionPercent($program);
        }

        if ($affiliate->commission_percent !== null) {
            return (float) $affiliate->commission_percent;
        }

        return 0.0;
    }

    public function partnerTypeForProduct(User $user, string $productId): ?string
    {
        $isCoprodutor = ProductCoproducer::query()
            ->where('product_id', $productId)
            ->where('user_id', $user->id)
            ->where('status', ProductCoproducer::STATUS_ACTIVE)
            ->exists();

        if ($isCoprodutor) {
            return 'coprodutor';
        }

        if ($user->isAfiliado() && $this->affiliateMembershipForProduct($user, $productId)) {
            return 'afiliado';
        }

        return null;
    }
}
