<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpacepagWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        if ($this->isLegacyPayload($payload)) {
            return $this->handleLegacy($request, $payload);
        }

        if (! isset($payload['type'], $payload['data']) || ! is_array($payload['data'])) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $type = (string) $payload['type'];
        $data = $payload['data'];

        $candidates = $this->gatewayIdCandidatesFromData($data);
        if ($candidates === []) {
            return response()->json(['message' => 'transaction id required'], 400);
        }

        $order = Order::where('gateway', 'spacepag')
            ->whereIn('gateway_id', $candidates)
            ->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if (! $this->verifyWebhookSignature('spacepag', $order->tenant_id, $request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $normalized = $this->normalizeNewWebhook($type, $data);
        if ($normalized === null) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        ProcessPaymentWebhook::dispatchSync(
            'spacepag',
            (string) $order->gateway_id,
            $normalized['event'],
            $normalized['status'],
            $payload
        );

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isLegacyPayload(array $payload): bool
    {
        if (! isset($payload['transaction_id']) || ! is_string($payload['transaction_id']) || $payload['transaction_id'] === '') {
            return false;
        }

        return isset($payload['event'], $payload['status'])
            && (! isset($payload['type']) || ! isset($payload['data']));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleLegacy(Request $request, array $payload): JsonResponse
    {
        $event = $payload['event'];
        $transactionId = $payload['transaction_id'];
        $status = $payload['status'];

        if (! is_string($transactionId) || $transactionId === '') {
            return response()->json(['message' => 'transaction_id required'], 400);
        }

        $order = Order::where('gateway', 'spacepag')->where('gateway_id', $transactionId)->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if (! $this->verifyWebhookSignature('spacepag', $order->tenant_id, $request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        ProcessPaymentWebhook::dispatchSync('spacepag', $transactionId, (string) $event, (string) $status, $payload);

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function gatewayIdCandidatesFromData(array $data): array
    {
        $out = [];
        foreach (['transactionId', 'id'] as $key) {
            $v = $data[$key] ?? null;
            if (is_string($v)) {
                $v = trim($v);
                if ($v !== '') {
                    $out[] = $v;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{event: string, status: string}|null
     */
    private function normalizeNewWebhook(string $type, array $data): ?array
    {
        $statusUpper = strtoupper((string) ($data['status'] ?? ''));
        $internal = strtolower((string) ($data['internalStatus'] ?? ''));

        switch ($type) {
            case 'pix.in.confirmation':
                if (in_array($statusUpper, ['APPROVED', 'PAID'], true) || $internal === 'approved') {
                    return ['event' => 'order.paid', 'status' => 'paid'];
                }

                return null;
            case 'pix.in.expired':
                return ['event' => 'order.cancelled', 'status' => 'cancelled'];
            case 'pix.in.failed':
                return ['event' => 'order.rejected', 'status' => 'rejected'];
            case 'pix.in.reversal.confirmation':
                return ['event' => 'order.refunded', 'status' => 'refunded'];
            default:
                return null;
        }
    }

    /**
     * Verifica assinatura HMAC do body (header X-Webhook-Signature: sha256=hex). Se webhook_secret estiver configurado, exige match.
     */
    private function verifyWebhookSignature(string $gatewaySlug, ?int $tenantId, Request $request): bool
    {
        $credential = GatewayCredential::forTenant($tenantId)
            ->where('gateway_slug', $gatewaySlug)
            ->where('is_connected', true)
            ->first();
        if (! $credential) {
            return true;
        }
        $credentials = $credential->getDecryptedCredentials();
        $secret = $credentials['webhook_secret'] ?? null;
        if ($secret === null || $secret === '') {
            return true;
        }
        $signature = $request->header('X-Webhook-Signature') ?? $request->header('X-Signature');
        if (! is_string($signature) || $signature === '') {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
