<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantProduct;
use App\Mail\CoproducerInviteMail;
use App\Models\Product;
use App\Models\ProductAffiliateProgram;
use App\Models\ProductCoproducer;
use App\Models\User;
use App\Services\CoproducerEnrollmentService;
use App\Services\TenantMailConfigService;
use App\Support\CoproducerPayoutRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProductCoproducerController extends Controller
{
    use AuthorizesTenantProduct;

    public function __construct(
        private readonly CoproducerEnrollmentService $enrollment,
    ) {}

    public function candidates(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeTenantProduct($produto);

        $search = $request->query('q');

        return response()->json([
            'candidates' => $this->enrollment->candidatesForProduct(
                $produto,
                is_string($search) ? $search : null
            ),
        ]);
    }

    public function updateCoproductionSettings(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeTenantProduct($produto);

        $validated = $request->validate([
            'cajupay_split_payout_enabled' => ['required', 'boolean'],
        ]);

        if ($validated['cajupay_split_payout_enabled'] && ! CoproducerPayoutRules::tenantHasCajupay((int) $produto->tenant_id)) {
            return response()->json([
                'message' => 'Conecte o gateway CajuPay em Integrações antes de ativar split.',
            ], 422);
        }

        $produto->update([
            'cajupay_split_payout_enabled' => (bool) $validated['cajupay_split_payout_enabled'],
        ]);

        return response()->json([
            'success' => true,
            'cajupay_split_payout_enabled' => $produto->cajupay_split_payout_enabled,
        ]);
    }

    public function assign(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeTenantProduct($produto);

        $validated = $request->validate(array_merge([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'duration_days' => ['nullable', 'integer', 'in:30,60,90,120'],
            'commission_on_producer_sales' => ['boolean'],
            'commission_on_affiliate_sales' => ['boolean'],
            'settlement_days_pix' => ['nullable', 'integer', 'min:0', 'max:365'],
            'settlement_days_card' => ['nullable', 'integer', 'min:0', 'max:365'],
            'settlement_days_boleto' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], CoproducerPayoutRules::validationRules()));

        $payout = CoproducerPayoutRules::normalizePayoutFields($produto, $validated);
        $validated = array_merge($validated, $payout);

        $user = User::query()->findOrFail($validated['user_id']);
        $coproducer = $this->enrollment->assignUser($produto, $user, $validated);

        return response()->json(['success' => true, 'coproducer' => $this->coproducerToArray($coproducer)]);
    }

    public function update(Request $request, Product $produto, ProductCoproducer $coproducer): JsonResponse
    {
        $this->authorizeTenantProduct($produto);
        if ($coproducer->product_id !== $produto->id) {
            abort(404);
        }
        if ($coproducer->status !== ProductCoproducer::STATUS_ACTIVE) {
            return response()->json(['message' => 'Só é possível editar co-produtores ativos.'], 422);
        }

        $validated = $request->validate(array_merge([
            'commission_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'commission_on_producer_sales' => ['sometimes', 'boolean'],
            'commission_on_affiliate_sales' => ['sometimes', 'boolean'],
            'settlement_days_pix' => ['nullable', 'integer', 'min:0', 'max:365'],
            'settlement_days_card' => ['nullable', 'integer', 'min:0', 'max:365'],
            'settlement_days_boleto' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], CoproducerPayoutRules::validationRules()));

        if (array_key_exists('payout_method', $validated) || array_key_exists('cajupay_split_id', $validated)) {
            $payout = CoproducerPayoutRules::normalizePayoutFields($produto, array_merge(
                [
                    'payout_method' => $coproducer->payout_method ?? ProductCoproducer::PAYOUT_INTERNAL,
                    'cajupay_split_id' => $coproducer->cajupay_split_id,
                ],
                $validated
            ));
            $validated = array_merge($validated, $payout);
        }

        $updateFields = [
            'commission_percent',
            'commission_on_producer_sales',
            'commission_on_affiliate_sales',
            'settlement_days_pix',
            'settlement_days_card',
            'settlement_days_boleto',
            'payout_method',
            'cajupay_split_id',
        ];
        $payload = [];
        foreach ($updateFields as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }
        $coproducer->update($payload);

        return response()->json([
            'success' => true,
            'coproducer' => $this->coproducerToArray($coproducer->fresh()),
        ]);
    }

    public function index(Product $produto): JsonResponse
    {
        $this->authorizeTenantProduct($produto);

        $items = ProductCoproducer::query()
            ->where('product_id', $produto->id)
            ->with('user:id,name,email,role')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ProductCoproducer $c) => $this->coproducerToArray($c, $produto));

        $program = ProductAffiliateProgram::firstOrCreate(
            ['product_id' => $produto->id],
            ['enabled' => false, 'default_commission_percent' => 0, 'manual_approval' => true]
        );

        return response()->json([
            'coproducers' => $items,
            'cajupay_split_payout_enabled' => (bool) $produto->cajupay_split_payout_enabled,
            'cajupay_connected' => CoproducerPayoutRules::tenantHasCajupay((int) $produto->tenant_id),
            'default_settlement_days' => [
                'pix' => $program->settlement_days_pix,
                'card' => $program->settlement_days_card,
                'boleto' => $program->settlement_days_boleto,
            ],
        ]);
    }

    public function invite(Request $request, Product $produto): JsonResponse
    {
        $this->authorizeTenantProduct($produto);

        $validated = $request->validate(array_merge([
            'email' => ['required', 'email', 'max:255'],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'duration_days' => ['nullable', 'integer', 'in:30,60,90,120'],
            'commission_on_producer_sales' => ['boolean'],
            'commission_on_affiliate_sales' => ['boolean'],
            'settlement_days_pix' => ['nullable', 'integer', 'min:0', 'max:365'],
            'settlement_days_card' => ['nullable', 'integer', 'min:0', 'max:365'],
            'settlement_days_boleto' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], CoproducerPayoutRules::validationRules()));

        $payout = CoproducerPayoutRules::normalizePayoutFields($produto, $validated);

        $email = strtolower(trim($validated['email']));
        $token = Str::random(48);
        $expiresDays = (int) config('commissions.coproducer_invite_expires_days', 14);
        $durationDays = $validated['duration_days'] ?? null;

        $coproducer = ProductCoproducer::create([
            'product_id' => $produto->id,
            'email' => $email,
            'invite_token' => $token,
            'status' => ProductCoproducer::STATUS_PENDING,
            'commission_percent' => $validated['commission_percent'],
            'duration_days' => $durationDays,
            'commission_on_producer_sales' => (bool) ($validated['commission_on_producer_sales'] ?? true),
            'commission_on_affiliate_sales' => (bool) ($validated['commission_on_affiliate_sales'] ?? true),
            'settlement_days_pix' => $validated['settlement_days_pix'] ?? null,
            'settlement_days_card' => $validated['settlement_days_card'] ?? null,
            'settlement_days_boleto' => $validated['settlement_days_boleto'] ?? null,
            'payout_method' => $payout['payout_method'],
            'cajupay_split_id' => $payout['cajupay_split_id'],
            'invite_expires_at' => now()->addDays($expiresDays),
        ]);

        $inviteUrl = url('/convite/co-producao/'.$token);
        try {
            app(TenantMailConfigService::class)->applyMailerConfigForTenant((int) $produto->tenant_id, [], null);
            Mail::purge('smtp');
            Mail::mailer('smtp')->to($email)->send(new CoproducerInviteMail(
                $produto,
                $inviteUrl,
                (float) $validated['commission_percent']
            ));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'coproducer' => $this->coproducerToArray($coproducer),
                'invite_url' => $inviteUrl,
                'warning' => 'Convite criado, mas o e-mail não foi enviado: '.$e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'coproducer' => $this->coproducerToArray($coproducer),
            'invite_url' => $inviteUrl,
        ]);
    }

    public function revoke(Product $produto, ProductCoproducer $coproducer): JsonResponse
    {
        $this->authorizeTenantProduct($produto);
        if ($coproducer->product_id !== $produto->id) {
            abort(404);
        }
        $coproducer->update(['status' => ProductCoproducer::STATUS_REVOKED]);

        return response()->json(['success' => true]);
    }

    public function resendInvite(Product $produto, ProductCoproducer $coproducer): JsonResponse
    {
        $this->authorizeTenantProduct($produto);
        if ($coproducer->product_id !== $produto->id || $coproducer->status !== ProductCoproducer::STATUS_PENDING) {
            abort(422, 'Convite inválido.');
        }

        $expiresDays = (int) config('commissions.coproducer_invite_expires_days', 14);
        $coproducer->update([
            'invite_expires_at' => now()->addDays($expiresDays),
            'invite_token' => $coproducer->invite_token ?: Str::random(48),
        ]);

        $inviteUrl = url('/convite/co-producao/'.$coproducer->invite_token);
        app(TenantMailConfigService::class)->applyMailerConfigForTenant((int) $produto->tenant_id, [], null);
        Mail::purge('smtp');
        Mail::mailer('smtp')->to($coproducer->email)->send(new CoproducerInviteMail(
            $produto,
            $inviteUrl,
            (float) $coproducer->commission_percent
        ));

        return response()->json(['success' => true, 'invite_url' => $inviteUrl]);
    }

    /**
     * @return array<string, mixed>
     */
    private function coproducerToArray(ProductCoproducer $c, ?Product $produto = null): array
    {
        return [
            'id' => $c->id,
            'email' => $c->email,
            'created_at' => $c->created_at?->toIso8601String(),
            'product_name' => $produto?->name,
            'user' => $c->user ? [
                'id' => $c->user->id,
                'name' => $c->user->name,
                'email' => $c->user->email,
                'role' => $c->user->role,
                'role_label' => match ($c->user->role) {
                    User::ROLE_INFOPRODUTOR => 'Infoprodutor',
                    User::ROLE_ADMIN => 'Administrador',
                    User::ROLE_TEAM => 'Equipe',
                    User::ROLE_COPRODUTOR => 'Co-produtor',
                    default => $c->user->role,
                },
            ] : null,
            'status' => $c->status,
            'source' => $c->user_id && ! $c->invite_token ? 'member' : 'invite',
            'commission_percent' => (float) $c->commission_percent,
            'duration_days' => $c->duration_days,
            'starts_at' => $c->starts_at?->toIso8601String(),
            'ends_at' => $c->ends_at?->toIso8601String(),
            'commission_on_producer_sales' => $c->commission_on_producer_sales,
            'commission_on_affiliate_sales' => $c->commission_on_affiliate_sales,
            'settlement_days_pix' => $c->settlement_days_pix,
            'settlement_days_card' => $c->settlement_days_card,
            'settlement_days_boleto' => $c->settlement_days_boleto,
            'payout_method' => $c->payout_method ?? ProductCoproducer::PAYOUT_INTERNAL,
            'cajupay_split_id' => $c->cajupay_split_id,
            'invite_expires_at' => $c->invite_expires_at?->toIso8601String(),
        ];
    }
}
