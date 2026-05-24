<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProofDocument;
use setasign\Fpdi\Fpdi;

class ProofPdfRenderer
{
    public function renderOrderDossier(Fpdi $pdf, Order $order, ProofDocument $doc): void
    {
        $order->loadMissing(['user', 'product', 'productOffer', 'subscriptionPlan', 'orderItems.product']);

        $snapshot = is_array($doc->payload_snapshot) ? $doc->payload_snapshot : [];
        $verifyUrl = $doc->public_code ? url('/verify/' . $doc->public_code) : null;

        $pdf->SetAutoPageBreak(true, 16);
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 15);
        $pdf->MultiCell(0, 8, $this->s('Log de Atividade do Pedido'), 0, 'L');

        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->MultiCell(0, 6, $this->s('Gerado em: ' . ($doc->generated_at?->format('d/m/Y H:i:s') ?? now()->format('d/m/Y H:i:s'))), 0, 'L');
        $pdf->Ln(2);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->MultiCell(0, 7, $this->s('Pedido'), 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, $this->s('Pedido #' . $order->id), 0, 'L');
        $pdf->MultiCell(0, 6, $this->s('Data/hora (criado): ' . ($order->created_at?->format('d/m/Y H:i:s') ?? '-')), 0, 'L');
        $pdf->MultiCell(0, 6, $this->s('Data/hora (atualizado): ' . ($order->updated_at?->format('d/m/Y H:i:s') ?? '-')), 0, 'L');
        $pdf->MultiCell(0, 6, $this->s('Status: ' . ($order->status ?? '-') . ' | Método: ' . $order->paymentMethodDisplayLabel() . ' | Gateway: ' . ($order->gateway ?? '-')), 0, 'L');
        if (! empty($order->gateway_id)) {
            $pdf->MultiCell(0, 6, $this->s('Transação: ' . $order->gateway_id), 0, 'L');
        }
        $pdf->MultiCell(0, 6, $this->s('Valor: ' . number_format((float) $order->amount, 2, ',', '.')), 0, 'L');
        $pdf->MultiCell(0, 6, $this->s('IP (checkout): ' . ($order->customer_ip ?? '-')), 0, 'L');
        $utmSource = data_get($snapshot, 'order.utm_source');
        $utmMedium = data_get($snapshot, 'order.utm_medium');
        $utmCampaign = data_get($snapshot, 'order.utm_campaign');
        $pdf->MultiCell(0, 6, $this->s('utm_source: ' . (is_string($utmSource) && $utmSource !== '' ? $utmSource : 'Não informado')), 0, 'L');
        $pdf->MultiCell(0, 6, $this->s('utm_campaign: ' . (is_string($utmCampaign) && $utmCampaign !== '' ? $utmCampaign : 'Não informado')), 0, 'L');
        $pdf->MultiCell(0, 6, $this->s('utm_medium: ' . (is_string($utmMedium) && $utmMedium !== '' ? $utmMedium : 'Não informado')), 0, 'L');
        $pdf->Ln(2);

        $buyerName = $order->user?->name ?? ($order->email ?? 'Cliente');
        $buyerEmail = $order->email ?? $order->user?->email ?? '';

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->MultiCell(0, 7, $this->s('Comprador'), 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, $this->s('Nome: ' . $buyerName), 0, 'L');
        $pdf->MultiCell(0, 6, $this->s('E-mail: ' . $buyerEmail), 0, 'L');
        if (! empty($order->cpf)) {
            $pdf->MultiCell(0, 6, $this->s('CPF: ' . $order->cpf), 0, 'L');
        }
        if (! empty($order->phone)) {
            $pdf->MultiCell(0, 6, $this->s('Telefone: ' . $order->phone), 0, 'L');
        }
        $pdf->Ln(2);

        $productName = $order->product?->name ?? '';
        $productId = $order->product?->id ?? '';
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->MultiCell(0, 7, $this->s('Produto'), 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, $this->s('Nome: ' . ($productName ?: '-')), 0, 'L');
        $pdf->MultiCell(0, 6, $this->s('ID: ' . ($productId ?: '-')), 0, 'L');
        $memberBase = data_get($snapshot, 'product.member_area_base_url');
        if (is_string($memberBase) && $memberBase !== '') {
            $pdf->MultiCell(0, 6, $this->s('Área de membros: ' . $memberBase), 0, 'L');
        }
        $pdf->Ln(2);

