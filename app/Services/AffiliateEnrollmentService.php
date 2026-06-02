<?php

namespace App\Services;

use App\Mail\AffiliateApprovedMail;
use App\Models\Product;
use App\Models\ProductAffiliate;
use App\Models\ProductAffiliateProgram;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AffiliateEnrollmentService
{
    /**
     * @return array{affiliate: ProductAffiliate, status: string, message: string, affiliate_link: ?string}
     */
    public function enroll(User $user, ProductAffiliateProgram $program, ?string $name = null): array
    {
        $product = $program->product;
        if (! $product instanceof Product) {
            throw ValidationException::withMessages(['program' => 'Produto não encontrado.']);
        }

        $tenantId = (int) $product->tenant_id;

        if ($user->isAdmin() || $user->isInfoprodutor() || $user->isTeam()) {
            throw ValidationException::withMessages([
                'email' => 'Esta conta não pode se afiliar. Use outro e-mail ou saia do painel do produtor.',
            ]);
        }

        if ($user->isAfiliado() && (int) $user->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'email' => 'Este e-mail já está vinculado a outro produtor.',
            ]);
        }

        if ($user->isCoprodutor()) {
            throw ValidationException::withMessages([
                'email' => 'Esta conta é de co-produtor e não pode se afiliar a este programa.',
            ]);
        }

        if ($user->isAluno() || $user->isAfiliado()) {
            $user->update([
                'role' => User::ROLE_AFILIADO,
                'tenant_id' => $tenantId,
                'name' => $name ?: $user->name,
            ]);
        } else {
            throw ValidationException::withMessages([
                'email' => 'Este e-mail já possui outro tipo de conta na plataforma.',
            ]);
        }

        $existing = ProductAffiliate::query()
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && $existing->status !== ProductAffiliate::STATUS_REMOVED) {
            $link = url('/c/'.$product->checkout_slug.'?ref='.$existing->affiliate_code);

            return [
                'affiliate' => $existing,
                'status' => $existing->status,
                'message' => 'Você já está cadastrado neste programa.',
                'affiliate_link' => $link,
            ];
        }

        $code = $this->uniqueAffiliateCode($product->id);
        $status = $program->manual_approval
            ? ProductAffiliate::STATUS_PENDING
            : ProductAffiliate::STATUS_APPROVED;

        $affiliate = ProductAffiliate::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'affiliate_code' => $code,
            'commission_percent' => null,
            'status' => $status,
        ]);

        $link = url('/c/'.$product->checkout_slug.'?ref='.$code);

        if ($status === ProductAffiliate::STATUS_APPROVED) {
            app(CommissionSplitService::class)->syncAffiliateSplit(
                $affiliate,
                $tenantId,
                (float) $program->default_commission_percent
            );
            try {
                app(TenantMailConfigService::class)->applyMailerConfigForTenant($tenantId, [], null);
                Mail::purge('smtp');
                Mail::mailer('smtp')->to($user->email)->send(new AffiliateApprovedMail($product, $affiliate, $link));
            } catch (\Throwable) {
                // silent
            }
        }

        $message = $program->manual_approval
            ? 'Cadastro enviado. Aguarde a aprovação do produtor.'
            : 'Afiliação aprovada! Você já pode divulgar seu link.';

        return [
            'affiliate' => $affiliate,
            'status' => $status,
            'message' => $message,
            'affiliate_link' => $link,
        ];
    }

    /**
     * @return array{program: ProductAffiliateProgram, product: Product}
     */
    public function resolveEnabledProgram(string $slug): array
    {
        $program = ProductAffiliateProgram::query()
            ->where('public_slug', $slug)
            ->where('enabled', true)
            ->with('product')
            ->firstOrFail();

        $product = $program->product;
        if (! $product instanceof Product) {
            abort(404);
        }

        return ['program' => $program, 'product' => $product];
    }

    /**
     * @return array<string, mixed>
     */
    public function pagePayload(ProductAffiliateProgram $program, Product $product): array
    {
        $storage = app(\App\Services\StorageService::class);

        return [
            'program' => [
                'description' => $program->description,
                'support_email' => $program->support_email,
                'default_commission_percent' => (float) $program->default_commission_percent,
                'manual_approval' => (bool) $program->manual_approval,
            ],
            'product' => [
                'name' => $product->name,
                'description' => $product->description,
                'image_url' => $product->image ? $storage->url($product->image) : null,
                'price' => (float) $product->price,
                'currency' => $product->currency ?? 'BRL',
            ],
        ];
    }

    private function uniqueAffiliateCode(string $productId): string
    {
        do {
            $code = Str::lower(Str::random(8));
        } while (ProductAffiliate::where('product_id', $productId)->where('affiliate_code', $code)->exists());

        return $code;
    }
}
