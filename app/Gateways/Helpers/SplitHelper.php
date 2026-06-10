<?php

namespace App\Gateways\Helpers;

use App\Support\MoneyMinorUnits;

class SplitHelper
{
    /**
     * Constrói split da Asaas baseado no método de pagamento.
     * Retorna array com walletId e valores de tarifa, ou null se não aplicável.
     *
     * @return array{wallet_id: string, amount_cents?: int, percentage?: float}|null
     */
    public static function buildAsaasPlatformSplit(string $billingType): ?array
    {
        $config = config('ark_gateways.asaas', []);
        $walletId = $config['wallet_id'] ?? null;

        if (!$walletId) {
            return null;
        }

        $splits = $config['splits'] ?? [];
        $split = $splits[strtolower($billingType)] ?? null;

        if (!$split) {
            return null;
        }

        $result = ['wallet_id' => $walletId];

        if ($split['type'] === 'fixed_amount' && isset($split['value_cents'])) {
            $result['amount_cents'] = (int) $split['value_cents'];
        } elseif ($split['type'] === 'percentage' && isset($split['value'])) {
            $result['percentage'] = (float) $split['value'];
        }

        return $result;
    }

    /**
     * Obtém split_id da CajuPay para qualquer método de pagamento.
     * Todos os métodos (PIX, Card, Apple Pay, Google Pay) usam o mesmo split_id.
     */
    public static function getCajuPaySplitId(): ?string
    {
        return config('ark_gateways.cajupay.split_id');
    }

    /**
     * Valida e injeta split_id no body de criação de sessão CajuPay.
     *
     * @param array<string, mixed> $body Body da requisição (será modificado)
     * @param string|null $method Método de pagamento (pix, card, apple_pay, google_pay)
     */
    public static function injectCajuPaySplitIntoSession(array &$body, ?string $method = null): void
    {
        $splitId = self::getCajuPaySplitId();
        if ($splitId) {
            $body['split_id'] = $splitId;
        }
    }

    /**
     * Calcula tarifa da Asaas (Pix ou Boleto) e retorna em centavos.
     * Para cartão, retorna null (percentual aplicado pela Asaas automaticamente).
     */
    public static function calculateAsaasFee(float $amountBrl, string $billingType): ?int
    {
        $split = self::buildAsaasPlatformSplit($billingType);
        if (!$split) {
            return null;
        }

        if (isset($split['amount_cents'])) {
            return $split['amount_cents'];
        }

        if (isset($split['percentage'])) {
            $amountCents = MoneyMinorUnits::toMinorUnits($amountBrl, 'BRL');
            $feeCents = (int) round(($amountCents * $split['percentage']) / 100);
            return max(1, $feeCents);
        }

        return null;
    }
}
