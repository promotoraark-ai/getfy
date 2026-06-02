<script setup>
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import FinanceiroDashboard from '@/components/financeiro/FinanceiroDashboard.vue';
import FinanceiroCajupayGate from '@/components/financeiro/FinanceiroCajupayGate.vue';
import FinanceiroCajupayHint from '@/components/financeiro/FinanceiroCajupayHint.vue';
import BetaBadge from '@/components/ui/BetaBadge.vue';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    cajupay_connected: { type: Boolean, default: false },
    balances: { type: Object, default: () => ({ by_wallet: {}, totals: {} }) },
    wallet_labels: { type: Object, default: () => ({}) },
    transactions: { type: Array, default: () => [] },
    commissions: { type: Array, default: () => [] },
    payouts: { type: Array, default: () => [] },
    pix_key: { type: String, default: '' },
    pix_key_type: { type: String, default: '' },
    pix_owner_document: { type: String, default: '' },
    min_payout_cents: { type: Number, default: 100 },
});

function onReload() {
    router.reload({
        only: ['balances', 'transactions', 'commissions', 'payouts', 'pix_key', 'pix_key_type', 'pix_owner_document', 'cajupay_connected'],
    });
}
</script>

<template>
    <div class="space-y-2">
        <div class="mb-2">
            <h1 class="flex flex-wrap items-center gap-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                Financeiro
                <BetaBadge />
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Acompanhe suas comissões e solicite saques via PIX.
            </p>
            <FinanceiroCajupayHint v-if="cajupay_connected" variant="partner" class="mt-3" />
        </div>

        <div
            v-if="!cajupay_connected"
            class="mb-4 flex items-start gap-3 rounded-xl border border-amber-200/80 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900/40 dark:bg-amber-950/25 dark:text-amber-100"
            role="status"
        >
            <span class="mt-0.5 shrink-0 font-semibold">Saques pausados</span>
            <p class="leading-relaxed">
                O produtor ainda não conectou a CajuPay. Você pode ver comissões e histórico, mas solicitar
                saque via PIX ficará indisponível até a integração ser ativada.
            </p>
        </div>

        <FinanceiroCajupayGate :connected="cajupay_connected" variant="partner">
        <FinanceiroDashboard
            :balances="balances"
            :wallet-labels="wallet_labels"
            :transactions="transactions"
            :commissions="commissions"
            :payouts="payouts"
            :pix-key="pix_key"
            :pix-key-type="pix_key_type"
            :pix-owner-document="pix_owner_document"
            :min-payout-cents="min_payout_cents"
            :cajupay-connected="cajupay_connected"
            payout-base-url="/parceiro/financeiro/payout"
            pix-base-url="/parceiro/financeiro/pix"
            :show-commissions-table="true"
            @reload="onReload"
        />
        </FinanceiroCajupayGate>
    </div>
</template>
