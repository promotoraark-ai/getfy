<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAffiliate extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'product_id',
        'user_id',
        'affiliate_code',
        'commission_percent',
        'status',
        'affiliate_pixels',
        'settlement_days_pix',
        'settlement_days_card',
        'settlement_days_boleto',
        'cajupay_split_id',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:4',
            'affiliate_pixels' => 'array',
            'settlement_days_pix' => 'integer',
            'settlement_days_card' => 'integer',
            'settlement_days_boleto' => 'integer',
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

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function effectiveCommissionPercent(ProductAffiliateProgram $program): float
    {
        if ($this->commission_percent !== null) {
            return (float) $this->commission_percent;
        }

        return (float) $program->default_commission_percent;
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
