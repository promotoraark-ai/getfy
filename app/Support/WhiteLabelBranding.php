<?php

namespace App\Support;

use App\Plugins\PluginRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Aplica branding do plugin White Label em config('getfy.*') por request.
 */
class WhiteLabelBranding
{
    /** @var list<string> */
    private const CONFIG_KEYS = [
        'app_name',
        'theme_primary',
        'pwa_theme_color',
        'app_logo',
        'app_logo_dark',
        'app_logo_icon',
        'app_logo_icon_dark',
        'login_hero_image',
        'favicon_url',
        'pwa_icon',
        'pwa_icon_192',
        'pwa_icon_512',
    ];

    public static function apply(?Request $request = null): void
    {
        if (! self::isPluginEnabled()) {
            return;
        }

        if (self::invokePluginApplicator($request)) {
            return;
        }

        self::applyFromDatabase($request?->user()?->tenant_id);
    }

    public static function isPluginEnabled(): bool
    {
        try {
            return collect(PluginRegistry::enabled())
                ->contains(fn ($p) => ($p['slug'] ?? null) === 'white-label');
        } catch (\Throwable) {
            return false;
        }
    }

    private static function invokePluginApplicator(?Request $request): bool
    {
        if (! class_exists(\Plugins\WhiteLabel\ApplyWhiteLabelConfig::class)) {
            return false;
        }

        $class = \Plugins\WhiteLabel\ApplyWhiteLabelConfig::class;

        if ($request !== null && method_exists($class, 'applyForRequest')) {
            $class::applyForRequest($request);

            return true;
        }

        if ($request !== null && method_exists($class, 'applyForHttpRequest')) {
            $class::applyForHttpRequest($request);

            return true;
        }

        if (method_exists($class, 'apply')) {
            $tenantId = $request?->user()?->tenant_id;
            $class::apply($tenantId);

            return true;
        }

        if (method_exists($class, 'applyToConfig')) {
            $tenantId = $request?->user()?->tenant_id;
            $class::applyToConfig($tenantId);

            return true;
        }

        return false;
    }

    private static function applyFromDatabase(mixed $tenantId): void
    {
        if (! class_exists(\Plugins\WhiteLabel\WhiteLabelSetting::class)) {
            return;
        }

        try {
            if (! Schema::hasTable('white_label_settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        try {
            $global = \Plugins\WhiteLabel\WhiteLabelSetting::query()->whereNull('tenant_id')->first();
            $tenant = $tenantId !== null
                ? \Plugins\WhiteLabel\WhiteLabelSetting::query()->where('tenant_id', $tenantId)->first()
                : null;

            $globalData = is_array($global?->data) ? $global->data : [];
            $tenantData = is_array($tenant?->data) ? $tenant->data : [];

            if (class_exists(\Plugins\WhiteLabel\ApplyWhiteLabelConfig::class)) {
                $branding = \Plugins\WhiteLabel\ApplyWhiteLabelConfig::mergeLayers($globalData, $tenantData);
            } else {
                $branding = array_merge($globalData, array_filter(
                    $tenantData,
                    fn ($v) => is_string($v) ? trim($v) !== '' : $v !== null && $v !== ''
                ));
            }

            foreach (self::CONFIG_KEYS as $key) {
                if (! array_key_exists($key, $branding)) {
                    continue;
                }
                $value = $branding[$key];
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                config(["getfy.{$key}" => trim($value)]);
            }
        } catch (\Throwable) {
            // Plugin ou tabela indisponível — mantém defaults de config/getfy.php
        }
    }
}
