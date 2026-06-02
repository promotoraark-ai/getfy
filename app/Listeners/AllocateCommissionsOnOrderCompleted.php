<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\CommissionAllocator;

class AllocateCommissionsOnOrderCompleted
{
    public function __construct(
        private readonly CommissionAllocator $allocator,
    ) {}

    public function handle(OrderCompleted $event): void
    {
        $this->allocator->allocateForCompletedOrder($event->order);
    }
}
