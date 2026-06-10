<?php

namespace App\Gateways;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GatewayRegistry
{
    /**
     * Registro de gateways permitidos: apenas Asaas e CajuPay.
     * @var array<string, array<string, mixed>>
     */
    private static array $gateways = [];

    /**
     * Drivers instanciados.
     * @var array<string, GatewayDriver>
     */
    private static array $drivers = [];

    /**
     * Inicializa o registro com configuração centralizada.
     */
    public static function boot(): void
    {
        $allowedGateways = config('ark_gateways.allowed_gateways', ['asaas', 'cajupay']);
        $gateways = config('gateways.gateways', []);

        self::$gateways = [];

        foreach ($allowedGateways as $slug) {
            if (!isset($gateways[$slug])) {
                Log::warning('GatewayRegistry: gateway '.$slug.' não encontrado em config/gateways.php');
                continue;
            }

            $gateway = $gateways[$slug];
            
            // Injeta signup_url centralizada
            if ($slug === 'asaas') {
                $gateway['signup_url'] = config('ark_gateways.asaas.signup_url', 'https://www.asaas.com');
            } elseif ($slug === 'cajupay') {
                $gateway['signup_url'] = config('ark_gateways.cajupay.signup_url', 'https://cajupay.com.br');
            }

            self::$gateways[$slug] = $gateway;
        }
    }

    /**
     * Obtém definição de gateway por slug.
     * @return array<string, mixed>|null
     */
    public static function get(string $slug): ?array
    {
        if (empty(self::$gateways)) {
            self::boot();
        }

        if (!in_array($slug, config('ark_gateways.allowed_gateways', ['asaas', 'cajupay']), true)) {
            Log::warning('GatewayRegistry: acesso negado ao gateway '.$slug.' (não está na whitelist)');
            return null;
        }

        return self::$gateways[$slug] ?? null;
    }

    /**
     * Lista todos os gateways permitidos.
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (empty(self::$gateways)) {
            self::boot();
        }

        return array_values(self::$gateways);
    }

    /**
     * Obtém driver instanciado para um gateway.
     */
    public static function driver(string $slug): ?GatewayDriver
    {
        if (!in_array($slug, config('ark_gateways.allowed_gateways', ['asaas', 'cajupay']), true)) {
            Log::warning('GatewayRegistry: driver não permitido para gateway '.$slug);
            return null;
        }

        if (isset(self::$drivers[$slug])) {
            return self::$drivers[$slug];
        }

        $gateway = self::get($slug);
        if (!$gateway) {
            return null;
        }

        $driverClass = $gateway['driver'] ?? null;
        if (!$driverClass || !class_exists($driverClass)) {
            Log::error('GatewayRegistry: driver class não encontrada', ['slug' => $slug, 'class' => $driverClass]);
            return null;
        }

        try {
            self::$drivers[$slug] = new $driverClass();
        } catch (\Throwable $e) {
            Log::error('GatewayRegistry: falha ao instanciar driver', ['slug' => $slug, 'error' => $e->getMessage()]);
            return null;
        }

        return self::$drivers[$slug];
    }

    /**
     * Registra um novo gateway (para plugins, se necessário).
     * Bloqueado: apenas Asaas e CajuPay são permitidos em ArkGateway.
     */
    public static function register(string $slug, array $definition): void
    {
        throw new RuntimeException('ArkGateway: registro de novos gateways não é permitido. Apenas Asaas e CajuPay.');
    }

    /**
     * Resolve URL da imagem de um gateway.
     */
    public static function resolveImageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return asset($path);
    }
}
