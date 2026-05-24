<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProofDocument extends Model
{
    protected $table = 'proof_documents';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'user_id',
        'product_id',
        'public_code',
        'public_hash',
        'payload_snapshot',
        'generated_by_user_id',
        'generated_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_snapshot' => 'array',
            'generated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}

