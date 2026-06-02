<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantProduct;
use App\Mail\AffiliateApprovedMail;
use App\Models\Product;
use App\Models\ProductAffiliate;
use App\Models\ProductAffiliateProgram;
use App\Services\CommissionSplitService;
use App\Services\TenantMailConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductAffiliateProgramController extends Controller
{
    use AuthorizesTenantProduct;

    public function show(Product $produto): JsonResponse
    {
        $this->authorizeTenantProduct($produto);

        $program = ProductAffiliateProgram::firstOrCreate(
            ['product_id' => $produto->id],
            [
                'enabled' => false,
                'default_commission_percent' => 10,
                'manual_approval' => true,
                'public_slug' => $this->uniquePublicSlug($produto),
            ]
        );

        $affiliates = ProductAffiliate::query()
            ->where('product_id', $produto->id)
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ProductAffiliate $a) => $this->affiliateToArray($a, $produto));

        return response()->json([
            'program' => $this->programToArray($program, $produto),
            'affiliates' => $affiliates,
        ]);
    }

    public function updateProgram(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeTenantProduct($produto);

        $program = ProductAffiliateProgram::firstOrCreate(
            ['product_id' => $produto->id],
            ['enabled' => false, 'default_commission_percent' => 10, 'manual_approval' => true]
        );

        $validated = $request->validate([
            'enabled' => ['boolean'],
            'default_commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'manual_approval' => ['boolean'],
            'share_buyer_data' => ['boolean'],
            'public_slug' => ['nullable', 'string', 'max:64', Rule::unique('product_affiliate_programs', 'public_slug')->ignore($program->id)],
            'support_email' => ['nullable', 'email', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'settlement_days_pix' => ['nullable', 'integer', 'min:0', 'max:365'],
            'settlement_days_card' => ['nullable', 'integer', 'min:0', 'max:365'],
            'settlement_days_boleto' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        if (empty($validated['public_slug'])) {
            $validated['public_slug'] = $program->public_slug ?: $this->uniquePublicSlug($produto);
        }

        $program->update($validated);

        return response()->json(['program' => $this->programToArray($program->fresh(), $produto)]);
    }

    public function updateAffiliate(Request $request, Product $produto, ProductAffiliate $affiliate): JsonResponse
    {
        $this->authorizeTenantProduct($produto);
        if ($affiliate->product_id !== $produto->id) {
            abort(404);
        }

        $validated = $request->validate([
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', Rule::in([
                ProductAffiliate::STATUS_APPROVED,
                ProductAffiliate::STATUS_REJECTED,
                ProductAffiliate::STATUS_REMOVED,
                ProductAffiliate::STATUS_PENDING,
            ])],
            'settlement_days_pix' => ['nullable', 'integer', 'min:0', 'max:365'],
            'settlement_days_card' => ['nullable', 'integer', 'min:0', 'max:365'],
            'settlement_days_boleto' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $wasPending = $affiliate->status === ProductAffiliate::STATUS_PENDING;
        $affiliate->update($validated);

        if ($wasPending && ($validated['status'] ?? '') === ProductAffiliate::STATUS_APPROVED) {
            $this->notifyAffiliateApproved($produto, $affiliate->fresh());
            app(CommissionSplitService::class)->syncAffiliateSplit(
                $affiliate->fresh(),
                (int) $produto->tenant_id,
                $affiliate->effectiveCommissionPercent($produto->affiliateProgram ?? ProductAffiliateProgram::firstOrCreate(['product_id' => $produto->id]))
            );
        }

        return response()->json(['affiliate' => $this->affiliateToArray($affiliate->fresh(), $produto)]);
    }

    public function destroyAffiliate(Product $produto, ProductAffiliate $affiliate): JsonResponse
    {
        $this->authorizeTenantProduct($produto);
        if ($affiliate->product_id !== $produto->id) {
            abort(404);
        }
        $affiliate->update(['status' => ProductAffiliate::STATUS_REMOVED]);

        return response()->json(['success' => true]);
    }

    private function uniquePublicSlug(Product $produto): string
    {
        $base = Str::slug(Str::limit($produto->name, 40, '')) ?: 'produto';
        $slug = $base;
        $i = 0;
        while (ProductAffiliateProgram::where('public_slug', $slug)->where('product_id', '!=', $produto->id)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    private function programToArray(ProductAffiliateProgram $program, Product $produto): array
    {
        $slug = $program->public_slug;

        return [
            'id' => $program->id,
            'enabled' => $program->enabled,
            'default_commission_percent' => (float) $program->default_commission_percent,
            'manual_approval' => $program->manual_approval,
            'share_buyer_data' => (bool) $program->share_buyer_data,
            'public_slug' => $slug,
            'support_email' => $program->support_email,
            'description' => $program->description,
            'settlement_days_pix' => $program->settlement_days_pix,
            'settlement_days_card' => $program->settlement_days_card,
            'settlement_days_boleto' => $program->settlement_days_boleto,
            'public_page_url' => $slug ? url('/afiliar/'.$slug) : null,
            'checkout_slug' => $produto->checkout_slug,
        ];
    }

    private function affiliateToArray(ProductAffiliate $a, Product $produto): array
    {
        $link = url('/c/'.$produto->checkout_slug.'?ref='.$a->affiliate_code);

        return [
            'id' => $a->id,
            'affiliate_code' => $a->affiliate_code,
            'commission_percent' => $a->commission_percent !== null ? (float) $a->commission_percent : null,
            'status' => $a->status,
            'affiliate_link' => $link,
            'created_at' => $a->created_at?->toIso8601String(),
            'product_name' => $produto->name,
            'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name, 'email' => $a->user->email] : null,
            'settlement_days_pix' => $a->settlement_days_pix,
            'settlement_days_card' => $a->settlement_days_card,
            'settlement_days_boleto' => $a->settlement_days_boleto,
        ];
    }

    private function notifyAffiliateApproved(Product $produto, ProductAffiliate $affiliate): void
    {
        $affiliate->load('user');
        if (! $affiliate->user) {
            return;
        }
        $link = url('/c/'.$produto->checkout_slug.'?ref='.$affiliate->affiliate_code);
        try {
            app(TenantMailConfigService::class)->applyMailerConfigForTenant((int) $produto->tenant_id, [], null);
            Mail::purge('smtp');
            Mail::mailer('smtp')->to($affiliate->user->email)->send(new AffiliateApprovedMail($produto, $affiliate, $link));
        } catch (\Throwable) {
            // silent
        }
    }
}
