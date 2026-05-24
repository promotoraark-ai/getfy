<?php

namespace App\Gateways;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

class GatewayRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $custom = [];

    /**
     * Register a gateway (e.g. from a plugin). Merges with core gateways from config.
     *
     * @param  array{slug: string, name: string, image: string, methods: array, scope: string, signup_url: string, driver: string, credential_keys: array}  $gateway
     */
    public static function register(array $gateway): void
    {
        $slug = $gateway['slug'] ?? null;
        if ($slug && is_string($slug)) {
            self::$custom[$slug] = $gateway;
        }
    }

    /**
     * All available gateways (config + custom from plugins).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $fromConfig = config('gateways.gateways', []);
        $merged = array_merge($fromConfig, self::$custom);

        return array_values(array_map(function ($def, $slug) {
            $def['slug'] = $def['slug'] ?? $slug;
            return $def;
        }, $merged, array_keys($merged)));
    }

    /**
     * Get a single gateway definition by slug.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $slug): ?array
    {
        foreach (self::all() as $gateway) {
            if (($gateway['slug'] ?? '') === $slug) {
                return $gateway;
            }
        }
        return null;
    }

    /**
     * Get driver instance for a gateway slug.
     */
    public static function driver(string $slug): ?Contracts\GatewayDriver
    {
        $def = self::get($slug);
        if (!$def || empty($def['driver'])) {
            return null;
        }
        $class = $def['driver'];
        if (!is_string($class) || !class_exists($class)) {
            return null;
        }
        $instance = app($class);
        return $instance instanceof Contracts\GatewayDriver ? $instance : null;
    }

    /**
     * Resolve gateway image URL. Se a imagem for "plugin:{slug}/{path}", retorna a URL da rota de assets do plugin.
     * Plugins podem colocar a imagem em plugins/{slug}/assets/{path} e usar image => 'plugin:slug/path'.
     */
    public static function resolveImageUrl(?string $image): ?string
    {
        if ($image === null || $image === '') {
            return null;
        }
        if (str_starts_with($image, 'plugin:')) {
            $rest = substr($image, 7);
            $slash = strpos($rest, '/');
            if ($slash === false) {
                return null;
            }
            $pluginSlug = substr($rest, 0, $slash);
            $path = substr($rest, $slash + 1);
            $path = str_replace(['../', '..\\'], '', $path);
            if ($path === '' || preg_match('/\\.\\./', $path)) {
                return null;
            }
            if (Route::has('plugins.asset')) {
                return URL::route('plugins.asset', ['slug' => $pluginSlug, 'path' => $path]);
            }
            return null;
        }
        return $image;
    }
}
