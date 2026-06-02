<?php

namespace App\Support;

use App\Models\Order;
use App\Models\ProductAffiliateProgram;
use App\Models\User;

class PartnerBuyerPresentation
{
    /**
     * @return array{name: ?string, email: ?string, phone: ?string, cpf: ?string, masked: bool}
     */
    public static function forOrder(Order $order, ?bool $shareBuyerData = null): array
    {
        if ($shareBuyerData === null) {
            $shareBuyerData = self::programSharesBuyerData($order->product_id);
        }

        $order->loadMissing('user:id,name,email');

        $name = $order->user?->name;
        $email = $order->email ?: $order->user?->email;
        $phone = $order->phone;
        $cpf = $order->cpf;

        if ($shareBuyerData) {
            return [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'cpf' => $cpf,
                'masked' => false,
            ];
        }

        return [
            'name' => self::maskName($name),
            'email' => self::maskEmail($email),
            'phone' => self::maskPhone($phone),
            'cpf' => self::maskCpf($cpf),
            'masked' => true,
        ];
    }

    public static function programSharesBuyerData(?string $productId): bool
    {
        if (! $productId) {
            return false;
        }

        return (bool) ProductAffiliateProgram::query()
            ->where('product_id', $productId)
            ->value('share_buyer_data');
    }

    public static function maskName(?string $name): ?string
    {
        $name = trim((string) ($name ?? ''));
        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $name) ?: [];
        $masked = array_map(function (string $part) {
            $len = mb_strlen($part);
            if ($len <= 1) {
                return $part.'***';
            }

            return mb_substr($part, 0, 1).'***';
        }, $parts);

        return implode(' ', $masked);
    }

    public static function maskEmail(?string $email): ?string
    {
        $email = trim((string) ($email ?? ''));
        if ($email === '' || ! str_contains($email, '@')) {
            return $email !== '' ? '***@***' : null;
        }

        [$local, $domain] = explode('@', $email, 2);
        $localMask = mb_substr($local, 0, 1).'***';
        $domainParts = explode('.', $domain);
        $domainName = $domainParts[0] ?? $domain;
        $domainMask = mb_substr($domainName, 0, 1).'***';
        $tld = count($domainParts) > 1 ? '.'.implode('.', array_slice($domainParts, 1)) : '';

        return $localMask.'@'.$domainMask.$tld;
    }

    public static function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($phone ?? ''));
        if ($digits === '') {
            return null;
        }

        $last = mb_substr($digits, -4);

        return '(***) *****-'.$last;
    }

    public static function maskCpf(?string $cpf): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($cpf ?? ''));
        if (strlen($digits) < 4) {
            return '***.***.***-**';
        }

        return '***.***.***-'.substr($digits, -2);
    }
}
