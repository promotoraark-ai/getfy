<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Support\WalletBucket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class WalletLedgerService
{
    /**
     * @param  list<string>|null  $roles  Filter by commission role (e.g. produtor only)
     * @return array{
     *   by_wallet: array<string, array{pending: float, available: float, reserved: float, paid_total: float}>,
     *   totals: array{pending: float, available: float, reserved: float, paid_total: float}
     * }
     */
    public function balancesForUser(User $user, ?array $roles = null): array
    {
        $byWallet = [];
        foreach (WalletBucket::keys() as $bucket) {
            $byWallet[$bucket] = [
                'pending' => 0.0,
                'available' => 0.0,
                'reserved' => 0.0,
                'paid_total' => 0.0,
            ];
        }

        $entries = $this->baseQuery($user, $roles)->get();

        foreach ($entries as $entry) {
            $bucket = WalletBucket::resolveFromPaymentMethod($entry->payment_method);
            if (! isset($byWallet[$bucket])) {
                continue;
            }

            $remaining = $entry->remainingAmount();

            if ($entry->status === CommissionEntry::STATUS_PENDING) {
                $byWallet[$bucket]['pending'] += $remaining;
            } elseif ($entry->status === CommissionEntry::STATUS_AVAILABLE) {
                $byWallet[$bucket]['available'] += $remaining;
            } elseif ($entry->status === CommissionEntry::STATUS_RESERVED) {
                $byWallet[$bucket]['reserved'] += $remaining;
            } elseif ($entry->status === CommissionEntry::STATUS_PAID) {
                $byWallet[$bucket]['paid_total'] += (float) $entry->commission_amount;
            }
        }

        $totals = ['pending' => 0.0, 'available' => 0.0, 'reserved' => 0.0, 'paid_total' => 0.0];
        foreach ($byWallet as $wallet) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $wallet[$key];
            }
        }

        foreach ($byWallet as $bucket => $amounts) {
            foreach ($amounts as $key => $value) {
                $byWallet[$bucket][$key] = round($value, 2);
            }
        }
        foreach ($totals as $key => $value) {
            $totals[$key] = round($value, 2);
        }

        return [
            'by_wallet' => $byWallet,
            'totals' => $totals,
        ];
    }

    public function availableForBucket(User $user, string $bucket, ?array $roles = null): float
    {
        $balances = $this->balancesForUser($user, $roles);

        return (float) ($balances['by_wallet'][$bucket]['available'] ?? 0);
    }

    /**
     * @param  list<string>|null  $roles
     */
    public function assertCanWithdraw(User $user, string $bucket, int $amountCents, ?array $roles = null): void
    {
        if (! WalletBucket::isValid($bucket)) {
            throw ValidationException::withMessages([
                'wallet_bucket' => 'Carteira inválida.',
            ]);
        }

        $minCents = (int) config('commissions.min_payout_cents', 100);
        if ($amountCents < $minCents) {
            throw ValidationException::withMessages([
                'amount' => 'Valor mínimo para saque: R$ '.number_format($minCents / 100, 2, ',', '.'),
            ]);
        }

        $availableCents = (int) round($this->availableForBucket($user, $bucket, $roles) * 100);
        if ($amountCents > $availableCents) {
            throw ValidationException::withMessages([
                'amount' => 'Saldo disponível insuficiente nesta carteira.',
            ]);
        }
    }

    /**
     * @param  list<string>|null  $roles
     */
    public function baseQuery(User $user, ?array $roles = null): Builder
    {
        $query = CommissionEntry::query()
            ->where('beneficiary_user_id', $user->id)
            ->whereNotIn('status', [
                CommissionEntry::STATUS_CANCELLED,
                CommissionEntry::STATUS_SETTLED_EXTERNALLY,
            ]);

        if ($roles !== null && $roles !== []) {
            $query->whereIn('role', $roles);
        }

        return $query;
    }

    /**
     * @param  list<string>|null  $roles
     * @return Builder<CommissionEntry>
     */
    public function availableEntriesQuery(User $user, string $bucket, ?array $roles = null): Builder
    {
        $methods = WalletBucket::paymentMethodsFor($bucket);

        return $this->baseQuery($user, $roles)
            ->where('status', CommissionEntry::STATUS_AVAILABLE)
            ->whereIn('payment_method', $methods)
            ->whereRaw('commission_amount > amount_paid')
            ->orderBy('available_at')
            ->orderBy('id');
    }
}
