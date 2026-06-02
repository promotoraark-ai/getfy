<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_AWAITING_PAYOUT = 'awaiting_payout';

    public const BUCKET_PIX = 'pix';

    public const BUCKET_CARD = 'card';

    public const BUCKET_BOLETO = 'boleto';

    protected $fillable = [
        'uuid',
        'idempotency_key',
        'user_id',
        'tenant_id',
        'wallet_bucket',
        'amount_cents',
        'status',
        'pix_key',
        'pix_key_type',
        'pix_owner_document',
        'cajupay_payout_id',
        'cajupay_response',
        'failure_reason',
        'requested_ip',
        'requested_at',
        'completed_at',
        'cajupay_status',
        'approved_by_user_id',
        'approved_at',
        'rejected_by_user_id',
        'rejected_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'cajupay_response' => 'array',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, [
            self::STATUS_PROCESSING,
            self::STATUS_AWAITING_PAYOUT,
            self::STATUS_PENDING_APPROVAL,
        ], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PayoutRequestAllocation::class);
    }

    public function commissionEntries(): HasMany
    {
        return $this->hasMany(CommissionEntry::class);
    }
}
