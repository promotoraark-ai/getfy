<?php

namespace App\Services;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundService
{
    public function __construct(
        protected CajuPayDriver $cajuPayDriver
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function eligibility(Product $product, User $user): array
    {
        $config = $product->memberAreaRefundConfig();
        $existing = RefundRequest::query()
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        $base = [
            'enabled' => $config['enabled'],
            'mode' => $config['mode'],
            'days' => $config['days'],
            'can_request' => false,
            'reason_code' => null,
            'message' => null,
            'days_remaining' => null,
            'existing_request' => $existing ? $this->formatRequestForMember($existing) : null,
            'order_id' => null,
        ];

        if (! $config['enabled']) {
            $base['reason_code'] = 'disabled';
            $base['message'] = 'Reembolso não disponível para este produto.';

            return $base;
        }

        if ($existing && $existing->isOpen()) {
            $base['reason_code'] = 'pending_request';
            $base['message'] = 'Você já possui uma solicitação de reembolso em andamento.';
            $base['can_request'] = false;

            return $base;
        }

        if ($existing && $existing->status === RefundRequest::STATUS_COMPLETED) {
            $base['reason_code'] = 'already_refunded';
            $base['message'] = 'Este pedido já foi reembolsado.';
            $base['can_request'] = false;

            return $base;
        }

        $order = $this->findEligibleOrder($product, $user);
        if (! $order) {
            $base['reason_code'] = 'no_order';
            $base['message'] = 'Nenhum pedido elegível encontrado.';

            return $base;
        }

        $base['order_id'] = $order->id;

        if ($order->status === 'refunded') {
            $base['reason_code'] = 'order_refunded';
            $base['message'] = 'Este pedido já foi reembolsado.';

            return $base;
        }

        if ($order->status !== 'completed') {
            $base['reason_code'] = 'order_not_completed';
            $base['message'] = 'Apenas pedidos pagos podem ser reembolsados.';

            return $base;
        }

        $accessStart = $this->accessStartAt($product, $user);
        $deadline = $accessStart->copy()->addDays($config['days']);
        $daysRemaining = (int) max(0, now()->startOfDay()->diffInDays($deadline->endOfDay(), false));
        $base['days_remaining'] = $daysRemaining;

        if (now()->greaterThan($deadline)) {
            $base['reason_code'] = 'deadline_passed';
            $base['message'] = 'O prazo para solicitar reembolso expirou.';

            return $base;
        }

        $base['can_request'] = true;
        $base['reason_code'] = 'eligible';
        $base['message'] = null;

        return $base;
    }

    public function submitRequest(Product $product, User $user, string $reason): RefundRequest
    {
        $eligibility = $this->eligibility($product, $user);
        if (empty($eligibility['can_request'])) {
            throw ValidationException::withMessages([
                'reason' => [$eligibility['message'] ?? 'Não é possível solicitar reembolso no momento.'],
            ]);
        }

        $order = $this->findEligibleOrder($product, $user);
        if (! $order || $order->status !== 'completed') {
            throw ValidationException::withMessages([
                'reason' => ['Pedido não encontrado ou não elegível.'],
            ]);
        }

        $config = $product->memberAreaRefundConfig();
        $mode = $config['mode'];
        $canAutoPix = $mode === RefundRequest::MODE_AUTO && $this->canExecuteCajuPayPixRefund($order);

        $clientRefundId = 'getfy-order-'.$order->id.'-refund';
        $paymentId = $this->cajuPayDriver->resolvePaymentIdForOrder($order);

        $request = RefundRequest::create([
            'tenant_id' => $product->tenant_id,
            'order_id' => $order->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'reason' => $reason,
            'status' => $canAutoPix ? RefundRequest::STATUS_PROCESSING : RefundRequest::STATUS_PENDING,
            'mode' => $mode,
            'gateway' => $order->gateway,
            'cajupay_payment_id' => $paymentId,
            'client_refund_id' => $clientRefundId,
        ]);

        if ($canAutoPix) {
            try {
                $this->executeCajuPayPixRefund($request);
            } catch (\Throwable $e) {
                $request->update([
                    'status' => RefundRequest::STATUS_PENDING,
                    'failure_reason' => $e->getMessage(),
                ]);
            }
        }

        return $request->fresh();
    }

    public function approve(RefundRequest $refundRequest, User $admin, ?string $adminNotes = null): RefundRequest
    {
        if ($refundRequest->status !== RefundRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Esta solicitação não está pendente de aprovação.'],
            ]);
        }

        $refundRequest->update([
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => $adminNotes,
            'status' => RefundRequest::STATUS_PROCESSING,
        ]);

