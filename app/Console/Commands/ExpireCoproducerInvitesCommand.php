<?php

namespace App\Console\Commands;

use App\Models\ProductCoproducer;
use Illuminate\Console\Command;

class ExpireCoproducerInvitesCommand extends Command
{
    protected $signature = 'coproducers:expire-invites';

    protected $description = 'Mark expired co-producer invites';

    public function handle(): int
    {
        $count = ProductCoproducer::query()
            ->where('status', ProductCoproducer::STATUS_PENDING)
            ->whereNotNull('invite_expires_at')
            ->where('invite_expires_at', '<', now())
            ->update(['status' => ProductCoproducer::STATUS_EXPIRED]);

        $this->info("Expired {$count} invites.");

        return self::SUCCESS;
    }
}
