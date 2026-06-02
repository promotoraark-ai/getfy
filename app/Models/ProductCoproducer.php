<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCoproducer extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const PAYOUT_INTERNAL = 'internal';

    public const PAYOUT_CAJUPAY_SPLIT = 'cajupay_split';

    protected $fillable = [
        'product_id',
        'user_id',
        'email',
        'invite_token',
        'status',
        'commission_percent',
        'duration_days',
        'starts_at',
        'ends_at',
        'commission_on_producer_sales',
        'commission_on_affiliate_sales',
        'settlement_days_pix',
        'settlement_days_card',
        'settlement_days_boleto',
        'cajupay_split_id',
        'payout_method',
        'invite_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:4',
            'duration_days' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'commission_on_producer_sales' => 'boolean',
            'commission_on_affiliate_sales' => 'boolean',
            'settlement_days_pix' => 'integer',
            'settlement_days_card' => 'integer',
            'settlement_days_boleto' => 'integer',
            'invite_expires_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }

        return true;
    }

    public function usesCajupaySplitPayout(): bool
    {
        return $this->payout_method === self::PAYOUT_CAJUPAY_SPLIT
            && filled($this->cajupay_split_id);
    }

    public function settlementDaysForMethod(string $method, ProductAffiliateProgram $program): int
    {
        $key = match ($method) {
            'pix', 'pix_auto' => 'settlement_days_pix',
            'boleto' => 'settlement_days_boleto',
            default => 'settlement_days_card',
        };
        if ($this->{$key} !== null) {
            return (int) $this->{$key};
        }

        return $program->settlementDaysForMethod($method);
    }
}