        $order = $refundRequest->order;
        if ($order && $this->canExecuteCajuPayPixRefund($order)) {
            try {
                $this->executeCajuPayPixRefund($refundRequest);
            } catch (\Throwable $e) {
                $refundRequest->update([
                    'status' => RefundRequest::STATUS_PENDING,
                    'failure_reason' => $e->getMessage(),
                ]);
                throw ValidationException::withMessages([
                    'gateway' => [$e->getMessage()],
                ]);
            }
        } else {
            $refundRequest->update([
                'status' => RefundRequest::STATUS_PROCESSING,
                'admin_notes' => trim(($adminNotes ?? '').' Aguardando confirmação do gateway (estorno manual se necessário).'),
            ]);
        }

        return $refundRequest->fresh();
    }

    public function reject(RefundRequest $refundRequest, User $admin, ?string $adminNotes = null): RefundRequest
    {
        if (! in_array($refundRequest->status, [RefundRequest::STATUS_PENDING, RefundRequest::STATUS_PROCESSING], true)) {
            throw ValidationException::withMessages([
                'status' => ['Esta solicitação não pode ser rejeitada.'],
            ]);
        }

        $refundRequest->update([
            'status' => RefundRequest::STATUS_REJECTED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_notes' => $adminNotes,
        ]);

        return $refundRequest->fresh();
    }

    public function executeCajuPayPixRefund(RefundRequest $refundRequest): void
    {
        $order = $refundRequest->order;
        if (! $order || ! $this->canExecuteCajuPayPixRefund($order)) {
            throw new \RuntimeException('Reembolso automático via API disponível apenas para PIX CajuPay.');
        }

        $paymentId = $refundRequest->cajupay_payment_id
            ?: $this->cajuPayDriver->resolvePaymentIdForOrder($order);
        if (! $paymentId) {
            throw new \RuntimeException('Não foi possível identificar o payment_id CajuPay deste pedido.');
        }

        $credential = GatewayCredential::forTenant($order->tenant_id)
            ->where('gateway_slug', 'cajupay')
            ->where('is_connected', true)
            ->first();
        if (! $credential) {
            throw new \RuntimeException('Credenciais CajuPay não configuradas para este tenant.');
        }

        $credentials = $credential->getDecryptedCredentials();
        $clientRefundId = $refundRequest->client_refund_id ?: ('getfy-order-'.$order->id.'-refund');
        $response = $this->cajuPayDriver->createPixRefund($paymentId, $credentials, $clientRefundId);

        $refundRequest->update([
            'status' => RefundRequest::STATUS_PROCESSING,
            'cajupay_payment_id' => $paymentId,
            'cajupay_refund_id' => is_string($response['id'] ?? null) ? $response['id'] : ($refundRequest->cajupay_refund_id),
            'client_refund_id' => $clientRefundId,
            'gateway_response' => $response,
        ]);

        $meta = $order->metadata ?? [];
        $meta['cajupay_payment_id'] = $paymentId;
        $order->update(['metadata' => $meta]);
    }

    public function markCompletedFromWebhook(Order $order, ?string $cajupayRefundId = null): void
    {
        $refundRequest = RefundRequest::query()->where('order_id', $order->id)->first();
        if ($refundRequest && $refundRequest->status !== RefundRequest::STATUS_COMPLETED) {
            $refundRequest->update([
                'status' => RefundRequest::STATUS_COMPLETED,
                'completed_at' => now(),
                'cajupay_refund_id' => $cajupayRefundId ?: $refundRequest->cajupay_refund_id,
            ]);
        }
    }

    public function persistCajuPayPaymentId(Order $order, ?string $paymentId): void
    {
        if (! is_string($paymentId) || $paymentId === '') {
            return;
        }
        $meta = $order->metadata ?? [];
        if (($meta['cajupay_payment_id'] ?? null) === $paymentId) {
            return;
        }
        $meta['cajupay_payment_id'] = $paymentId;
        $order->update(['metadata' => $meta]);
    }

    public function canExecuteCajuPayPixRefund(Order $order): bool
    {
        return $order->gateway === 'cajupay' && $order->isCajuPayPixPayment();
    }

    private function findEligibleOrder(Product $product, User $user): ?Order
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->whereIn('status', ['completed', 'refunded'])
            ->latest('id')
            ->first();
    }

    private function accessStartAt(Product $product, User $user): Carbon
    {
        $createdAt = DB::table('product_user')
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->value('created_at');

        if ($createdAt) {
            return Carbon::parse($createdAt);
        }

        $order = Order::query()
            ->where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->where('status', 'completed')
            ->oldest('id')
            ->first();

        return $order?->updated_at ?? now();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRequestForMember(RefundRequest $request): array
    {
        return [
            'id' => $request->id,
            'status' => $request->status,
            'status_label' => RefundRequest::statusLabel($request->status),
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }
}
