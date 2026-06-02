<?php

namespace App\Support;

/**
 * Maps Getfy checkout locale keys to CajuPay BCP-47 tags (POST /api/sdk/v1/checkout/sessions).
 */
final class CajuPayLocale
{
    /** @var array<string, string> */
    private const MAP = [
        'pt_BR' => 'pt-BR',
        'en' => 'en',
        'es' => 'es',
    ];

    public static function fromCheckoutLocale(?string $locale): string
    {
        $key = trim((string) $locale);
        $tag = self::MAP[$key] ?? self::MAP['pt_BR'];

        return mb_substr($tag, 0, 16);
    }
}
