<?php

namespace App\Services;

use App\Models\GatewayFeeSetting;
use App\Models\Order;

class NetAmountCalculator
{
    /**
     * @return array{gross: float, fee: float, net: float}
     */
    public function forOrder(Order $order): array
    {
        $gross = round($order->lineItemsTotalAmount(), 2);
        $method = $order->checkoutPaymentMethod();
        $gateway = strtolower((string) ($order->gateway ?? ''));
        $tenantId = (int) $order->tenant_id;

        $fee = $this->estimateFee($tenantId, $gateway, $method, $gross);
        $net = max(0, round($gross - $fee, 2));

        return [
            'gross' => $gross,
            'fee' => $fee,
            'net' => $net,
        ];
    }

    public function estimateFee(int $tenantId, string $gatewaySlug, string $method, float $gross): float
    {
        $setting = null;
        if ($gatewaySlug !== '') {
            $setting = GatewayFeeSetting::forTenant($tenantId)
                ->where('gateway_slug', $gatewaySlug)
                ->where('method', $method)
                ->first();
        }

        if ($setting) {
            $percent = (float) $setting->percent;
            $fixed = ((int) $setting->fixed_cents) / 100;

            return round(($gross * $percent / 100) + $fixed, 2);
        }

        $defaults = config('commissions.default_gateway_fees', []);
        $cfg = $defaults[$method] ?? $defaults['pix'] ?? ['percent' => 0, 'fixed_cents' => 0];
        $percent = (float) ($cfg['percent'] ?? 0);
        $fixed = ((int) ($cfg['fixed_cents'] ?? 0)) / 100;

        return round(($gross * $percent / 100) + $fixed, 2);
    }
}
