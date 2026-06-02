<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductAffiliateProgram extends Model
{
    protected $fillable = [
        'product_id',
        'enabled',
        'default_commission_percent',
        'manual_approval',
        'share_buyer_data',
        'public_slug',
        'support_email',
        'description',
        'settlement_days_pix',
        'settlement_days_card',
        'settlement_days_boleto',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'default_commission_percent' => 'decimal:4',
            'manual_approval' => 'boolean',
            'share_buyer_data' => 'boolean',
            'settlement_days_pix' => 'integer',
            'settlement_days_card' => 'integer',
            'settlement_days_boleto' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function affiliates(): HasMany
    {
        return $this->hasMany(ProductAffiliate::class, 'product_id', 'product_id');
    }

    public function settlementDaysForMethod(string $method): int
    {
        $key = match ($method) {
            'pix', 'pix_auto' => 'settlement_days_pix',
            'boleto' => 'settlement_days_boleto',
            default => 'settlement_days_card',
        };

        return (int) ($this->{$key} ?? config("commissions.default_settlement_days.{$method}", 0));
    }
}
