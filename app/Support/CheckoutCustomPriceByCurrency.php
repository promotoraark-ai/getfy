<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;

class CheckoutCustomPriceByCurrency
{
    /**
     * @param  array<int, array{code?: string, rate_to_brl?: float|int|string}>  $tenantCurrencies
     */
    public static function amountBrlFromCustomIfApplicable(
        Product $product,
        ?ProductOffer $offer,
        ?SubscriptionPlan $plan,
        float $amountBrl,
        string $displayCurrency,
        array $tenantCurrencies
    ): float {
        $cfg = $product->checkout_config['custom_prices_by_currency'] ?? [];
        if (! is_array($cfg) || empty($cfg['enabled'])) {
            return $amountBrl;
        }
        if ($offer !== null || $plan !== null) {
            return $amountBrl;
        }
        $code = strtoupper(trim($displayCurrency));
        if ($code === '' || $code === 'BRL') {
            return $amountBrl;
        }
        $amounts = $cfg['amounts'] ?? [];
        if (! is_array($amounts) || ! isset($amounts[$code])) {
            return $amountBrl;
        }
        $custom = (float) $amounts[$code];
        if ($custom <= 0) {
            return $amountBrl;
        }
        $rate = self::rateToBrlForCode($tenantCurrencies, $code);
        if ($rate <= 0) {
            return $amountBrl;
        }

        return round($custom / $rate, 2);
    }

    /**
     * Valor na moeda de cobrança (Caju Global): preço customizado na moeda é usado direto;
     * caso contrário converte o valor em BRL pela taxa rate_to_brl.
     *
     * @param  array<int, array{code?: string, rate_to_brl?: float|int|string}>  $tenantCurrencies
     */
    public static function amountInChargeCurrency(
        Product $product,
        ?ProductOffer $offer,
        ?SubscriptionPlan $plan,
        float $amountBrl,
        string $chargeCurrency,
        array $tenantCurrencies
    ): float {
        $code = strtoupper(trim($chargeCurrency));
        if ($code === '' || $code === 'BRL') {
            return round($amountBrl, MoneyMinorUnits::isZeroDecimal('BRL') ? 0 : 2);
        }

        $custom = self::customAmountForCurrency($product, $offer, $plan, $code);
        if ($custom !== null && $custom > 0) {
            return self::roundForCurrency($custom, $code);
        }

        $rate = self::rateToBrlForCode($tenantCurrencies, $code);
        if ($rate <= 0) {
            return round($amountBrl, 2);
        }

        return self::roundForCurrency($amountBrl * $rate, $code);
    }

    /**
     * Converte valor de bump (sempre armazenado em BRL) para a moeda de cobrança.
     *
     * @param  array<int, array{code?: string, rate_to_brl?: float|int|string}>  $tenantCurrencies
     */
    public static function bumpBrlToChargeCurrency(float $amountBrl, string $chargeCurrency, array $tenantCurrencies): float
    {
        $code = strtoupper(trim($chargeCurrency));
        if ($code === '' || $code === 'BRL') {
            return round($amountBrl, 2);
        }

        $rate = self::rateToBrlForCode($tenantCurrencies, $code);
        if ($rate <= 0) {
            return round($amountBrl, 2);
        }

        return self::roundForCurrency($amountBrl * $rate, $code);
    }

    private static function customAmountForCurrency(
        Product $product,
        ?ProductOffer $offer,
        ?SubscriptionPlan $plan,
        string $code
    ): ?float {
        $cfg = $product->checkout_config['custom_prices_by_currency'] ?? [];
        if (! is_array($cfg) || empty($cfg['enabled'])) {
            return null;
        }
        if ($offer !== null || $plan !== null) {
            return null;
        }
        $amounts = $cfg['amounts'] ?? [];
        if (! is_array($amounts) || ! isset($amounts[$code])) {
            return null;
        }
        $custom = (float) $amounts[$code];

        return $custom > 0 ? $custom : null;
    }

    private static function roundForCurrency(float $amount, string $currency): float
    {
        if (MoneyMinorUnits::isZeroDecimal($currency)) {
            return (float) max(1, (int) round($amount));
        }

        return round($amount, 2);
    }

    /**
     * @param  array<int, array{code?: string, rate_to_brl?: float|int|string}>  $tenantCurrencies
     */
    public static function rateToBrlForCode(array $tenantCurrencies, string $code): float
    {
        foreach ($tenantCurrencies as $row) {
            if (! is_array($row)) {
                continue;
            }
            $c = isset($row['code']) ? strtoupper(trim((string) $row['code'])) : '';
            if ($c === $code) {
                return max(0.0, (float) ($row['rate_to_brl'] ?? 0));
            }
        }

        return 0.0;
    }

    /**
     * @param  array<int, array{code?: string}>  $tenantCurrencies
     * @return list<string>
     */
    public static function currencyCodesFromTenantSettings(array $tenantCurrencies): array
    {
        $out = [];
        foreach ($tenantCurrencies as $row) {
            if (! is_array($row)) {
                continue;
            }
            $c = isset($row['code']) ? strtoupper(trim((string) $row['code'])) : '';
            if ($c !== '') {
                $out[] = $c;
            }
        }
        if (! in_array('BRL', $out, true)) {
            array_unshift($out, 'BRL');
        }

        return array_values(array_unique($out));
    }
}
