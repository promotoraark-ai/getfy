<?php

namespace App\Services;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MetaCustomAudienceCsvService
{
    /** Cabeçalho exigido pelo modelo Meta (Customer List). */
    public const HEADER_LINE = 'email,email,email,phone,phone,phone,madid,fn,ln,zip,ct,st,country,dob,doby,gen,age,uid,value';

    public function since180Days(): Carbon
    {
        return Carbon::now()->subDays(180)->startOfDay();
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function productsForExportDropdown(User $user): array
    {
        $tenantId = $user->tenant_id;
        $q = Product::forTenant($tenantId)->orderBy('name');
        if ($user->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor($user);
            if ($allowed === []) {
                return [];
            }
            $q->whereIn('id', $allowed);
        }

        return $q->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => (string) $p->id, 'name' => $p->name])
            ->values()
            ->all();
    }

    public function resolveProductOrAbort(User $user, string $productId): Product
    {
        $product = Product::query()->where('id', $productId)->first();
        if (! $product) {
            abort(404);
        }

        $tenantId = $user->tenant_id;
        if ($tenantId === null) {
            if ($product->tenant_id !== null) {
                abort(403);
            }
        } elseif ((int) $product->tenant_id !== (int) $tenantId) {
            abort(403);
        }

        if ($user->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor($user);
            $allowedNorm = array_map(static fn ($id) => (string) $id, $allowed);
            if (! in_array((string) $product->id, $allowedNorm, true)) {
                abort(403);
            }
        }

        return $product;
    }

    public function streamPurchasers(User $user, string $productId): StreamedResponse
    {
        $this->resolveProductOrAbort($user, $productId);
        $since = $this->since180Days();
        $tenantId = $user->tenant_id;

        $orders = Order::query()
            ->forTenant($tenantId)
            ->with(['user'])
            ->where('status', 'completed')
            ->where('product_id', $productId)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get();

        $byEmail = [];
        foreach ($orders as $order) {
            $email = $this->effectiveEmail($order);
            if ($email === '') {
                continue;
            }
            $key = strtolower($email);
            if (! isset($byEmail[$key])) {
                $byEmail[$key] = $order;
            }
        }

        $orderIds = collect($byEmail)->pluck('id')->filter()->all();
        $sessionsByOrderId = $orderIds === []
            ? collect()
            : CheckoutSession::query()
                ->whereIn('order_id', $orderIds)
                ->get()
                ->keyBy('order_id');

        $rows = [];
        foreach ($byEmail as $order) {
            $order->loadMissing('orderItems');
            $name = $sessionsByOrderId->get($order->id)?->name
                ?? $order->user?->name
                ?? '';
            [$fn, $ln] = $this->splitFirstLast($name);
            $value = (string) round($order->lineItemsTotalAmount(), 2);
            $rows[] = $this->metaRow(
                $this->effectiveEmail($order),
                trim((string) ($order->phone ?? '')),
                $fn,
                $ln,
                $value
            );
        }

        $filename = 'meta-compradores-'.Str::slug((string) $productId, '-').'-'.date('Y-m-d').'.csv';

        return $this->streamCsv($filename, $rows);
    }

    public function streamAbandonedEngaged(User $user, string $productId): StreamedResponse
    {
        $this->resolveProductOrAbort($user, $productId);
        $since = $this->since180Days();
        $tenantId = $user->tenant_id;

        $sessions = CheckoutSession::query()
            ->forTenant($tenantId)
            ->where('product_id', $productId)
            ->whereAbandonmentFormEligible()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('created_at', '>=', $since)
            ->orderByDesc('updated_at')
            ->get();

        $candidates = [];
        foreach ($sessions as $session) {
            $email = strtolower(trim((string) $session->email));
            if ($email === '') {
                continue;
            }
            $t0 = $session->form_filled_at ?? $session->form_started_at ?? $session->created_at;
            if ($this->hasCompletedPurchaseAfter($tenantId, $productId, $email, $t0)) {
                continue;
            }
            $candidates[] = ['email' => $email, 't0' => $t0, 'session' => $session];
        }

        usort($candidates, function ($a, $b) {
            return $b['t0'] <=> $a['t0'];
        });

        $byEmail = [];
        foreach ($candidates as $c) {
            $key = $c['email'];
            if (! isset($byEmail[$key])) {
                $byEmail[$key] = $c['session'];
            }
        }

        $rows = [];
        foreach ($byEmail as $session) {
            [$fn, $ln] = $this->splitFirstLast($session->name);
            $rows[] = $this->metaRow(
                trim((string) $session->email),
                '',
                $fn,
                $ln,
                ''
            );
        }

        $filename = 'meta-abandonos-'.Str::slug((string) $productId, '-').'-'.date('Y-m-d').'.csv';

        return $this->streamCsv($filename, $rows);
    }

    private function hasCompletedPurchaseAfter(?int $tenantId, string $productId, string $emailNorm, Carbon $t0): bool
    {
        return Order::query()
            ->forTenant($tenantId)
            ->where('status', 'completed')
            ->where('product_id', $productId)
            ->where('created_at', '>', $t0)
            ->where(function ($q) use ($emailNorm) {
                $q->whereRaw("LOWER(TRIM(COALESCE(email, ''))) = ?", [$emailNorm])
                    ->orWhereHas('user', function ($uq) use ($emailNorm) {
                        $uq->whereRaw("LOWER(TRIM(COALESCE(email, ''))) = ?", [$emailNorm]);
                    });
            })
            ->exists();
    }

    private function effectiveEmail(Order $order): string
    {
        $e = trim((string) ($order->email ?? ''));
        if ($e !== '') {
            return $e;
        }
        $order->loadMissing('user');

        return trim((string) ($order->user?->email ?? ''));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitFirstLast(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['', ''];
        }
        $pos = strpos($name, ' ');
        if ($pos === false) {
            return [$name, ''];
        }

        return [substr($name, 0, $pos), trim(substr($name, $pos + 1))];
    }

    /**
     * @return list<string>
     */
    private function metaRow(string $email, string $phone, string $fn, string $ln, string $value): array
    {
        return [
            $email,
            '',
            '',
            $phone,
            '',
            '',
            '',
            $fn,
            $ln,
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            $value,
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function streamCsv(string $filename, array $rows): StreamedResponse
    {
        $headerCells = str_getcsv(self::HEADER_LINE);

        return response()->streamDownload(function () use ($rows, $headerCells) {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $headerCells, ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '\\');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
