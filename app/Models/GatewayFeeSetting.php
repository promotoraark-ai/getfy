<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayFeeSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'gateway_slug',
        'method',
        'percent',
        'fixed_cents',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:4',
            'fixed_cents' => 'integer',
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
    }
}
