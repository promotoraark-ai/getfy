<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SapcepagWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $event = $request->input('event');
        $transactionId = $request->input('transaction_id');
        $status = $request->input('status');

        if (empty($transactionId) || ! is_string($transactionId)) {
            return response()->json(['message' => 'transaction_id required'], 400);
        }

        $order = Order::where('gateway', 'sapcepag')->where('gateway_id', $transactionId)->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if (! $this->verifyWebhookSignature('sapcepag', $order->tenant_id, $request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        ProcessPaymentWebhook::dispatchSync('sapcepag', $transactionId, (string) $event, (string) $status, $request->all());

        return response()->json(['received' => true]);
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
        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $signature);
    }
}