        $progress = data_get($snapshot, 'access.progress', []);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->MultiCell(0, 7, $this->s('Progresso'), 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, $this->s('Conclusão: ' . (int) data_get($progress, 'completion_percent', 0) . '%'), 0, 'L');
        $pdf->MultiCell(0, 6, $this->s('Aulas concluídas: ' . (int) data_get($progress, 'completed_lessons', 0) . ' / ' . (int) data_get($progress, 'total_lessons', 0)), 0, 'L');
        $firstAt = data_get($snapshot, 'access.first_progress_at');
        $lastAt = data_get($snapshot, 'access.last_progress_at');
        if (is_string($firstAt) && $firstAt !== '') {
            $pdf->MultiCell(0, 6, $this->s('Primeira atividade registrada: ' . $firstAt), 0, 'L');
        }
        if (is_string($lastAt) && $lastAt !== '') {
            $pdf->MultiCell(0, 6, $this->s('Última atividade registrada: ' . $lastAt), 0, 'L');
        }
        $pdf->Ln(2);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->MultiCell(0, 7, $this->s('Aulas concluídas (mais recentes)'), 0, 'L');
        $pdf->SetFont('Arial', '', 9);
        $lessons = data_get($snapshot, 'access.completed_lessons', []);
        if (is_array($lessons) && $lessons !== []) {
            $i = 0;
            foreach ($lessons as $l) {
                if ($i++ >= 20) {
                    break;
                }
                $title = (string) ($l['lesson_title'] ?? '');
                $lid = (string) ($l['lesson_id'] ?? '');
                $completedAt = (string) ($l['completed_at'] ?? '');
                $line = trim('#' . $lid . ' - ' . ($title !== '' ? $title : 'Aula') . ($completedAt !== '' ? ' (' . $completedAt . ')' : ''));
                $pdf->MultiCell(0, 5, $this->s($line), 0, 'L');
            }
        } else {
            $pdf->MultiCell(0, 5, $this->s('Nenhuma aula concluída registrada.'), 0, 'L');
        }
        $pdf->Ln(2);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->MultiCell(0, 7, $this->s('Logs de atividade (mais recentes)'), 0, 'L');
        $pdf->SetFont('Arial', '', 8);
        $logs = data_get($snapshot, 'access.activity_logs', []);
        if (is_array($logs) && $logs !== []) {
            $i = 0;
            foreach ($logs as $log) {
                if ($i++ >= 30) {
                    break;
                }
                $event = (string) ($log['event'] ?? '');
                $ip = (string) ($log['ip'] ?? '');
                $createdAt = (string) ($log['created_at'] ?? '');
                $meta = $log['metadata'] ?? null;
                $metaStr = '';
                if (is_array($meta) && $meta !== []) {
                    $safe = array_intersect_key($meta, array_flip(['lesson_id', 'module_id', 'embedded', 'mode', 'path']));
                    $metaStr = $safe !== [] ? ' ' . json_encode($safe, JSON_UNESCAPED_UNICODE) : '';
                }
                $pdf->MultiCell(0, 4.5, $this->s(trim($createdAt . ' | ' . $event . ' | ' . $ip . $metaStr)), 0, 'L');
            }
        } else {
            $pdf->MultiCell(0, 4.5, $this->s('Nenhum log registrado.'), 0, 'L');
        }

        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->MultiCell(0, 7, $this->s('Verificação'), 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, $this->s('Código: ' . $doc->public_code), 0, 'L');
        if ($verifyUrl) {
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell(0, 5, $this->s('Link: ' . $verifyUrl), 0, 'L');
        }
    }

    private function s(string $text): string
    {
        // FPDF usa ISO-8859-1 por padrão; converter evita caracteres quebrados (ex.: “Método”).
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        return is_string($converted) && $converted !== '' ? $converted : $text;
    }
}

