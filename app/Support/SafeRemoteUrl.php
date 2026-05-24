<?php

namespace App\Support;

class SafeRemoteUrl
{
    /**
     * Valida URL HTTPS pública para fetch server-side (evita SSRF).
     */
    public static function isAllowedHttpUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isAllowedIp($host);
        }

        foreach (self::blockedHostnames() as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.'.$blocked)) {
                return false;
            }
        }

        $allowedHosts = self::allowedHosts();
        foreach ($allowedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function allowedHosts(): array
    {
        $hosts = ['r2.getfy.cloud'];

        $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = strtolower($appHost);
        }

        $awsUrl = (string) config('filesystems.disks.s3.url', '');
        if ($awsUrl === '') {
            $awsUrl = (string) env('AWS_URL', '');
        }
        if ($awsUrl !== '' && str_starts_with($awsUrl, 'http')) {
            $h = parse_url($awsUrl, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $hosts[] = strtolower($h);
            }
        }

        $csv = (string) config('csp.extra_connect_src', '');
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $h = parse_url($part, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $hosts[] = strtolower($h);
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * @return list<string>
     */
    private static function blockedHostnames(): array
    {
        return ['localhost', 'metadata.google.internal'];
    }

    private static function isAllowedIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (in_array($ip, ['127.0.0.1', '0.0.0.0', '::1'], true)) {
            return false;
        }

        if (str_starts_with($ip, '169.254.')) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
