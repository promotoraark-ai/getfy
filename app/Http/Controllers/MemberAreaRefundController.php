<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberAreaRefundController extends Controller
{
    public function __construct(
        protected RefundService $refundService
    ) {}

    public function eligibility(Request $request): JsonResponse
    {
        $product = $this->productFromRequest($request);
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        return response()->json($this->refundService->eligibility($product, $user));
    }

    public function store(Request $request): JsonResponse
    {
        $product = $this->productFromRequest($request);
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $refundRequest = $this->refundService->submitRequest($product, $user, $validated['reason']);

        return response()->json([
            'success' => true,
            'message' => $refundRequest->status === 'processing'
                ? 'Solicitação enviada. O reembolso está sendo processado.'
                : 'Solicitação enviada. Aguarde a análise da equipe.',
            'request' => [
                'id' => $refundRequest->id,
                'status' => $refundRequest->status,
                'status_label' => \App\Models\RefundRequest::statusLabel($refundRequest->status),
            ],
        ]);
    }

    private function productFromRequest(Request $request): Product
    {
        $product = $request->route('product') ?? $request->attributes->get('member_area_product');
        if (! $product instanceof Product) {
            abort(404);
        }

        return $product;
    }
}
