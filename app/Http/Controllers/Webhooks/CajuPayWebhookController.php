<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CajuPayWebhookController extends Controller
{
    private const SLUG = 'cajupay';

    /**
     * POST /webhooks/gateways/cajupay — webhooks outbound da CajuPay assinados com HMAC SHA256.
     *
     * Cabeçalhos esperados:
     *  - X-CajuPay-Event       (ex.: checkout.payment.paid)
     *  - X-CajuPay-Event-Id    (mesmo valor do id no envelope)
     *  - X-CajuPay-Timestamp   (unix segundos)
     *  - X-CajuPay-Signature   (formato t=<unix>,v1=<hex_hmac>)
     *
     * Assinatura: HMAC_SHA256(signing_secret, timestamp + "." + raw_body)
     *
     * Multi-tenant: testamos a assinatura contra cada credencial CajuPay com
     * webhook_signing_secret salvo até casar — assim o mesmo endpoint público
     * serve todos os tenants do Getfy.
     */
    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $payload = $request->all();

        $eventType = (string) ($request->header('X-CajuPay-Event') ?? ($payload['type'] ?? ''));
        $signatureHeader = (string) ($request->header('X-CajuPay-Signature') ?? '');
        $timestampHeader = (string) ($request->header('X-CajuPay-Timestamp') ?? '');

        $sigParts = $this->parseSignatureHeader($signatureHeader);
        $signatureTs = $sigParts['t'] ?? $timestampHeader;
        $signatureHex = $sigParts['v1'] ?? '';

        // Tolerância anti-replay: 5 minutos.
        if ($signatureTs !== '' && is_numeric($signatureTs)) {
            $age = abs(time() - (int) $signatureTs);
            if ($age > 300) {
                Log::warning('CajuPayWebhook: timestamp fora da janela', ['age_seconds' => $age]);
                return response()->json(['message' => 'Stale timestamp'], 401);
            }
        }

        $object = $this->extractObject($payload);
        $sessionId = $this->pickSessionId($object);
        $chargeId = $this->pickChargeId($object);

        $order = null;
        if (is_string($sessionId) && $sessionId !== '') {
            $order = Order::where('gateway', self::SLUG)
                ->where('gateway_id', $sessionId)
                ->first();
            if (! $order) {
                $order = Order::where('gateway', self::SLUG)
                    ->where('metadata->cajupay_checkout_session_id', $sessionId)
                    ->first();
            }
        }
        if (! $order && is_string($chargeId) && $chargeId !== '') {
            $order = Order::where('gateway', self::SLUG)
                ->where('gateway_id', $chargeId)
                ->first();
        }
        if (! $order && is_string($chargeId) && $chargeId !== '') {
            $order = Order::where('gateway', self::SLUG)
                ->where('metadata->cajupay_payment_id', $chargeId)
                ->first();
        }
        if (! $order && is_array($object)) {
            $clientRefundId = $object['client_refund_id'] ?? null;
            if (is_string($clientRefundId) && $clientRefundId !== '') {
                $refundRequest = RefundRequest::query()
                    ->where('client_refund_id', $clientRefundId)
                    ->first();
                if ($refundRequest) {
                    $order = $refundRequest->order;
                }
            }
        }

        if (! $order) {
            Log::debug('CajuPayWebhook: order not found', [
                'event' => $eventType,
                'session_id' => $sessionId,
                'charge_id' => $chargeId,
            ]);
            return response()->json(['received' => true]);
        }

        // Verificação HMAC: percorre as credenciais CajuPay do tenant da order
        // (e, em fallback, todos os tenants — útil quando a order ainda não foi
        // associada). Aceita o primeiro signing_secret que casar.
        $signingSecret = $this->resolveSigningSecret($raw, $signatureTs, $signatureHex, $order->tenant_id);
        if ($signingSecret === null) {
            Log::warning('CajuPayWebhook: assinatura inválida ou sem signing_secret', [
                'event' => $eventType,
                'order_id' => $order->id,
            ]);
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Atualiza gateway_id para o charge_id real quando ainda estiver com session_id.
        if (is_string($chargeId) && $chargeId !== '' && $order->gateway_id !== $chargeId) {
            try {
                $order->update(['gateway_id' => $chargeId]);
            } catch (\Throwable $e) {
                Log::debug('CajuPayWebhook: falha ao atualizar gateway_id', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (is_string($chargeId) && $chargeId !== '') {
            app(RefundService::class)->persistCajuPayPaymentId($order, $chargeId);
        }

        $dispatchChargeId = (string) ($chargeId ?: $order->gateway_id ?: $sessionId);
        $refundId = is_array($object) && is_string($object['refund_id'] ?? null) ? $object['refund_id'] : null;

        switch ($eventType) {
            case 'checkout.payment.paid':
            case 'card.payment.succeeded':
                ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchChargeId, 'order.paid', 'paid', array_merge(
                    is_array($payload) ? $payload : [],
                    ['webhook_source' => 'cajupay_hmac_verified']
                ));
                break;
            case 'checkout.payment.failed':
            case 'card.payment.failed':
                ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchChargeId, 'order.rejected', 'rejected', array_merge(
                    is_array($payload) ? $payload : [],
                    ['webhook_source' => 'cajupay_hmac_verified']
                ));
                break;
            case 'checkout.payment.refunded':
            case 'card.payment.refunded':
            case 'pix.payment.refunded':
                if ($refundId) {
                    RefundRequest::query()
                        ->where('order_id', $order->id)
                        ->whereIn('status', [RefundRequest::STATUS_PENDING, RefundRequest::STATUS_PROCESSING])
                        ->update(['cajupay_refund_id' => $refundId]);
                }
                ProcessPaymentWebhook::dispatchSync(self::SLUG, $dispatchChargeId, 'order.refunded', 'refunded', array_merge(
                    is_array($payload) ? $payload : [],
                    ['webhook_source' => 'cajupay_hmac_verified', 'cajupay_refund_id' => $refundId]
                ));
                break;
            case 'checkout.payment.disputed':
            case 'card.payment.disputed':
                Log::info('CajuPayWebhook: disputa recebida', [
                    'order_id' => $order->id,
                    'charge_id' => $dispatchChargeId,
                ]);
                break;
            default:
                Log::debug('CajuPayWebhook: tipo não tratado', ['event' => $eventType]);
                break;
        }

        return response()->json(['received' => true]);
    }

    /**
     * Parse "t=<unix>,v1=<hex>" tolerando espaços e ordem livre.
     *
     * @return array<string, string>
     */
    private function parseSignatureHeader(string $header): array
    {
        $out = [];
        if ($header === '') {
            return $out;
        }
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            $out[strtolower(trim($kv[0]))] = trim($kv[1]);
        }
        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function extractObject(array $payload): ?array
    {
        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            $object = $data['object'] ?? null;
            if (is_array($object)) {
                return $object;
            }

            return $data;
        }
        if (isset($payload['object']) && is_array($payload['object'])) {
            return $payload['object'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $object
     */
    private function pickSessionId(?array $object): ?string
    {
        if ($object === null) {
            return null;
        }
        foreach (['checkout_session_id', 'checkout_sessionId', 'session_id'] as $k) {
            $v = $object[$k] ?? null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $object
     */
    private function pickChargeId(?array $object): ?string
    {
        if ($object === null) {
            return null;
        }
        foreach (['cajupay_charge_id', 'charge_id', 'payment_id'] as $k) {
            $v = $object[$k] ?? null;
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * Devolve o signing_secret que valida a assinatura, ou null se nenhuma credencial casar.
     */
    private function resolveSigningSecret(string $rawBody, string $timestamp, string $expectedHex, ?int $preferTenantId): ?string
    {
        if ($expectedHex === '' || $timestamp === '') {
            return null;
        }
        $payloadToSign = $timestamp . '.' . $rawBody;

        $query = GatewayCredential::query()->where('gateway_slug', self::SLUG);
        if ($preferTenantId !== null) {
            $query->where('tenant_id', $preferTenantId);
        }
        $candidates = $query->get();

        // Fallback: se não casou no tenant da order, procura em todos.
        if ($candidates->isEmpty() && $preferTenantId !== null) {
            $candidates = GatewayCredential::where('gateway_slug', self::SLUG)->get();
        }

        foreach ($candidates as $cred) {
            $creds = $cred->getDecryptedCredentials();
            $secret = is_array($creds) ? trim((string) ($creds['webhook_signing_secret'] ?? '')) : '';
            if ($secret === '') {
                continue;
            }
            $computed = hash_hmac('sha256', $payloadToSign, $secret, false);
            if (hash_equals(strtolower($computed), strtolower($expectedHex))) {
                return $secret;
            }
        }
        return null;
    }
}
