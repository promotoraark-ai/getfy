<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\CommissionEntry;
use App\Models\GatewayCredential;
use App\Models\WalletTransaction;
use App\Services\PayoutService;
use App\Services\WalletLedgerService;
use App\Support\WalletBucket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class PartnerFinanceiroController extends Controller
{
    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly PayoutService $payoutService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        if (! $user->usesPartnerPanel()) {
            abort(403);
        }

        try {
            Artisan::call('commissions:release');
        } catch (\Throwable) {
            // ignore if migrations pending
        }

        $partnerRoles = [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR];
        $balances = $this->ledger->balancesForUser($user, $partnerRoles);

        $transactions = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (WalletTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'description' => $t->description,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        $commissions = CommissionEntry::query()
            ->where('beneficiary_user_id', $user->id)
            ->whereIn('role', $partnerRoles)
            ->with(['order.product:id,name'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn (CommissionEntry $e) => [
                'id' => $e->id,
                'role' => $e->role,
                'status' => $e->status,
                'wallet_bucket' => WalletBucket::resolveFromPaymentMethod($e->payment_method),
                'cajupay_split' => (bool) (($e->metadata ?? [])['cajupay_split'] ?? false),
                'commission_amount' => (float) $e->commission_amount,
                'amount_paid' => (float) $e->amount_paid,
                'remaining' => $e->remainingAmount(),
                'product_name' => $e->order?->product?->name,
                'order_id' => $e->order_id,
                'created_at' => $e->created_at?->toIso8601String(),
                'available_at' => $e->available_at?->toIso8601String(),
            ]);

        $cajupayConnected = GatewayCredential::forTenant((int) $user->tenant_id)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->exists();

        return Inertia::render('Partner/Financeiro', [
            'cajupay_connected' => $cajupayConnected,
            'balances' => $balances,
            'wallet_labels' => collect(WalletBucket::keys())->mapWithKeys(
                fn ($k) => [$k => WalletBucket::label($k)]
            )->all(),
            'transactions' => $transactions,
            'commissions' => $commissions,
            'payouts' => $this->payoutService->recentPayoutsForUser($user),
            'pix_key' => $user->pix_key,
            'pix_key_type' => $user->pix_key_type,
            'pix_owner_document' => $user->pix_owner_document,
            'min_payout_cents' => (int) config('commissions.min_payout_cents', 100),
        ]);
    }

    public function updatePix(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->usesPartnerPanel()) {
            abort(403);
        }

        $validated = $request->validate([
            'pix_key' => ['required', 'string', 'max:255'],
            'pix_key_type' => ['required', 'string', 'in:cpf,cnpj,email,phone,random'],
            'pix_owner_document' => ['nullable', 'string', 'max:32'],
        ]);

        $user->update($validated);

        return response()->json(['success' => true]);
    }

    public function requestPayout(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->usesPartnerPanel()) {
            abort(403);
        }

        $validated = $request->validate([
            'wallet_bucket' => ['required', 'string', 'in:'.implode(',', WalletBucket::keys())],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'withdraw_all' => ['sometimes', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $withdrawAll = (bool) ($validated['withdraw_all'] ?? false);
        $amount = $withdrawAll ? null : (float) ($validated['amount'] ?? 0);

        if (! $withdrawAll && $amount <= 0) {
            return response()->json(['message' => 'Informe o valor do saque ou use sacar tudo.'], 422);
        }

        $partnerRoles = [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR];

        $result = $this->payoutService->requestPayout(
            $user,
            $validated['wallet_bucket'],
            $amount,
            $withdrawAll,
            $request,
            $partnerRoles,
            $validated['idempotency_key'] ?? null,
        );

        return response()->json([
            'success' => true,
            'replayed' => $result['replayed'] ?? false,
            'message' => $result['message'] ?? null,
            'payout' => $this->payoutService->formatPayoutForApi($result['payout_request']),
            'payout_request' => [
                'uuid' => $result['payout_request']->uuid,
                'amount' => $result['payout_request']->amount_cents / 100,
                'status' => $result['payout_request']->status,
                'pix_destination' => $this->payoutService->formatPixDestination(
                    $result['payout_request']->pix_key,
                    $result['payout_request']->pix_key_type,
                ),
            ],
        ]);
    }
}
