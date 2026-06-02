<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'type',
        'source',
        'amount',
        'description',
        'commission_entry_id',
        'cajupay_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commissionEntry(): BelongsTo
    {
        return $this->belongsTo(CommissionEntry::class);
    }
}
