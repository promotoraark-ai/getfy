<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ProofDocument;
use App\Services\ProofOfDeliveryService;
use App\Services\ProofPdfRenderer;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use setasign\Fpdi\Fpdi;

class ProofDocumentsController extends Controller
{
    public function __construct(
        protected ProofOfDeliveryService $proofService,
        protected ProofPdfRenderer $pdfRenderer,
    ) {}

    public function show(Request $request, Order $order): InertiaResponse
    {
        $order->loadMissing(['user', 'product', 'productOffer', 'subscriptionPlan', 'orderItems.product']);

        $doc = ProofDocument::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        $snapshot = null;
        if ($doc && is_array($doc->payload_snapshot)) {
            $snapshot = $doc->payload_snapshot;
        } else {
            // Preview (does not persist). Generation will persist a stable code/hash.
            $snapshot = $this->proofService->buildSnapshot($order, 'PREVIEW', now());
        }

        return Inertia::render('Vendas/ProofDocument', [
            'order' => [
                'id' => $order->id,
                'status' => $order->status,
                'amount' => (float) $order->amount,
                'gateway' => $order->gateway,
                'gateway_id' => $order->gateway_id,
                'created_at' => $order->created_at?->toIso8601String(),
                'customer_ip' => $order->customer_ip,
                'buyer' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'email' => $order->user->email,
                ] : null,
                'product' => $order->product ? [
                    'id' => $order->product->id,
                    'name' => $order->product->name,
                    'checkout_slug' => $order->product->checkout_slug ?? null,
                    'type' => $order->product->type,
                ] : null,
            ],
            'proof_document' => $doc ? [
                'id' => $doc->id,
                'public_code' => $doc->public_code,
                'generated_at' => $doc->generated_at?->toIso8601String(),
                'revoked_at' => $doc->revoked_at?->toIso8601String(),
                'verify_url' => $doc->public_code ? url('/verify/' . $doc->public_code) : null,
            ] : null,
            'snapshot' => $snapshot,
        ]);
    }

    public function generate(Request $request, Order $order): RedirectResponse
    {
        $generatedBy = $request->user();
        $this->proofService->issueForOrder($order, $generatedBy);

        return redirect()->back()->with('success', 'Dossiê gerado/atualizado com sucesso.');
    }

    public function pdf(Request $request, Order $order): Response
    {
        $order->loadMissing(['user', 'product', 'productOffer', 'subscriptionPlan', 'orderItems.product']);

        $doc = ProofDocument::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        if (! $doc) {
            $doc = $this->proofService->issueForOrder($order, $request->user());
        }
        $pdf = new Fpdi('P', 'mm', 'A4');
        $this->pdfRenderer->renderOrderDossier($pdf, $order, $doc);

        $content = $pdf->Output('S');
        $filename = 'dossie-comprovacao-pedido-' . $order->id . '.pdf';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}

