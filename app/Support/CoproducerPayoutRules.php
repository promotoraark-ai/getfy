<?php

namespace App\Support;

use App\Models\GatewayCredential;
use App\Models\Product;
use App\Models\ProductCoproducer;
use Illuminate\Validation\ValidationException;

class CoproducerPayoutRules
{
    public static function tenantHasCajupay(int $tenantId): bool
    {
        return GatewayCredential::forTenant($tenantId)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{payout_method: string, cajupay_split_id: ?string}
     */
    public static function normalizePayoutFields(Product $product, array $data): array
    {
        $method = $data['payout_method'] ?? ProductCoproducer::PAYOUT_INTERNAL;
        if (! in_array($method, [ProductCoproducer::PAYOUT_INTERNAL, ProductCoproducer::PAYOUT_CAJUPAY_SPLIT], true)) {
            throw ValidationException::withMessages([
                'payout_method' => 'Forma de repasse inválida.',
            ]);
        }

        $splitId = isset($data['cajupay_split_id']) && $data['cajupay_split_id'] !== ''
            ? trim((string) $data['cajupay_split_id'])
            : null;

        if ($method === ProductCoproducer::PAYOUT_CAJUPAY_SPLIT) {
            if (! $product->cajupay_split_payout_enabled) {
                throw ValidationException::withMessages([
                    'payout_method' => 'Ative o repasse via split CajuPay nas configurações do produto.',
                ]);
            }
            if (! self::tenantHasCajupay((int) $product->tenant_id)) {
                throw ValidationException::withMessages([
                    'payout_method' => 'Conecte o gateway CajuPay antes de usar split.',
                ]);
            }
            if (! $splitId || ! self::isValidUuid($splitId)) {
                throw ValidationException::withMessages([
                    'cajupay_split_id' => 'Informe o ID do split (UUID) criado no painel CajuPay.',
                ]);
            }
        } else {
            $splitId = null;
        }

        return [
            'payout_method' => $method,
            'cajupay_split_id' => $splitId,
        ];
    }

    public static function isValidUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }

    public static function validationRules(): array
    {
        return [
            'payout_method' => ['nullable', 'string', 'in:internal,cajupay_split'],
            'cajupay_split_id' => ['nullable', 'string', 'max:36'],
        ];
    }
}
