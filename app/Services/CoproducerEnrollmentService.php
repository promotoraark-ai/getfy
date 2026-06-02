<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAffiliateProgram;
use App\Models\ProductCoproducer;
use App\Models\User;
use App\Services\StorageService;
use Illuminate\Validation\ValidationException;

class CoproducerEnrollmentService
{

    /**
     * @return array{product: Product, invite: ProductCoproducer}
     */
    public function resolvePendingInvite(string $token): array
    {
        $invite = ProductCoproducer::query()
            ->where('invite_token', $token)
            ->where('status', ProductCoproducer::STATUS_PENDING)
            ->first();

        if (! $invite) {
            abort(404, 'Convite inválido ou já utilizado.');
        }

        if ($invite->invite_expires_at && $invite->invite_expires_at->isPast()) {
            $invite->update(['status' => ProductCoproducer::STATUS_EXPIRED]);
            abort(410, 'Convite expirado.');
        }

        $invite->load('product');
        $product = $invite->product;
        if (! $product) {
            abort(404, 'Produto não encontrado.');
        }

        return ['product' => $product, 'invite' => $invite];
    }

    /**
     * @return array<string, mixed>
     */
    public function pagePayload(Product $product, ProductCoproducer $invite): array
    {
        $imageUrl = null;
        if ($product->image) {
            $imageUrl = (new StorageService($product->tenant_id))->url($product->image);
        }

        return [
            'invite' => [
                'email' => $invite->email,
                'commission_percent' => (float) $invite->commission_percent,
                'duration_days' => $invite->duration_days,
                'commission_on_producer_sales' => (bool) $invite->commission_on_producer_sales,
                'commission_on_affiliate_sales' => (bool) $invite->commission_on_affiliate_sales,
            ],
            'product' => [
                'name' => $product->name,
                'description' => $product->description,
                'image_url' => $imageUrl,
                'price' => (float) $product->price,
                'currency' => $product->currency ?? 'BRL',
            ],
        ];
    }

    public function assertUserEligible(User $user, Product $product): void
    {
        if ($user->isAluno()) {
            throw ValidationException::withMessages([
                'email' => 'Contas de aluno não podem ser co-produtoras.',
            ]);
        }

        if ($user->isAfiliado() && (int) $user->tenant_id !== (int) $product->tenant_id) {
            throw ValidationException::withMessages([
                'email' => 'Este afiliado está vinculado a outro produtor.',
            ]);
        }

        if ($user->isTeam() || $user->isInfoprodutor() || $user->isAdmin()) {
            if ((int) $user->tenant_id !== (int) $product->tenant_id) {
                throw ValidationException::withMessages([
                    'email' => 'Só é possível adicionar membros da sua equipe ou da sua conta.',
                ]);
            }

            return;
        }

        if ($user->isCoprodutor() || $user->isAfiliado()) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => 'Este tipo de conta não pode aceitar co-produção.',
        ]);
    }

    public function activateInvite(ProductCoproducer $invite, User $user): ProductCoproducer
    {
        $invite->loadMissing('product');
        $product = $invite->product;
        if (! $product) {
            throw ValidationException::withMessages(['invite' => 'Produto não encontrado.']);
        }

        if (strtolower(trim($user->email)) !== strtolower(trim($invite->email))) {
            throw ValidationException::withMessages([
                'email' => 'Use a mesma conta do e-mail convidado ('.$invite->email.').',
            ]);
        }

        $this->assertUserEligible($user, $product);
        $this->assertNotDuplicate($product->id, $user->id, $invite->email, $invite->id);

        $startsAt = now();
        $endsAt = $invite->duration_days ? $startsAt->copy()->addDays((int) $invite->duration_days) : null;

        $invite->update([
            'user_id' => $user->id,
            'email' => strtolower(trim($user->email)),
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'invite_token' => null,
            'invite_expires_at' => null,
        ]);

        return $invite->fresh();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function assignUser(Product $product, User $user, array $settings): ProductCoproducer
    {
        $this->assertUserEligible($user, $product);
        $this->assertNotDuplicate($product->id, $user->id, $user->email);

        $durationDays = $settings['duration_days'] ?? null;
        $startsAt = now();
        $endsAt = $durationDays ? $startsAt->copy()->addDays((int) $durationDays) : null;

        $coproducer = ProductCoproducer::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'email' => strtolower(trim($user->email)),
            'invite_token' => null,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'commission_percent' => $settings['commission_percent'],
            'duration_days' => $durationDays,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'commission_on_producer_sales' => (bool) ($settings['commission_on_producer_sales'] ?? true),
            'commission_on_affiliate_sales' => (bool) ($settings['commission_on_affiliate_sales'] ?? true),
            'settlement_days_pix' => $settings['settlement_days_pix'] ?? null,
            'settlement_days_card' => $settings['settlement_days_card'] ?? null,
            'settlement_days_boleto' => $settings['settlement_days_boleto'] ?? null,
            'payout_method' => $settings['payout_method'] ?? ProductCoproducer::PAYOUT_INTERNAL,
            'cajupay_split_id' => $settings['cajupay_split_id'] ?? null,
            'invite_expires_at' => null,
        ]);

        return $coproducer;
    }

    /**
     * @return list<array{id: int, name: string, email: string, role: string, role_label: string}>
     */
    public function candidatesForProduct(Product $product, ?string $search = null): array
    {
        $tenantId = (int) $product->tenant_id;

        $blockedUserIds = ProductCoproducer::query()
            ->where('product_id', $product->id)
            ->whereIn('status', [ProductCoproducer::STATUS_PENDING, ProductCoproducer::STATUS_ACTIVE])
            ->whereNotNull('user_id')
            ->pluck('user_id');

        $query = User::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', [User::ROLE_INFOPRODUTOR, User::ROLE_TEAM, User::ROLE_ADMIN])
            ->whereNotIn('id', $blockedUserIds->isEmpty() ? [0] : $blockedUserIds)
            ->orderBy('name');

        if ($search !== null && trim($search) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        return $query->limit(30)->get(['id', 'name', 'email', 'role'])->map(function (User $u) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'role_label' => match ($u->role) {
                    User::ROLE_INFOPRODUTOR => 'Infoprodutor',
                    User::ROLE_ADMIN => 'Administrador',
                    default => 'Equipe',
                },
            ];
        })->values()->all();
    }

    private function assertNotDuplicate(string $productId, int $userId, string $email, ?int $ignoreInviteId = null): void
    {
        $exists = ProductCoproducer::query()
            ->where('product_id', $productId)
            ->whereIn('status', [ProductCoproducer::STATUS_PENDING, ProductCoproducer::STATUS_ACTIVE])
            ->when($ignoreInviteId, fn ($q) => $q->where('id', '!=', $ignoreInviteId))
            ->where(function ($q) use ($userId, $email) {
                $q->where('user_id', $userId)
                    ->orWhereRaw('LOWER(email) = ?', [strtolower(trim($email))]);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'user_id' => 'Esta pessoa já é co-produtora deste produto.',
            ]);
        }
    }

}
