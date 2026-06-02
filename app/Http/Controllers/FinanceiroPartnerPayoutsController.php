<?php

namespace App\Http\Controllers;

use App\Models\PayoutRequest;
use App\Services\PartnerPayoutApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceiroPartnerPayoutsController extends Controller
{
    public function __construct(
        private readonly PartnerPayoutApprovalService $approvalService,
    ) {}

    public function approve(Request $request, PayoutRequest $payout): JsonResponse
    {
        $result = $this->approvalService->approve($request->user(), $payout);

        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'Saque aprovado e enviado.',
            'payout_request' => [
                'id' => $result['payout_request']->id,
                'status' => $result['payout_request']->status,
                'amount' => $result['payout_request']->amount_cents / 100,
            ],
        ]);
    }

    public function reject(Request $request, PayoutRequest $payout): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->approvalService->reject($request->user(), $payout, $validated['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Saque rejeitado.',
            'payout_request' => [
                'id' => $updated->id,
                'status' => $updated->status,
            ],
        ]);
    }
}
