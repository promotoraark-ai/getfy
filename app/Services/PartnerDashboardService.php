<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\User;
use App\Support\PartnerBuyerPresentation;
use App\Support\ReportingPeriod;
use Carbon\Carbon;

class PartnerDashboardService
{
    private const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total'];

    /**
     * @return array<string, mixed>
     */
    public function metricsFor(User $user, string $period): array
    {
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'hoje';
        }

        [$start, $end] = ReportingPeriod::boundsForDashboard($period);

        $entriesQuery = $this->baseEntriesQuery($user);
        $entriesQuery->whereHas('order', function ($q) use ($start, $end) {
            $q->where('status', 'completed');
            ReportingPeriod::applyCreatedAtBounds($q, $start, $end);
        });

        $comissaoTotal = (float) (clone $entriesQuery)->sum('commission_entries.commission_amount');
        $quantidadeVendas = (int) (clone $entriesQuery)->distinct()->count('commission_entries.order_id');
        $ticketMedio = $quantidadeVendas > 0 ? round($comissaoTotal / $quantidadeVendas, 2) : 0.0;

        $saldoPendente = (float) CommissionEntry::query()
            ->where('beneficiary_user_id', $user->id)
            ->whereIn('role', [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR])
            ->where('status', CommissionEntry::STATUS_PENDING)
            ->sum('commission_amount');

        $saldoDisponivel = (float) CommissionEntry::query()
            ->where('beneficiary_user_id', $user->id)
            ->whereIn('role', [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR])
            ->where('status', CommissionEntry::STATUS_AVAILABLE)
            ->sum('commission_amount');

        $quantidadeProdutos = count(app(PartnerAccessService::class)->allowedProductIdsFor($user));

        return [
            'period' => $period,
            'comissao_total' => round($comissaoTotal, 2),
            'quantidade_vendas' => $quantidadeVendas,
            'ticket_medio_comissao' => $ticketMedio,
            'saldo_pendente' => round($saldoPendente, 2),
            'saldo_disponivel' => round($saldoDisponivel, 2),
            'quantidade_produtos' => $quantidadeProdutos,
            'grafico_comissoes' => $this->buildCommissionChart($user, $period, $start, $end),
            'vendas_recentes' => $this->recentSales($user),
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<CommissionEntry>
     */
    private function baseEntriesQuery(User $user)
    {
        return CommissionEntry::query()
            ->where('commission_entries.beneficiary_user_id', $user->id)
            ->whereIn('commission_entries.role', [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR])
            ->where('commission_entries.status', '!=', CommissionEntry::STATUS_CANCELLED);
    }

    /**
     * @return list<array{data: string, total: float}>
     */
    private function buildCommissionChart(User $user, string $period, ?Carbon $start, ?Carbon $end): array
    {
        $query = $this->baseEntriesQuery($user)
            ->join('orders', 'orders.id', '=', 'commission_entries.order_id')
            ->where('orders.status', 'completed');

        if ($start && $end) {
            $query->whereBetween('orders.created_at', [$start, $end]);
        } elseif ($start) {
            $query->where('orders.created_at', '>=', $start);
        } elseif ($end) {
            $query->where('orders.created_at', '<=', $end);
        }

        $isHourly = in_array($period, ['hoje', 'ontem'], true);
        $tz = ReportingPeriod::timezone();

        $rows = $query
            ->select(['commission_entries.commission_amount', 'orders.created_at'])
            ->orderBy('orders.created_at')
            ->get();

        if ($isHourly) {
            $totalsByHour = array_fill(0, 24, 0.0);
            foreach ($rows as $row) {
                $h = (int) Carbon::parse($row->created_at)->timezone($tz)->format('G');
                $totalsByHour[$h] += (float) $row->commission_amount;
            }

            $result = [];
            for ($h = 0; $h <= 23; $h++) {
                $result[] = ['data' => (string) $h, 'total' => round($totalsByHour[$h], 2)];
            }

            return $result;
        }

        $totalsByDate = [];
        foreach ($rows as $row) {
            $d = Carbon::parse($row->created_at)->timezone($tz)->format('Y-m-d');
            $totalsByDate[$d] = ($totalsByDate[$d] ?? 0.0) + (float) $row->commission_amount;
        }
        ksort($totalsByDate);

        $out = [];
        foreach ($totalsByDate as $data => $total) {
            $out[] = ['data' => $data, 'total' => round($total, 2)];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentSales(User $user): array
    {
        $orderIds = CommissionEntry::query()
            ->where('beneficiary_user_id', $user->id)
            ->whereIn('role', [CommissionEntry::ROLE_AFILIADO, CommissionEntry::ROLE_COPRODUTOR])
            ->where('status', '!=', CommissionEntry::STATUS_CANCELLED)
            ->orderByDesc('created_at')
            ->limit(50)
            ->pluck('order_id');

        $orders = Order::query()
            ->whereIn('id', $orderIds)
            ->where('status', 'completed')
            ->with(['product:id,name', 'user:id,name,email', 'commissionEntries' => fn ($q) => $q->where('beneficiary_user_id', $user->id)])
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        $shareMap = app(PartnerVendasService::class)->shareBuyerDataByProductIds(
            $orders->pluck('product_id')->filter()->unique()->values()->all()
        );

        return $orders
            ->map(function (Order $o) use ($shareMap) {
                $entry = $o->commissionEntries->first();
                $share = (bool) ($shareMap->get($o->product_id) ?? false);
                $buyer = PartnerBuyerPresentation::forOrder($o, $share);

                return [
                    'id' => $o->id,
                    'product_name' => $o->product?->name,
                    'buyer_name' => $buyer['name'],
                    'buyer_email' => $buyer['email'],
                    'buyer_masked' => $buyer['masked'],
                    'commission_amount' => $entry ? (float) $entry->commission_amount : 0,
                    'commission_status' => $entry?->status,
                    'created_at' => $o->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
