<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\RefundRequest;
use App\Services\RefundService;
use App\Services\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ReembolsosController extends Controller
{
    public function __construct(
        protected RefundService $refundService,
        protected TeamAccessService $teamAccess
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $query = RefundRequest::query()
            ->with(['user:id,name,email', 'product:id,name', 'order:id,amount,currency,status,gateway'])
            ->where('tenant_id', $tenantId)
            ->latest('id');

        $status = trim((string) $request->query('status', ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $productId = trim((string) $request->query('product_id', ''));
        if ($productId !== '') {
            $query->where('product_id', $productId);
        }

        $allowedProductIds = $this->teamAccess->allowedProductIdsFor($user);
        if ($user->isTeam() && $allowedProductIds !== []) {
            $query->whereIn('product_id', $allowedProductIds);
        }

        $requests = $query->paginate(20)->withQueryString();

        $products = Product::forTenant($tenantId)
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($user->isTeam() && $allowedProductIds !== []) {
            $products = $products->whereIn('id', $allowedProductIds)->values();
        }

        return Inertia::render('Reembolsos/Index', [
            'requests' => $requests->through(fn (RefundRequest $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'status_label' => RefundRequest::statusLabel($r->status),
                'mode' => $r->mode,
                'reason' => $r->reason,
                'gateway' => $r->gateway,
                'failure_reason' => $r->failure_reason,
                'admin_notes' => $r->admin_notes,
                'created_at' => $r->created_at?->toIso8601String(),
                'can_approve' => $r->status === RefundRequest::STATUS_PENDING,
                'can_reject' => in_array($r->status, [RefundRequest::STATUS_PENDING, RefundRequest::STATUS_PROCESSING], true),
                'needs_manual_gateway' => $r->status === RefundRequest::STATUS_PROCESSING
                    && $r->order
                    && ! $r->order->isCajuPayPixPayment(),
                'user' => $r->user ? [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                    'email' => $r->user->email,
                ] : null,
                'product' => $r->product ? [
                    'id' => $r->product->id,
                    'name' => $r->product->name,
                ] : null,
                'order' => $r->order ? [
                    'id' => $r->order->id,
                    'amount' => (float) $r->order->amount,
                    'currency' => $r->order->currency ?? 'BRL',
                    'status' => $r->order->status,
                    'gateway' => $r->order->gateway,
                    'payment_label' => $r->order->paymentMethodDisplayLabel(),
                ] : null,
            ]),
            'filters' => [
                'status' => $status !== '' ? $status : 'all',
                'product_id' => $productId,
            ],
            'products' => $products->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'status_options' => [
                ['value' => 'all', 'label' => 'Todos'],
                ['value' => RefundRequest::STATUS_PENDING, 'label' => 'Pendentes'],
                ['value' => RefundRequest::STATUS_PROCESSING, 'label' => 'Processando'],
                ['value' => RefundRequest::STATUS_COMPLETED, 'label' => 'Concluídos'],
                ['value' => RefundRequest::STATUS_REJECTED, 'label' => 'Rejeitados'],
                ['value' => RefundRequest::STATUS_FAILED, 'label' => 'Falhou'],
            ],
            'can_manage' => $user->isAdmin() || $user->isInfoprodutor() || $this->teamAccess->can($user, 'reembolsos.manage'),
        ]);
    }

    public function approve(Request $request, RefundRequest $refundRequest): RedirectResponse
    {
        $this->authorizeRefundRequest($request, $refundRequest);

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->refundService->approve($refundRequest, $request->user(), $validated['admin_notes'] ?? null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Solicitação aprovada. O reembolso está em processamento.');
    }

    public function reject(Request $request, RefundRequest $refundRequest): RedirectResponse
    {
        $this->authorizeRefundRequest($request, $refundRequest);

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->refundService->reject($refundRequest, $request->user(), $validated['admin_notes'] ?? null);

        return back()->with('success', 'Solicitação rejeitada.');
    }

    private function authorizeRefundRequest(Request $request, RefundRequest $refundRequest): void
    {
        $user = $request->user();
        if ($refundRequest->tenant_id !== $user->tenant_id) {
            abort(403);
        }
        if (! $user->isAdmin() && ! $user->isInfoprodutor() && ! $this->teamAccess->can($user, 'reembolsos.manage')) {
            abort(403);
        }
        if ($user->isTeam()) {
            $allowed = $this->teamAccess->allowedProductIdsFor($user);
            if ($allowed !== [] && ! in_array($refundRequest->product_id, $allowed, true)) {
                abort(403);
            }
        }
    }
}
