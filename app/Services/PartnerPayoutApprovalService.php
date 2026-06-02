<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\ProductCoproducer;
use App\Models\PayoutRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartnerPayoutApprovalService
{
    public function __construct(
        private readonly PayoutService $payoutService,
    ) {}

    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   summary: array{pending_count: int, pending_amount: float}
     * }
     */
    public function listForTenant(int $tenantId, ?string $status = null): array
    {
        $query = PayoutRequest::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('user', function ($q) {
                $q->whereIn('role', [User::ROLE_AFILIADO, User::ROLE_COPRODUTOR])
                    ->orWhereHas('coproducerProducts', fn ($q2) => $q2->where('status', ProductCoproducer::STATUS_ACTIVE));
            })
            ->with('user:id,name,email,role')
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', [
                PayoutRequest::STATUS_PENDING_APPROVAL,
                PayoutRequest::STATUS_AWAITING_PAYOUT,
                PayoutRequest::STATUS_PROCESSING,
                PayoutRequest::STATUS_COMPLETED,
                PayoutRequest::STATUS_FAILED,
                PayoutRequest::STATUS_CANCELLED,
            ]);
        }

        $items = $query->limit(100)->get()->map(fn (PayoutRequest $p) => $this->formatForProducer($p))->all();

        $pending = PayoutRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('status', PayoutRequest::STATUS_PENDING_APPROVAL)
            ->whereHas('user', function ($q) {
                $q->whereIn('role', [User::ROLE_AFILIADO, User::ROLE_COPRODUTOR])
                    ->orWhereHas('coproducerProducts', fn ($q2) => $q2->where('status', ProductCoproducer::STATUS_ACTIVE));
            })
            ->get();

        return [
            'items' => $items,
            'summary' => [
                'pending_count' => $pending->count(),
                'pending_amount' => round($pending->sum('amount_cents') / 100, 2),
            ],
        ];
    }

    /**
     * @return array{payout_request: PayoutRequest, payout: array<string, mixed>, message?: string}
     */
    public function approve(User $producer, PayoutRequest $payout): array
    {
        $this->assertProducerCanManage($producer, $payout);

        return DB::transaction(function () use ($producer, $payout) {
            $locked = PayoutRequest::query()
                ->whereKey($payout->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== PayoutRequest::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'payout' => 'Este saque não está aguardando aprovação.',
                ]);
            }

            $this->assertPartnerPayoutRecord($locked);

            $reservedCount = CommissionEntry::query()
                ->where('payout_request_id', $locked->id)
                ->where('status', CommissionEntry::STATUS_RESERVED)
                ->count();

            if ($reservedCount === 0) {
                throw ValidationException::withMessages([
                    'payout' => 'Reserva de comissão inválida. Solicite um novo saque.',
                ]);
            }

            $locked->update([
                'approved_by_user_id' => $producer->id,
                'approved_at' => now(),
                'status' => PayoutRequest::STATUS_PROCESSING,
            ]);

            return $this->payoutService->executeCajupayPayout($locked->fresh());
        });
    }

    public function reject(User $producer, PayoutRequest $payout, ?string $reason = null): PayoutRequest
    {
        $this->assertProducerCanManage($producer, $payout);

        return DB::transaction(function () use ($producer, $payout, $reason) {
            $locked = PayoutRequest::query()
                ->whereKey($payout->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== PayoutRequest::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'payout' => 'Este saque não está aguardando aprovação.',
                ]);
            }

            $this->assertPartnerPayoutRecord($locked);

            $this->payoutService->releaseReservation($locked);

            $locked->update([
                'status' => PayoutRequest::STATUS_CANCELLED,
                'rejected_by_user_id' => $producer->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason ?: 'Rejeitado pelo produtor.',
                'completed_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    private function assertProducerCanManage(User $producer, PayoutRequest $payout): void
    {
        if (! $producer->isAdmin() && ! $producer->isInfoprodutor()) {
            $access = app(TeamAccessService::class);
            if (! $producer->isTeam() || ! $access->can($producer, 'financeiro.manage')) {
                abort(403);
            }
        }

        if ((int) $payout->tenant_id !== (int) $producer->tenant_id) {
            abort(404);
        }
    }

    private function assertPartnerPayoutRecord(PayoutRequest $payout): void
    {
        $payout->loadMissing('user');
        $beneficiary = $payout->user;

        if (! $beneficiary || ! $this->payoutService->isPartnerPayout($beneficiary, null)) {
            throw ValidationException::withMessages([
                'payout' => 'Este saque não pertence a um afiliado ou co-produtor.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatForProducer(PayoutRequest $p): array
    {
        $user = $p->user;

        return array_merge($this->payoutService->formatPayoutForApi($p), [
            'partner' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'role' => $user?->role,
            ],
            'pix_key_masked' => $this->payoutService->maskPixKey($p->pix_key, $p->pix_key_type),
        ]);
    }
}
