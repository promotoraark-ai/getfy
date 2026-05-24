<?php

namespace App\Services;

use App\Models\MemberActivityLog;
use App\Models\MemberLessonProgress;
use App\Models\Order;
use App\Models\ProofDocument;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProofOfDeliveryService
{
    public function __construct(
        protected MemberProgressService $progressService,
        protected MemberAreaResolver $memberAreaResolver,
    ) {}

    public function issueForOrder(Order $order, ?User $generatedBy = null, bool $rotatePublicCode = false): ProofDocument
    {
        $order->loadMissing(['user', 'product', 'productOffer', 'subscriptionPlan', 'orderItems.product']);

        $doc = ProofDocument::query()->where('order_id', $order->id)->first();
        $now = now();
        $publicCode = $doc && ! $rotatePublicCode ? (string) $doc->public_code : $this->generatePublicCode(12);
        $generatedAt = $doc?->generated_at ?? $now;
        if (! $doc || $rotatePublicCode) {
            $generatedAt = $now;
        }

        $snapshot = $this->buildSnapshot($order, $publicCode, $generatedAt);
        $hash = $this->computePublicHash($publicCode, $order, $generatedAt);

        if (! $doc) {
            $doc = ProofDocument::create([
                'tenant_id' => $order->tenant_id ?? $order->product?->tenant_id,
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'product_id' => $order->product_id,
                'public_code' => $publicCode,
                'public_hash' => $hash,
                'payload_snapshot' => $snapshot,
                'generated_by_user_id' => $generatedBy?->id,
                'generated_at' => $generatedAt,
                'revoked_at' => null,
            ]);

            return $doc;
        }

        $doc->update([
            'tenant_id' => $order->tenant_id ?? $order->product?->tenant_id,
            'user_id' => $order->user_id,
            'product_id' => $order->product_id,
            'public_code' => $publicCode,
            'public_hash' => $hash,
            'payload_snapshot' => $snapshot,
            'generated_by_user_id' => $generatedBy?->id ?? $doc->generated_by_user_id,
            'generated_at' => $generatedAt,
        ]);

        return $doc->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(Order $order, string $publicCode, \Illuminate\Support\Carbon $generatedAt): array
    {
        $order->loadMissing(['user', 'product', 'productOffer', 'subscriptionPlan', 'orderItems.product']);

        $product = $order->product;
        $user = $order->user;

        $tenantId = $order->tenant_id ?? $product?->tenant_id;
        $productId = $product?->id;
        $userId = $user?->id;

        $memberAreaBaseUrl = null;
        if ($product) {
            try {
                $memberAreaBaseUrl = $this->memberAreaResolver->baseUrlForProduct($product);
            } catch (\Throwable) {
                $memberAreaBaseUrl = null;
            }
        }

        $progress = null;
        $completedLessons = [];
        $firstProgressAt = null;
        $lastProgressAt = null;

        if ($product && $user) {
            $total = $this->progressService->totalLessonsCount($product);
            $completed = $this->progressService->completedLessonsCount($product, $user);
            $percent = $this->progressService->completionPercent($product, $user);

            $progress = [
                'total_lessons' => $total,
                'completed_lessons' => $completed,
                'completion_percent' => $percent,
            ];

            $firstProgressAt = MemberLessonProgress::query()
                ->forUser($user->id)
                ->forProduct($product->id)
                ->orderBy('created_at')
                ->value('created_at');

            $lastProgressAt = MemberLessonProgress::query()
                ->forUser($user->id)
                ->forProduct($product->id)
                ->orderByDesc('updated_at')
                ->value('updated_at');

            $completedLessons = MemberLessonProgress::query()
                ->forUser($user->id)
                ->forProduct($product->id)
                ->whereNotNull('completed_at')
                ->with(['lesson:id,title,member_module_id,product_id'])
                ->orderByDesc('completed_at')
                ->limit(25)
                ->get()
                ->map(fn (MemberLessonProgress $p) => [
                    'lesson_id' => $p->member_lesson_id,
                    'lesson_title' => $p->lesson?->title,
                    'lesson_product_id' => $p->lesson?->product_id,
                    'completed_at' => $p->completed_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        $activities = [];
        if ($userId && $productId) {
            $activities = MemberActivityLog::query()
                ->where('user_id', $userId)
                ->where('product_id', $productId)
                ->orderByDesc('id')
                ->limit(80)
                ->get()
                ->map(fn (MemberActivityLog $l) => [
                    'event' => $l->event,
                    'metadata' => $l->metadata,
                    'ip' => $l->ip,
                    'user_agent' => $l->user_agent,
                    'created_at' => $l->created_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        $items = $order->orderItems->map(function ($it) {
            return [
                'product_id' => $it->product_id,
                'product_name' => $it->product?->name,
                'amount' => (float) ($it->amount ?? 0),
                'position' => (int) ($it->position ?? 0),
            ];
        })->values()->all();

        $snapshot = [
            'type' => 'proof_of_delivery',
            'version' => 1,
            'generated_at' => $generatedAt->toIso8601String(),
            'public_code' => $publicCode,
            'tenant_id' => $tenantId,
            'order' => [
                'id' => $order->id,
                'status' => $order->status,
                'gateway' => $order->gateway,
                'gateway_id' => $order->gateway_id,
                'amount' => (float) $order->amount,
                'currency' => Arr::get($order->metadata ?? [], 'currency', null),
                'payment_method' => $order->paymentMethodDisplayLabel(),
                'email' => $order->email,
                'cpf' => $order->cpf,
                'phone' => $order->phone,
                'customer_ip' => $order->customer_ip,
                'created_at' => $order->created_at?->toIso8601String(),
                'updated_at' => $order->updated_at?->toIso8601String(),
                'utm_source' => Arr::get($order->metadata ?? [], 'utm_source', null),
                'utm_medium' => Arr::get($order->metadata ?? [], 'utm_medium', null),
                'utm_campaign' => Arr::get($order->metadata ?? [], 'utm_campaign', null),
                'items' => $items,
            ],
            'buyer' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
            ] : null,
            'product' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'type' => $product->type,
                'checkout_slug' => $product->checkout_slug ?? null,
                'member_area_base_url' => $memberAreaBaseUrl,
            ] : null,
            'access' => [
                'first_progress_at' => $firstProgressAt?->toIso8601String(),
                'last_progress_at' => $lastProgressAt?->toIso8601String(),
                'progress' => $progress,
                'completed_lessons' => $completedLessons,
                'activity_logs' => $activities,
            ],
        ];

        return $snapshot;
    }

    public function verifyPublicCode(string $publicCode, ProofDocument $doc): bool
    {
        $expected = $this->computePublicHash($publicCode, $doc->order, $doc->generated_at ?? $doc->created_at ?? now());
        return hash_equals((string) $doc->public_hash, $expected);
    }

    private function computePublicHash(string $publicCode, Order $order, \Illuminate\Support\Carbon $generatedAt): string
    {
        $tenantId = (string) ($order->tenant_id ?? $order->product?->tenant_id ?? '');
        $payload = implode('|', [
            $publicCode,
            (string) $order->id,
            $tenantId,
            (string) $generatedAt->timestamp,
        ]);

        return hash_hmac('sha256', $payload, $this->hmacKey());
    }

    private function hmacKey(): string
    {
        $key = (string) config('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }
        return $key;
    }

    private function generatePublicCode(int $length = 12): string
    {
        // Crockford Base32 (no I, L, O, U). Uppercase for readability.
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}

