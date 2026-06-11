<?php

namespace App\Support;

/**
 * Centraliza regras de acesso, splits e configurações de gateways.
 * Usado por controllers, drivers e serviços.
 */
class GatewayConfig
{
    /**
     * Valida se gateway está na whitelist.
     */
    public static function isAllowed(string $gateway): bool
    {
        return in_array($gateway, config('ark_gateways.allowed_gateways', []), true);
    }

    /**
     * Retorna gateways permitidos.
     */
    public static function allowedGateways(): array
    {
        return config('ark_gateways.allowed_gateways', []);
    }

    /**
     * Retorna link signup para gateway.
     */
    public static function getSignupUrl(string $gateway): ?string
    {
        return config("ark_gateways.signup_links.{$gateway}");
    }

    /**
     * Constrói split Asaas (PIX, BOLETO, CREDIT_CARD).
     * Retorna walletId e percentage (em centavos para PIX/BOLETO, percentual para CREDIT_CARD).
     */
    public static function buildAsaasSplit(string $billingType): array
    {
        $walletId = config('ark_gateways.asaas_wallet_id');
        $splits = config('ark_gateways.asaas_splits', []);

        $amount = 0;
        if ($billingType === 'PIX') {
            $amount = $splits['pix'] ?? 0.50;
        } elseif ($billingType === 'BOLETO') {
            $amount = $splits['boleto'] ?? 1.00;
        } elseif ($billingType === 'CREDIT_CARD') {
            $amount = $splits['card'] ?? 0.017;
        }

        return [
            'walletId' => $walletId,
            'amount' => $amount,
        ];
    }

    /**
     * Retorna split_id CajuPay (fixo).
     */
    public static function getCajuPaySplitId(): string
    {
        return config('ark_gateways.cajupay_split_id', '');
    }
}
