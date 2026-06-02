<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionEntry extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SETTLED_EXTERNALLY = 'settled_externally';

    public const STATUS_RESERVED = 'reserved';

    public const ROLE_COPRODUTOR = 'coprodutor';

    public const ROLE_AFILIADO = 'afiliado';

    public const ROLE_PRODUTOR = 'produtor';

    protected $fillable = [
        'order_id',
        'tenant_id',
        'beneficiary_user_id',
        'role',
        'gross_amount',
        'gateway_fee_amount',
        'net_amount',
        'commission_percent',
        'commission_amount',
        'amount_paid',
        'status',
        'payment_method',
        'available_at',
        'paid_at',
        'payout_request_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'gateway_fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'commission_percent' => 'decimal:4',
            'commission_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'available_at' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(PayoutRequest::class);
    }

    public function remainingAmount(): float
    {
        return max(0, round((float) $this->commission_amount - (float) $this->amount_paid, 2));
    }
}
