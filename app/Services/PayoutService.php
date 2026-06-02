<?php

namespace App\Services;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Models\CommissionEntry;
use App\Models\GatewayCredential;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\WalletBucket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayoutService
{
    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly PayoutAllocationService $allocation,
        private readonly CajuPayDriver $cajuPayDriver,
    ) {}

    /**
     * @param  list<string>|null  $roles
     * @return array{payout_request: PayoutRequest, payout: array<string, mixed>, replayed: bool, message?: string}
     */
    public function requestPayout(
        User $user,
        string $walletBucket,
        ?float $amount,
        bool $withdrawAll,
        Request $httpRequest,
        ?array $roles = null,
        ?string $idempotencyKey = null,
    ): array {
        if (! WalletBucket::isValid($walletBucket)) {
            throw ValidationException::withMessages(['wallet_bucket' => 'Carteira inválida.']);
        }

        $this->assertUserCanPayout($user);
        $this->assertPixConfigured($user);

        $existingResult = $this->tryReplayIdempotent($user, $idempotencyKey);
        if ($existingResult !== null) {
            return $existingResult;
        }

        $available = $this->ledger->availableForBucket($user, $walletBucket, $roles);
        if ($available <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Não há saldo disponível nesta carteira.',
            ]);
        }

        $payoutAmount = $withdrawAll ? $available : round((float) $amount, 2);

        if (! $withdrawAll && ($payoutAmount <= 0 || $payoutAmount > $available)) {
            throw ValidationException::withMessages([
                'amount' => 'Valor inválido para saque.',
            ]);
        }

        $amountCents = (int) round($payoutAmount * 100);
        $this->ledger->assertCanWithdraw($user, $walletBucket, $amountCents, $roles);

        $tenantId = (int) $user->tenant_id;
        $isPartnerPayout = $this->isPartnerPayout($user, $roles);
        $needsApproval = $isPartnerPayout && $this->partnerWalletRequiresApproval($walletBucket);

        if (! $needsApproval) {
            $credential = $this->resolveCajupayCredential($tenantId);
            $this->assertCajupayWalletBalance($credential, $amountCents);
        }

        $this->assertNoInFlightPayout($user);

        $uuid = (string) Str::uuid();
        $idempotencyKey = $idempotencyKey ?: $uuid;

        $initialStatus = $needsApproval
            ? PayoutRequest::STATUS_PENDING_APPROVAL
            : PayoutRequest::STATUS_PROCESSING;

        $payoutRequest = $this->createPayoutRequest(
            $user,
            $walletBucket,
            $payoutAmount,
            $uuid,
            $idempotencyKey,
            $httpRequest,
            $tenantId,
            $roles,
            $initialStatus,
        );

        if ($needsApproval) {
            return [
                'payout_request' => $payoutRequest,
                'payout' => [],
                'replayed' => false,
                'message' => 'Solicitação enviada. Aguarde aprovação do produtor.',
            ];
        }

        return $this->executeCajupayPayout($payoutRequest);
    }

    /**
     * @return array{payout_request: PayoutRequest, payout: array<string, mixed>, replayed: bool, message?: string}
     */
    public function executeCajupayPayout(PayoutRequest $payoutRequest): array
    {
        if ($payoutRequest->isTerminal()) {
            return [
                'payout_request' => $payoutRequest,
                'payout' => $payoutRequest->cajupay_response ?? [],
                'replayed' => true,
            ];
        }

        $payoutRequest = PayoutRequest::query()
            ->whereKey($payoutRequest->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($payoutRequest->status !== PayoutRequest::STATUS_PROCESSING) {
            throw ValidationException::withMessages([
                'payout' => 'Este saque não pode ser enviado à CajuPay no estado atual.',
            ]);
        }

        $credential = $this->resolveCajupayCredential((int) $payoutRequest->tenant_id);
        $this->assertCajupayWalletBalance($credential, (int) $payoutRequest->amount_cents);

        try {
            $result = $this->cajuPayDriver->createPayout(
                $credential->getDecryptedCredentials(),
                $payoutRequest->amount_cents,
                $payoutRequest->pix_key,
                $payoutRequest->pix_key_type,
                $payoutRequest->idempotency_key,
                $payoutRequest->pix_owner_document,
            );

            return $this->applyCajupayResponse($payoutRequest, $result);
        } catch (\Throwable $e) {
            $this->markPayoutFailed($payoutRequest, $e->getMessage());

            throw ValidationException::withMessages([
                'amount' => $this->friendlyPayoutError($e),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{payout_request: PayoutRequest, payout: array<string, mixed>, replayed: bool, message?: string}
     */
    public function applyCajupayResponse(PayoutRequest $payoutRequest, array $result): array
    {
        $payoutId = $this->cajuPayDriver->extractPayoutId($result);
        $normalized = $this->cajuPayDriver->normalizePayoutStatus($result);

        $payoutRequest->update([
            'cajupay_payout_id' => $payoutId,
            'cajupay_response' => $result,
            'cajupay_status' => $normalized,
        ]);

        if ($normalized === 'paid') {
            $this->confirmPayoutSuccess($payoutRequest->fresh());

            return [
                'payout_request' => $payoutRequest->fresh(),
                'payout' => $result,
                'replayed' => false,
                'message' => 'Saque concluído com sucesso.',
            ];
        }

        if (in_array($normalized, ['failed', 'cancelled'], true)) {
            $reason = (string) ($result['failure_reason'] ?? $result['message'] ?? 'Saque recusado pela CajuPay.');
            $this->markPayoutFailed($payoutRequest->fresh(), $reason);

            throw ValidationException::withMessages([
                'amount' => $this->friendlyPayoutError(new \RuntimeException($reason)),
            ]);
        }

        $payoutRequest->update(['status' => PayoutRequest::STATUS_AWAITING_PAYOUT]);

        return [
            'payout_request' => $payoutRequest->fresh(),
            'payout' => $result,
            'replayed' => false,
            'message' => 'Saque em processamento. Você será notificado quando concluir.',
        ];
    }

    public function confirmPayoutSuccess(PayoutRequest $payoutRequest): void
    {
        if ($payoutRequest->status === PayoutRequest::STATUS_COMPLETED) {
            return;
        }

        DB::transaction(function () use ($payoutRequest) {
            $locked = PayoutRequest::query()->whereKey($payoutRequest->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PayoutRequest::STATUS_COMPLETED) {
                return;
            }

            $locked->load('allocations.commissionEntry', 'user');

            $this->allocation->confirmAllocations($locked, $locked->allocations);

            $debitAmount = $locked->amount_cents / 100;

            WalletTransaction::create([
                'user_id' => $locked->user_id,
                'tenant_id' => $locked->tenant_id,
                'type' => WalletTransaction::TYPE_DEBIT,
                'source' => 'payout',
                'amount' => $debitAmount,
                'description' => 'Saque para '.$this->formatPixDestination($locked->pix_key, $locked->pix_key_type)
                    .' · '.WalletBucket::label($locked->wallet_bucket),
                'cajupay_reference' => $locked->cajupay_payout_id,
            ]);

            $locked->update([
                'status' => PayoutRequest::STATUS_COMPLETED,
                'cajupay_status' => 'paid',
                'completed_at' => now(),
            ]);
        });
    }

    public function releaseReservation(PayoutRequest $payoutRequest): void
    {
        $this->allocation->releaseReservation($payoutRequest);
    }

    public function markPayoutFailed(PayoutRequest $payoutRequest, string $reason): void
    {
        DB::transaction(function () use ($payoutRequest, $reason) {
            $locked = PayoutRequest::query()->whereKey($payoutRequest->id)->lockForUpdate()->firstOrFail();
            if ($locked->isTerminal()) {
                return;
            }

            $this->allocation->releaseReservation($locked);
            $locked->update([
                'status' => PayoutRequest::STATUS_FAILED,
                'cajupay_status' => 'failed',
                'failure_reason' => $reason,
                'completed_at' => now(),
            ]);
        });
    }

    public function reconcilePayout(PayoutRequest $payoutRequest): bool
    {
        if (! $payoutRequest->cajupay_payout_id) {
            return false;
        }

        if (! in_array($payoutRequest->status, [
            PayoutRequest::STATUS_PROCESSING,
            PayoutRequest::STATUS_AWAITING_PAYOUT,
        ], true)) {
            return false;
        }

        try {
            $credential = $this->resolveCajupayCredential((int) $payoutRequest->tenant_id);
            $result = $this->cajuPayDriver->getPayout(
                $credential->getDecryptedCredentials(),
                $payoutRequest->cajupay_payout_id
            );
            $normalized = $this->cajuPayDriver->normalizePayoutStatus($result);

            $payoutRequest->update([
                'cajupay_response' => $result,
                'cajupay_status' => $normalized,
            ]);

            if ($normalized === 'paid') {
                $this->confirmPayoutSuccess($payoutRequest->fresh());

                return true;
            }

            if (in_array($normalized, ['failed', 'cancelled'], true)) {
                $this->markPayoutFailed(
                    $payoutRequest->fresh(),
                    (string) ($result['failure_reason'] ?? $result['message'] ?? 'Saque falhou na CajuPay.')
                );

                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * @param  list<string>|null  $roles
     */
    public function createPayoutRequest(
        User $user,
        string $walletBucket,
        float $payoutAmount,
        string $uuid,
        string $idempotencyKey,
        Request $httpRequest,
        int $tenantId,
        ?array $roles,
        string $initialStatus,
    ): PayoutRequest {
        return DB::transaction(function () use (
            $user,
            $walletBucket,
            $payoutAmount,
            $uuid,
            $idempotencyKey,
            $httpRequest,
            $tenantId,
            $roles,
            $initialStatus,
        ) {
            $plan = $this->allocation->planAllocations($user, $walletBucket, $payoutAmount, $roles);

            if ($plan['total'] < $payoutAmount - 0.01) {
                throw ValidationException::withMessages([
                    'amount' => 'Saldo disponível insuficiente (concorrência).',
                ]);
            }

            $actualCents = (int) round($plan['total'] * 100);

            $payoutRequest = PayoutRequest::create([
                'uuid' => $uuid,
                'idempotency_key' => $idempotencyKey,
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'wallet_bucket' => $walletBucket,
                'amount_cents' => $actualCents,
                'status' => $initialStatus,
                'pix_key' => $user->pix_key,
                'pix_key_type' => $this->normalizePixKeyType($user->pix_key_type),
                'pix_owner_document' => $this->resolvePixOwnerDocument($user),
                'requested_ip' => $httpRequest->ip(),
                'requested_at' => now(),
            ]);

            $this->allocation->persistAllocations($payoutRequest, $plan['allocations']);

            return $payoutRequest;
        });
    }

    public function assertUserCanPayout(User $user): void
    {
        $canPartner = $user->usesPartnerPanel();
        $canProducer = $user->isAdmin() || $user->isInfoprodutor();

        if (! $canPartner && ! $canProducer) {
            abort(403);
        }
    }

    public function assertPixConfigured(User $user): void
    {
        if (! $user->pix_key || ! $user->pix_key_type) {
            throw ValidationException::withMessages([
                'pix_key' => 'Cadastre sua chave PIX no financeiro antes de sacar.',
            ]);
        }

        $type = $this->normalizePixKeyType($user->pix_key_type);
        if (in_array($type, ['email', 'phone', 'evp', 'random'], true)) {
            $doc = $this->resolvePixOwnerDocument($user);
            if ($doc === null || $doc === '') {
                throw ValidationException::withMessages([
                    'pix_owner_document' => 'Informe o CPF/CNPJ do titular da chave PIX.',
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentPayoutsForUser(User $user, int $limit = 20): array
    {
        return PayoutRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (PayoutRequest $p) => $this->formatPayoutForApi($p))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatPayoutForApi(PayoutRequest $p): array
    {
        return [
            'id' => $p->id,
            'uuid' => $p->uuid,
            'wallet_bucket' => $p->wallet_bucket,
            'wallet_label' => WalletBucket::label($p->wallet_bucket),
            'amount' => $p->amount_cents / 100,
            'status' => $p->status,
            'cajupay_status' => $p->cajupay_status,
            'failure_reason' => $p->failure_reason,
            'rejection_reason' => $p->rejection_reason,
            'pix_key_type' => $p->pix_key_type,
            'pix_key_type_label' => $this->pixKeyTypeLabel($p->pix_key_type),
            'pix_key_masked' => $this->maskPixKey($p->pix_key, $p->pix_key_type),
            'pix_destination' => $this->formatPixDestination($p->pix_key, $p->pix_key_type),
            'created_at' => $p->created_at?->toIso8601String(),
            'completed_at' => $p->completed_at?->toIso8601String(),
            'approved_at' => $p->approved_at?->toIso8601String(),
        ];
    }

    public function formatPixDestination(?string $key, ?string $type, bool $masked = true): string
    {
        $label = $this->pixKeyTypeLabel($type);
        if (! $key) {
            return $label;
        }

        $display = $masked ? $this->maskPixKey($key, $type) : $key;

        return $label.': '.$display;
    }

    public function pixKeyTypeLabel(?string $type): string
    {
        return match (strtolower((string) $type)) {
            'email' => 'E-mail',
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'phone' => 'Telefone',
            'random', 'evp' => 'Chave aleatória',
            default => $type ? ucfirst($type) : 'PIX',
        };
    }

    public function maskPixKey(?string $key, ?string $type): string
    {
        if (! $key) {
            return '—';
        }

        $type = strtolower((string) $type);
        if (in_array($type, ['email'], true) && str_contains($key, '@')) {
            [$local, $domain] = explode('@', $key, 2);
            $localMask = strlen($local) <= 2
                ? str_repeat('*', strlen($local))
                : substr($local, 0, 1).str_repeat('*', max(1, strlen($local) - 2)).substr($local, -1);

            return $localMask.'@'.$domain;
        }

        $len = strlen($key);
        if ($len <= 4) {
            return '****';
        }

        return substr($key, 0, 2).'***'.substr($key, -2);
    }

    /**
     * @param  list<string>|null  $roles
     */
    public function isPartnerPayout(User $user, ?array $roles): bool
    {
        if ($roles !== null && $roles !== []) {
            $partnerRoles = [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR];
            $onlyPartner = count(array_diff($roles, $partnerRoles)) === 0
                && count(array_intersect($roles, $partnerRoles)) > 0;

            if ($onlyPartner) {
                return true;
            }
            if (in_array(CommissionEntry::ROLE_PRODUTOR, $roles, true)) {
                return false;
            }
        }

        return $user->usesPartnerPanel() && ! $user->isAdmin() && ! $user->isInfoprodutor();
    }

    public function partnerWalletRequiresApproval(string $walletBucket): bool
    {
        return in_array($walletBucket, config('commissions.partner_payout_requires_approval', ['card', 'boleto']), true);
    }

    public function resolveCajupayCredential(int $tenantId): GatewayCredential
    {
        $credential = GatewayCredential::forTenant($tenantId)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->first();

        if (! $credential) {
            throw ValidationException::withMessages([
                'amount' => 'Saque via CajuPay indisponível. O produtor precisa conectar o gateway.',
            ]);
        }

        return $credential;
    }

    /**
     * @return array{payout_request: PayoutRequest, payout: array<string, mixed>, replayed: bool, message?: string}|null
     */
    private function tryReplayIdempotent(User $user, ?string $idempotencyKey): ?array
    {
        if (! $idempotencyKey) {
            return null;
        }

        $existing = PayoutRequest::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('user_id', $user->id)
            ->first();

        if (! $existing) {
            return null;
        }

        if ($existing->status === PayoutRequest::STATUS_COMPLETED) {
            return [
                'payout_request' => $existing,
                'payout' => $existing->cajupay_response ?? [],
                'replayed' => true,
                'message' => 'Saque já concluído.',
            ];
        }

        if ($existing->status === PayoutRequest::STATUS_PENDING_APPROVAL) {
            return [
                'payout_request' => $existing,
                'payout' => [],
                'replayed' => true,
                'message' => 'Solicitação já registrada. Aguarde aprovação do produtor.',
            ];
        }

        if (in_array($existing->status, [
            PayoutRequest::STATUS_PROCESSING,
            PayoutRequest::STATUS_AWAITING_PAYOUT,
        ], true)) {
            throw ValidationException::withMessages([
                'amount' => 'Já existe um saque em processamento. Aguarde a conclusão.',
            ]);
        }

        if ($existing->status === PayoutRequest::STATUS_FAILED) {
            $existing->delete();

            return null;
        }

        return null;
    }

    private function assertNoInFlightPayout(User $user): void
    {
        if (PayoutRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                PayoutRequest::STATUS_PROCESSING,
                PayoutRequest::STATUS_AWAITING_PAYOUT,
            ])
            ->exists()) {
            throw ValidationException::withMessages([
                'amount' => 'Já existe um saque em processamento.',
            ]);
        }
    }

    private function assertCajupayWalletBalance(GatewayCredential $credential, int $amountCents): void
    {
        try {
            $balance = $this->cajuPayDriver->getWalletBalance(
                $credential->getDecryptedCredentials(),
                'main'
            );
            $availableCents = (int) ($balance['available_cents'] ?? $balance['balance_cents'] ?? $balance['amount_cents'] ?? 0);
            if ($availableCents > 0 && $availableCents < $amountCents) {
                throw ValidationException::withMessages([
                    'amount' => 'Saldo insuficiente na carteira CajuPay do produtor para este saque.',
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            // proceed — CajuPay will reject if insufficient
        }
    }

    private function normalizePixKeyType(?string $type): string
    {
        $type = strtolower((string) $type);
        if ($type === 'random') {
            return 'evp';
        }

        return $type;
    }

    private function resolvePixOwnerDocument(User $user): ?string
    {
        $doc = preg_replace('/\D/', '', (string) ($user->pix_owner_document ?? ''));
        if ($doc !== '') {
            return $doc;
        }

        $type = $this->normalizePixKeyType($user->pix_key_type);
        if (in_array($type, ['cpf', 'cnpj'], true)) {
            return preg_replace('/\D/', '', (string) $user->pix_key);
        }

        return null;
    }

    private function friendlyPayoutError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'payouts_blocked_pending_kyc')) {
            return 'Saque bloqueado: complete o KYC na CajuPay.';
        }

        return 'Não foi possível processar o saque. '.$msg;
    }
}
