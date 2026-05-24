<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Support\MetaPurchaseTracking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaConversionsApiService
{
    private const GRAPH_VERSION = 'v21.0';

    /**
     * Envia Purchase (e opcionalmente um segundo evento só para order bumps) via CAPI.
     */
    public function sendPurchaseForCompletedOrder(Order $order): void
    {
        $order->refresh();
        if ($order->status !== 'completed') {
            return;
        }

        $order->syncUtmMetadataFromCheckoutSession();
        $order->refresh();

        $lockKey = 'meta_capi_purchase_sent:' . $order->id;
        if (\Illuminate\Support\Facades\Cache::has($lockKey)) {
            return;
        }

        $pixels = $order->resolvedConversionPixels();
        $metaBlock = is_array($pixels['meta'] ?? null) ? $pixels['meta'] : [];
        if (empty($metaBlock['enabled'])) {
            return;
        }

        $entries = Product::normalizeConversionPixelBlock($metaBlock, 'meta')['entries'] ?? [];
        if ($entries === []) {
            return;
        }

        $currency = $order->getCurrencyOrDefault();
        $eventTime = $order->updated_at?->timestamp ?? time();
        $sentAny = false;

        try {
            foreach ($entries as $entry) {
                $pixelId = trim((string) ($entry['pixel_id'] ?? ''));
                $token = trim((string) ($entry['access_token'] ?? ''));
                if ($pixelId === '' && $token === '') {
                    continue;
                }
                if ($pixelId !== '' && $token === '') {
                    Log::warning('Meta CAPI: pixel sem access_token (CAPI não enviado). Configure o token em Produto > Pixels > Meta.', [
                        'order_id' => $order->id,
                        'pixel_id' => $pixelId,
                    ]);
                    continue;
                }
                if ($pixelId === '') {
                    continue;
                }

                $excludeBumps = ! empty($entry['disable_order_bump_events']);
                $contents = MetaPurchaseTracking::purchaseContentsFromOrder($order, $excludeBumps);
                $value = round((float) $order->lineItemsTotalAmount(), 2);
                if ($excludeBumps && $contents !== []) {
                    $value = round(array_sum(array_map(
                        fn ($c) => (float) ($c['item_price'] ?? 0) * (int) ($c['quantity'] ?? 1),
                        $contents
                    )), 2);
                }
                if ($value <= 0) {
                    $value = round((float) $order->amount, 2);
                }
                $contentIds = array_values(array_unique(array_map(fn ($c) => (string) ($c['id'] ?? ''), $contents)));

                $userData = $this->buildUserData($order);
                $customData = [
                    'value' => $value,
                    'currency' => $currency,
                    'content_ids' => $contentIds,
                    'contents' => $contents,
                    'num_items' => count($contents) > 0 ? count($contents) : 1,
                ];

                $this->postEvents($pixelId, $token, [[
                    'event_name' => 'Purchase',
                    'event_time' => $eventTime,
                    'event_id' => MetaPurchaseTracking::purchaseEventId($order->id),
                    'action_source' => 'website',
                    'user_data' => $userData,
                    'custom_data' => $customData,
                ]]);
                $sentAny = true;
            }

            if ($sentAny) {
                \Illuminate\Support\Facades\Cache::put($lockKey, 1, now()->addDays(30));
            }
        } catch (\Throwable $e) {
            Log::warning('Meta CAPI: envio interrompido; nova tentativa pela fila.', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function postEvents(string $pixelId, string $accessToken, array $events): void
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events',
            self::GRAPH_VERSION,
            rawurlencode($pixelId)
        );

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->asJson()
                ->post($url . '?access_token=' . rawurlencode($accessToken), [
                    'data' => $events,
                ]);

            if (! $response->successful()) {
                Log::warning('Meta CAPI: resposta não OK.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('Meta CAPI HTTP ' . $response->status());
            }
        } catch (\Throwable $e) {
            Log::warning('Meta CAPI: falha.', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUserData(Order $order): array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];

        $out = [];

        $em = $this->hashEmail($order->email ?? null);
        if ($em !== null) {
            $out['em'] = [$em];
        }

        $ph = $this->hashPhone($order->phone ?? null);
        if ($ph !== null) {
            $out['ph'] = [$ph];
        }

        if (! empty($order->cpf)) {
            $doc = preg_replace('/\D/', '', (string) $order->cpf);
            if (strlen($doc) >= 11) {
                $out['external_id'] = hash('sha256', $doc);
            }
        }

        $fbc = isset($meta['fbc']) && is_string($meta['fbc']) ? trim($meta['fbc']) : '';
        if ($fbc !== '') {
            $out['fbc'] = mb_substr($fbc, 0, 512);
        }

        $fbp = isset($meta['fbp']) && is_string($meta['fbp']) ? trim($meta['fbp']) : '';
        if ($fbp !== '') {
            $out['fbp'] = mb_substr($fbp, 0, 512);
        }

        if (! empty($order->customer_ip) && filter_var($order->customer_ip, FILTER_VALIDATE_IP)) {
            $out['client_ip_address'] = $order->customer_ip;
        }

        return $out;
    }

    private function hashEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return hash('sha256', strtolower(trim($email)));
    }

    private function hashPhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === null || strlen($digits) < 10) {
            return null;
        }
        if (! str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }

        return hash('sha256', $digits);
    }
}
