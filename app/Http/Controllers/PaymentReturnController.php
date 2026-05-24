<?php

namespace App\Http\Controllers;

use App\Models\ApiCheckoutSession;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Endpoint de retorno genérico para gateways que exigem return_url
 * (ex.: Stripe em fluxos com possibilidade de redirect/3DS) quando o
 * tenant não configurou um default_return_url próprio.
 *
 * Mantém a página leve e somente leitura: apenas exibe o status do
 * pedido e um link para a URL configurada do tenant, se houver.
 */
class PaymentReturnController extends Controller
{
    public function show(int $order): Response|RedirectResponse
    {
        if ($order <= 0) {
            return redirect('/')->with('error', 'Pedido inválido.');
        }

        $orderModel = Order::with('apiApplication')->find($order);
        if (! $orderModel) {
            return redirect('/')->with('error', 'Pedido inválido.');
        }

        $tenantReturnUrl = $orderModel->apiApplication?->default_return_url;
        $tenantReturnUrl = is_string($tenantReturnUrl) ? trim($tenantReturnUrl) : '';
        if ($tenantReturnUrl !== '' && ! filter_var($tenantReturnUrl, FILTER_VALIDATE_URL)) {
            $tenantReturnUrl = '';
        }

        $currency = 'BRL';
        if ($orderModel->api_checkout_session_id) {
            $session = ApiCheckoutSession::find($orderModel->api_checkout_session_id);
            $currency = $session?->currency ?: 'BRL';
        }

        return Inertia::render('Payments/Return', [
            'order_id' => $orderModel->id,
            'order_status' => (string) ($orderModel->status ?? 'pending'),
            'order_amount' => (float) ($orderModel->amount ?? 0),
            'order_currency' => strtoupper((string) $currency),
            'tenant_return_url' => $tenantReturnUrl ?: null,
        ]);
    }
}
