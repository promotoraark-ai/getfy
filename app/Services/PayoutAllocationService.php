<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\PayoutRequest;
use App\Models\PayoutRequestAllocation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayoutAllocationService
{
    public function __construct(
        private readonly WalletLedgerService $ledger,
    ) {}

    /**
     * @param  list<string>|null  $roles
     * @return array{allocations: list<array{entry: CommissionEntry, amount: float}>, total: float}
     */
    public function planAllocations(User $user, string $bucket, float $amount, ?array $roles = null): array
    {
        $remaining = round($amount, 2);
        $allocations = [];

        $entries = $this->ledger
            ->availableEntriesQuery($user, $bucket, $roles)
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            if ($remaining <= 0) {
                break;
            }

            $entryRemaining = $entry->remainingAmount();
            if ($entryRemaining <= 0) {
                continue;
            }

            $take = min($entryRemaining, $remaining);
            $allocations[] = [
                'entry' => $entry,
                'amount' => $take,
            ];
            $remaining = round($remaining - $take, 2);
        }

        $total = round($amount - $remaining, 2);

        return [
            'allocations' => $allocations,
            'total' => $total,
        ];
    }

    /**
     * @param  list<array{entry: CommissionEntry, amount: float}>  $allocations
     */
    public function persistAllocations(PayoutRequest $payoutRequest, array $allocations): void
    {
        foreach ($allocations as $item) {
            /** @var CommissionEntry $entry */
            $entry = $item['entry'];
            $amount = (float) $item['amount'];

            PayoutRequestAllocation::create([
                'payout_request_id' => $payoutRequest->id,
                'commission_entry_id' => $entry->id,
                'amount' => $amount,
            ]);

            $entry->update([
                'status' => CommissionEntry::STATUS_RESERVED,
                'payout_request_id' => $payoutRequest->id,
            ]);
        }
    }

    /**
     * @param  Collection<int, PayoutRequestAllocation>  $allocations
     */
    public function confirmAllocations(PayoutRequest $payoutRequest, Collection $allocations): void
    {
        foreach ($allocations as $allocation) {
            $entry = $allocation->commissionEntry;
            if (! $entry) {
                continue;
            }

            $newPaid = round((float) $entry->amount_paid + (float) $allocation->amount, 2);
            $fullyPaid = $newPaid >= (float) $entry->commission_amount - 0.001;

            $entry->update([
                'amount_paid' => $newPaid,
                'status' => $fullyPaid ? CommissionEntry::STATUS_PAID : CommissionEntry::STATUS_AVAILABLE,
                'paid_at' => $fullyPaid ? now() : $entry->paid_at,
                'payout_request_id' => $fullyPaid ? $payoutRequest->id : null,
            ]);
        }
    }

    public function releaseReservation(PayoutRequest $payoutRequest): void
    {
        DB::transaction(function () use ($payoutRequest) {
            $entries = CommissionEntry::query()
                ->where('payout_request_id', $payoutRequest->id)
                ->where('status', CommissionEntry::STATUS_RESERVED)
                ->lockForUpdate()
                ->get();

            foreach ($entries as $entry) {
                $entry->update([
                    'status' => CommissionEntry::STATUS_AVAILABLE,
                    'payout_request_id' => null,
                ]);
            }
        });
    }
}
