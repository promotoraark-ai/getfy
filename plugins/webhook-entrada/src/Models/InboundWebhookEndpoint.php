<?php

namespace Plugins\WebhookEntrada\Models;

use Illuminate\Database\Eloquent\Model;

class InboundWebhookEndpoint extends Model
{
    /** Chaves de mapeamento suportadas (caminhos no JSON, dot notation). */
    public const FIELD_KEYS = ['email', 'name', 'cpf', 'phone', 'external_id'];

    /** Se true em field_map, não usa fallbacks automáticos — só os caminhos que configurar. */
    public const META_STRICT = '_strict';

    protected $table = 'inbound_webhook_endpoints';

    protected $fillable = [
        'tenant_id',
        'name',
        'is_active',
        'url_token',
        'product_id',
        'product_offer_id',
        'subscription_plan_id',
        'field_map',
        'signing_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'field_map' => 'array',
        ];
    }

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
    }

    /**
     * Normaliza field_map vindo da BD: cada campo é string (um caminho) ou array de strings (ordem de tentativa).
     * Chave reservada {@see META_STRICT}: boolean — só caminhos explícitos, sem fallbacks da aplicação.
     *
     * @return array<string, string|array<int, string>>
     */
    public function normalizedFieldMap(): array
    {
        $defaults = [
            'email' => 'email',
            'name' => 'name',
            'cpf' => 'cpf',
            'phone' => 'phone',
            'external_id' => 'external_id',
        ];

        $map = $this->field_map;
        if (! is_array($map) || $map === []) {
            return $defaults;
        }

        $out = [];
        foreach ($map as $internal => $path) {
            $k = is_string($internal) ? trim($internal) : '';
            if ($k === '' || $k === self::META_STRICT) {
                continue;
            }
            if (! in_array($k, self::FIELD_KEYS, true)) {
                continue;
            }
            $normalized = self::normalizePathValueToStoredShape($path);
            if ($normalized !== null) {
                $out[$k] = $normalized;
            }
        }

        foreach ($defaults as $k => $v) {
            if (! isset($out[$k])) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>|null
     */
    public static function sanitizeFieldMapForStorage(?array $input): ?array
    {
        if ($input === null) {
            return null;
        }

        $out = [];
        foreach ($input as $internal => $path) {
            $k = is_string($internal) ? trim($internal) : '';
            if ($k === '') {
                continue;
            }
            if ($k === self::META_STRICT) {
                $out[self::META_STRICT] = filter_var($path, FILTER_VALIDATE_BOOLEAN);

                continue;
            }
            if (! in_array($k, self::FIELD_KEYS, true)) {
                continue;
            }
            $normalized = self::normalizePathValueToStoredShape($path);
            if ($normalized !== null) {
                $out[$k] = $normalized;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @return array{strict: bool, fields: array<string, array<int, string>>}
     */
    public function getFieldResolutionConfig(): array
    {
        $raw = $this->field_map;
        $strict = is_array($raw) && array_key_exists(self::META_STRICT, $raw)
            ? filter_var($raw[self::META_STRICT], FILTER_VALIDATE_BOOLEAN)
            : false;

        $defaults = [
            'email' => ['email'],
            'name' => ['name'],
            'cpf' => ['cpf'],
            'phone' => ['phone'],
            'external_id' => ['external_id'],
        ];

        $normalized = $this->normalizedFieldMap();
        $fields = [];
        foreach (self::FIELD_KEYS as $key) {
            $v = $normalized[$key] ?? $defaults[$key];
            $fields[$key] = self::coerceToPathList($v, $defaults[$key]);
        }

        return ['strict' => $strict, 'fields' => $fields];
    }

    /**
     * @return array<int, string>
     */
    private static function coerceToPathList(string|array $value, array $fallbackIfEmpty): array
    {
        if (is_string($value)) {
            $t = trim($value);

            return $t !== '' ? [$t] : $fallbackIfEmpty;
        }
        $list = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $t = trim($item);
            if ($t !== '' && ! in_array($t, $list, true)) {
                $list[] = $t;
            }
        }

        return $list !== [] ? $list : $fallbackIfEmpty;
    }

    /**
     * @return string|array<int, string>|null null = omitir campo
     */
    private static function normalizePathValueToStoredShape(mixed $path): string|array|null
    {
        if (is_string($path)) {
            $t = trim($path);

            return $t !== '' ? $t : null;
        }
        if (! is_array($path)) {
            return null;
        }
        $list = [];
        foreach ($path as $item) {
            if (! is_string($item)) {
                continue;
            }
            $t = trim($item);
            if ($t === '' || strlen($t) > 255) {
                continue;
            }
            if (count($list) >= 40) {
                break;
            }
            if (! in_array($t, $list, true)) {
                $list[] = $t;
            }
        }

        if ($list === []) {
            return null;
        }
        if (count($list) === 1) {
            return $list[0];
        }

        return $list;
    }

    public static function generateUrlToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
