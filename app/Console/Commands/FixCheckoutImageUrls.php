<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Migra URLs absolutas (http://APP_URL_HOST/storage/...) salvas no checkout_config
 * de produtos para URLs relativas (/storage/...). Necessário quando o ambiente
 * passou a ser servido por outro host (ngrok HTTPS local, mudança de domínio
 * em produção, etc.) e as imagens antigas continuam apontando pro host antigo.
 */
class FixCheckoutImageUrls extends Command
{
    protected $signature = 'checkout:fix-image-urls
                            {--from= : Host de origem a substituir (default: parse de APP_URL)}
                            {--dry-run : Mostra o que seria alterado sem persistir}';

    protected $description = 'Converte URLs absolutas no checkout_config para URLs relativas (/storage/...).';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        if ($from === '') {
            $appUrl = (string) env('APP_URL', '');
            $host = parse_url($appUrl, PHP_URL_HOST);
            if (! $host) {
                $this->error('Não consegui extrair host de APP_URL. Use --from=getfy-opensource.test');
                return self::FAILURE;
            }
            $from = $host;
        }

        $patterns = [
            'http://' . $from,
            'https://' . $from,
        ];

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[DRY-RUN] ' : '') . 'Migrando URLs com host "' . $from . '" → relativas (/storage/...)');

        $count = 0;
        Product::query()->whereNotNull('checkout_config')->chunkById(100, function ($products) use ($patterns, $dryRun, &$count) {
            foreach ($products as $product) {
                $config = $product->checkout_config;
                if (! is_array($config)) continue;

                $original = $config;
                $config = $this->rewriteRecursively($config, $patterns);

                if ($config !== $original) {
                    $count++;
                    $this->line(' - product #' . $product->id . ' (' . $product->name . ')');
                    if (! $dryRun) {
                        $product->update(['checkout_config' => $config]);
                    }
                }
            }
        });

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . 'Concluído. Produtos afetados: ' . $count);
        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<int, string>  $patterns
     * @return array<string, mixed>
     */
    private function rewriteRecursively(array $value, array $patterns): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->rewriteRecursively($v, $patterns);
                continue;
            }
            if (! is_string($v) || $v === '') continue;
            foreach ($patterns as $prefix) {
                if (str_starts_with($v, $prefix . '/storage/')) {
                    $value[$k] = substr($v, strlen($prefix));
                    break;
                }
            }
        }
        return $value;
    }
}
