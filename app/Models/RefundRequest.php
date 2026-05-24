<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const MODE_AUTO = 'auto';

    public const MODE_MANUAL = 'manual';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'user_id',
        'product_id',
        'reason',
        'status',
        'mode',
        'gateway',
        'cajupay_payment_id',
        'cajupay_refund_id',
        'client_refund_id',
        'gateway_response',
        'admin_notes',
        'failure_reason',
        'reviewed_by',
        'reviewed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'gateway_response' => 'array',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ], true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_PROCESSING => 'Processando',
            self::STATUS_COMPLETED => 'Concluído',
            self::STATUS_REJECTED => 'Rejeitado',
            self::STATUS_FAILED => 'Falhou',
            self::STATUS_CANCELLED => 'Cancelado',
            default => $status,
        };
    }
}
