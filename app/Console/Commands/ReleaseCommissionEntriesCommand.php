<?php

namespace App\Console\Commands;

use App\Models\CommissionEntry;
use Illuminate\Console\Command;

class ReleaseCommissionEntriesCommand extends Command
{
    protected $signature = 'commissions:release';

    protected $description = 'Move commission entries from pending to available when settlement date is reached';

    public function handle(): int
    {
        $updated = CommissionEntry::query()
            ->where('status', CommissionEntry::STATUS_PENDING)
            ->where('role', '!=', CommissionEntry::ROLE_PRODUTOR)
            ->whereNotNull('available_at')
            ->where('available_at', '<=', now())
            ->update(['status' => CommissionEntry::STATUS_AVAILABLE]);

        $this->info("Released {$updated} commission entries.");

        return self::SUCCESS;
    }
}
