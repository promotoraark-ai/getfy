<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class GatewayCredential extends Model
{
    protected $fillable = [
        'tenant_id',
        'gateway_slug',
        'credentials',
        'is_connected',
    ];

    protected function casts(): array
    {
        return [
            'is_connected' => 'boolean',
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Get decrypted credentials array. Never expose to serialization/API.
     *
     * @return array<string, string>
     */
    public function getDecryptedCredentials(): array
    {
        if (empty($this->credentials)) {
            return [];
        }
        try {
            $decrypted = Crypt::decryptString($this->credentials);
            $decoded = json_decode($decrypted, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            Log::warning('GatewayCredential getDecryptedCredentials failed', [
                'gateway_slug' => $this->gateway_slug,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Set credentials from array (will be encrypted).
     *
     * @param  array<string, string>  $credentials
     */
    public function setEncryptedCredentials(array $credentials): void
    {
        $this->credentials = Crypt::encryptString(json_encode($credentials));
    }
}
