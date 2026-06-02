<?php

namespace App\Console\Commands;

use App\Models\PayoutRequest;
use App\Services\PayoutService;
use Illuminate\Console\Command;

class ReconcilePayoutsCommand extends Command
{
    protected $signature = 'payouts:reconcile {--limit=50}';

    protected $description = 'Sync payout status with CajuPay for in-flight withdrawals';

    public function handle(PayoutService $payoutService): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $payouts = PayoutRequest::query()
            ->whereIn('status', [
                PayoutRequest::STATUS_PROCESSING,
                PayoutRequest::STATUS_AWAITING_PAYOUT,
            ])
            ->whereNotNull('cajupay_payout_id')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $updated = 0;

        foreach ($payouts as $payout) {
            if ($payoutService->reconcilePayout($payout)) {
                $updated++;
            }
        }

        $this->info("Reconciled {$updated} of {$payouts->count()} payout(s).");

        return self::SUCCESS;
    }
}
