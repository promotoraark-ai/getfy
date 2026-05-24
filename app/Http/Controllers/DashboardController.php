<?php

namespace App\Http\Controllers;

use App\Events\DashboardLoading;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Support\OrderCurrencyTotals;
use Carbon\Carbon;
use App\Services\TeamAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total'];

    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    public function __invoke(Request $request): Response
    {
        $period = $request->query('period', 'hoje');
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'hoje';
        }

        $tenantId = auth()->user()->tenant_id;
        $userId = auth()->id();
        $cacheKey = 'dashboard:' . ($tenantId ?? 'global') . ':' . $period . ':u' . ($userId ?? '0');

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($tenantId, $period) {
            [$start, $end] = $this->rangeForPeriod($period);

            $ordersQuery = Order::forTenant($tenantId);
            if (auth()->user()?->isTeam()) {
                $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
                $ordersQuery->whereIn('product_id', $allowed ?: ['__none__']);
            }
        if ($start && $end) {
            $ordersQuery->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $ordersQuery->where('created_at', '>=', $start);
        } elseif ($end) {
            $ordersQuery->where('created_at', '<=', $end);
        }

        $ordersCompleted = (clone $ordersQuery)->where('status', 'completed');
        $ordersPending = (clone $ordersQuery)->where('status', 'pending');
        $ordersRefunded = (clone $ordersQuery)->where('status', 'refunded');

        $vendasTotaisPorMoeda = OrderCurrencyTotals::valorPorMoedaFromQuery($ordersQuery);
        $brlRow = collect($vendasTotaisPorMoeda)->firstWhere('currency', 'BRL');
        $vendasTotais = $brlRow ? (float) $brlRow['total'] : 0.0;
        $quantidadeVendas = $ordersCompleted->count();
        $ticketMedio = $quantidadeVendas > 0 && $brlRow
            ? (float) $brlRow['total'] / $quantidadeVendas
            : 0.0;
        $vendasPendentes = (float) (clone $ordersQuery)->where('status', 'pending')->where('currency', 'BRL')->sum('amount');
        $reembolsosCount = $ordersRefunded->count();
        $reembolsosTotal = (float) (clone $ordersQuery)->where('status', 'refunded')->sum('amount');

        $formasPagamento = (clone $ordersQuery)
            ->where('status', 'completed')
            ->selectRaw('gateway, SUM(amount) as total, COUNT(*) as quantidade')
            ->groupBy('gateway')
            ->get()
            ->map(function ($row) {
                $label = $this->gatewayLabel($row->gateway);
                return [
                    'metodo' => $row->gateway ?? 'outro',
                    'label' => $label,
                    'total' => (float) $row->total,
                    'quantidade' => (int) $row->quantidade,
                ];
            })
            ->values()
            ->all();

        $graficoVendas = $this->buildGraficoVendas($tenantId, $period, $start, $end);

        $productsQuery = Product::forTenant($tenantId);
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $productsQuery->whereIn('id', $allowed ?: ['__none__']);
        }
        $quantidadeProdutos = $productsQuery->count();

            $sessionsQuery = CheckoutSession::forTenant($tenantId);
            if (auth()->user()?->isTeam()) {
                $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
                $sessionsQuery->whereIn('product_id', $allowed ?: ['__none__']);
            }
            if ($start && $end) {
                $sessionsQuery->whereBetween('created_at', [$start, $end]);
            } elseif ($start) {
                $sessionsQuery->where('created_at', '>=', $start);
            } elseif ($end) {
                $sessionsQuery->where('created_at', '<=', $end);
            }

            $abandonadosVisit = (clone $sessionsQuery)
                ->whereAbandonmentVisitEligible()
                ->count();

            $abandonadosForm = (clone $sessionsQuery)
                ->whereAbandonmentFormEligible()
                ->count();

            $convertedSessions = (clone $sessionsQuery)
                ->where('step', CheckoutSession::STEP_CONVERTED)
                ->count();

            $totalSessoesPeriodo = (clone $sessionsQuery)->count();

            $abandonoCarrinho = $abandonadosVisit + $abandonadosForm;
            $taxaConversao = $totalSessoesPeriodo > 0
                ? round((float) $convertedSessions / $totalSessoesPeriodo * 100, 1)
                : 0.0;

            return [
                'period' => $period,
                'vendas_totais' => round($vendasTotais, 2),
                'vendas_totais_por_moeda' => $vendasTotaisPorMoeda,
                'vendas_pendentes' => round($vendasPendentes, 2),
                'quantidade_vendas' => $quantidadeVendas,
                'ticket_medio' => round($ticketMedio, 2),
                'formas_pagamento' => $formasPagamento,
                'taxa_conversao' => $taxaConversao,
                'abandono_carrinho' => $abandonoCarrinho,
                'reembolsos_count' => $reembolsosCount,
                'reembolsos_total' => round($reembolsosTotal, 2),
                'quantidade_produtos' => $quantidadeProdutos,
                'grafico_vendas' => $graficoVendas,
            ];
        });

        $data = new \ArrayObject($payload);
        event(new DashboardLoading($data));

        return Inertia::render('Dashboard/Index', $data->getArrayCopy());
    }

    private function rangeForPeriod(string $period): array
    {
        $now = Carbon::now();
        $start = null;
        $end = null;

        switch ($period) {
            case 'hoje':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'ontem':
                $start = $now->copy()->subDay()->startOfDay();
                $end = $now->copy()->subDay()->endOfDay();
                break;
            case '7dias':
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'mes':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;
            case 'ano':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                break;
            case 'total':
                break;
        }

        return [$start?->toDateTimeString(), $end?->toDateTimeString()];
    }

    private function gatewayLabel(?string $gateway): string
    {
        if ($gateway === null || $gateway === '') {
            return 'Outro';
        }
        $g = strtolower($gateway);
        if (str_contains($g, 'pix')) {
            return 'Pix';
        }
        if (str_contains($gateway, 'card') || str_contains($g, 'cartao') || str_contains($g, 'cartão') || str_contains($g, 'credito')) {
            return 'Cartão';
        }
        if (str_contains($g, 'boleto')) {
            return 'Boleto';
        }
        return ucfirst($gateway);
    }

    private function buildGraficoVendas(?int $tenantId, string $period, ?string $start, ?string $end): array
    {
        $query = Order::forTenant($tenantId)->where('status', 'completed');
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $query->whereIn('product_id', $allowed ?: ['__none__']);
        }

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $query->where('created_at', '>=', $start);
        } elseif ($end) {
            $query->where('created_at', '<=', $end);
        }

        $isHourly = in_array($period, ['hoje', 'ontem'], true);

        if ($isHourly) {
            $rows = $query
                ->selectRaw('HOUR(created_at) as hora, SUM(amount) as total')
                ->groupBy('hora')
                ->orderBy('hora')
                ->get()
                ->keyBy('hora');

            $result = [];
            for ($h = 0; $h <= 23; $h++) {
                $result[] = [
                    'data' => (string) $h,
                    'total' => (float) ($rows->get($h)?->total ?? 0),
                ];
            }

            return $result;
        }

        $rows = $query
            ->selectRaw('DATE(created_at) as data, SUM(amount) as total')
            ->groupBy('data')
            ->orderBy('data')
            ->get();

        return $rows->map(fn ($r) => [
            'data' => $r->data,
            'total' => (float) $r->total,
        ])->values()->all();
    }
}
