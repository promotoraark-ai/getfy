<?php

namespace App\Http\Controllers;

use App\Models\ProofDocument;
use App\Services\ProofOfDeliveryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicProofVerifyController extends Controller
{
    public function __construct(
        protected ProofOfDeliveryService $proofService,
    ) {}

    public function show(Request $request, string $code): Response
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 32) {
            abort(404);
        }

        $doc = ProofDocument::query()
            ->where('public_code', $code)
            ->first();

        if (! $doc) {
            abort(404);
        }

        if ($doc->revoked_at) {
            return Inertia::render('Public/VerifyProof', [
                'valid' => false,
                'status' => 'revoked',
                'code' => $code,
                'summary' => null,
            ]);
        }

        $doc->loadMissing(['order.product', 'order.user']);

        if (! $doc->order || ! $this->proofService->verifyPublicCode($code, $doc)) {
            abort(404);
        }

        $snapshot = is_array($doc->payload_snapshot) ? $doc->payload_snapshot : [];
        $summary = $this->buildMaskedSummary($snapshot);

        return Inertia::render('Public/VerifyProof', [
            'valid' => true,
            'status' => 'valid',
            'code' => $code,
            'generated_at' => $doc->generated_at?->toIso8601String(),
            'summary' => $summary,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function buildMaskedSummary(array $snapshot): array
    {
        $buyerName = (string) data_get($snapshot, 'buyer.name', '');
        $buyerEmail = (string) data_get($snapshot, 'buyer.email', '');
        $productName = (string) data_get($snapshot, 'product.name', '');
        $orderId = (string) data_get($snapshot, 'order.id', '');

        $progress = data_get($snapshot, 'access.progress', []);
        $percent = is_array($progress) ? (int) ($progress['completion_percent'] ?? 0) : 0;

        return [
            'order_id' => $orderId !== '' ? $this->maskOrderId($orderId) : null,
            'buyer_name' => $buyerName !== '' ? $this->maskName($buyerName) : null,
            'buyer_email' => $buyerEmail !== '' ? $this->maskEmail($buyerEmail) : null,
            'product_name' => $productName !== '' ? $productName : null,
            'completion_percent' => $percent,
            'first_activity_at' => data_get($snapshot, 'access.first_progress_at'),
            'last_activity_at' => data_get($snapshot, 'access.last_progress_at'),
            'order_created_at' => data_get($snapshot, 'order.created_at'),
        ];
    }

    private function maskOrderId(string $orderId): string
    {
        $digits = preg_replace('/\\D/', '', $orderId);
        if (! is_string($digits) || $digits === '') {
            return $orderId;
        }
        $tail = substr($digits, -4);
        return '***' . $tail;
    }

    private function maskName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\\s+/', $name) ?: [$name];
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
        $masked = [];
        foreach ($parts as $i => $p) {
            $p = (string) $p;
            $first = mb_substr($p, 0, 1);
            $masked[] = $first . str_repeat('*', max(1, mb_strlen($p) - 1));
            if ($i >= 1) {
                break; // keep only first 2 parts
            }
        }
        return implode(' ', $masked);
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || ! str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = (string) $local;
        $domain = (string) $domain;
        $localMasked = mb_substr($local, 0, 2) . str_repeat('*', max(1, mb_strlen($local) - 2));
        $domainParts = explode('.', $domain);
        $domainHead = $domainParts[0] ?? $domain;
        $domainMasked = mb_substr($domainHead, 0, 1) . str_repeat('*', max(1, mb_strlen($domainHead) - 1));
        $suffix = count($domainParts) > 1 ? '.' . implode('.', array_slice($domainParts, 1)) : '';
        return $localMasked . '@' . $domainMasked . $suffix;
    }
}

