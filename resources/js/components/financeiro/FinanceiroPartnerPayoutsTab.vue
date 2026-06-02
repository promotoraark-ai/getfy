<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import { Clock, CheckCircle, XCircle, CreditCard, QrCode, Receipt, Users } from 'lucide-vue-next';

const props = defineProps({
    partnerPayouts: {
        type: Object,
        default: () => ({ items: [], summary: { pending_count: 0, pending_amount: 0 } }),
    },
    canManage: { type: Boolean, default: true },
});

const msg = ref('');
const processingId = ref(null);

const statusLabels = {
    pending_approval: 'Aguardando aprovação',
    processing: 'Processando',
    awaiting_payout: 'Processando PIX',
    completed: 'Concluído',
    failed: 'Falhou',
    cancelled: 'Rejeitado',
};

const walletIcons = { pix: QrCode, card: CreditCard, boleto: Receipt };

function formatBRL(v) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));
}

function roleLabel(role) {
    if (role === 'afiliado') return 'Afiliado';
    if (role === 'coprodutor') return 'Co-produtor';
    return role || 'Parceiro';
}

function roleBadgeClass(role) {
    if (role === 'afiliado') {
        return 'bg-violet-500/15 text-violet-700 dark:text-violet-300';
    }
    if (role === 'coprodutor') {
        return 'bg-sky-500/15 text-sky-700 dark:text-sky-300';
    }
    return 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400';
}

async function approve(payout) {
    if (!confirm(`Aprovar saque de ${formatBRL(payout.amount)} para ${payout.partner?.name}?`)) return;
    processingId.value = payout.id;
    msg.value = '';
    try {
        const { data } = await axios.post(`/financeiro/saques-parceiros/${payout.id}/approve`);
        msg.value = data.message || 'Saque aprovado.';
        router.reload({ only: ['partner_payouts', 'summary', 'balances'] });
    } catch (e) {
        msg.value = e.response?.data?.message || e.response?.data?.errors
            ? Object.values(e.response.data.errors || {}).flat().join(' ')
            : 'Erro ao aprovar.';
    } finally {
        processingId.value = null;
    }
}

async function reject(payout) {
    const reason = window.prompt('Motivo da rejeição (opcional):');
    if (reason === null) return;
    processingId.value = payout.id;
    msg.value = '';
    try {
        await axios.post(`/financeiro/saques-parceiros/${payout.id}/reject`, { reason: reason || undefined });
        msg.value = 'Saque rejeitado.';
        router.reload({ only: ['partner_payouts', 'summary', 'balances'] });
    } catch (e) {
        msg.value = e.response?.data?.message || 'Erro ao rejeitar.';
    } finally {
        processingId.value = null;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div
            class="flex items-start gap-3 rounded-xl border border-zinc-200/80 bg-zinc-50/80 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900/40"
        >
            <div
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--color-primary)]/15 text-[var(--color-primary)]"
            >
                <Users class="h-5 w-5" />
            </div>
            <div class="min-w-0 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                <p class="font-medium text-zinc-900 dark:text-white">
                    Saques de afiliados e co-produtores
                </p>
                <p class="mt-1">
                    Aqui aparecem as solicitações de saque feitas pelos seus
                    <strong class="font-medium text-zinc-700 dark:text-zinc-300">afiliados</strong>
                    e
                    <strong class="font-medium text-zinc-700 dark:text-zinc-300">co-produtores</strong>
                    (comissões de vendas na sua conta). PIX costuma ser automático; saques de
                    <strong class="font-medium text-zinc-700 dark:text-zinc-300">cartão</strong>
                    e
                    <strong class="font-medium text-zinc-700 dark:text-zinc-300">boleto</strong>
                    ficam nesta fila até você aprovar ou rejeitar.
                </p>
            </div>
        </div>

        <div class="fin-metric-card flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-zinc-500">Saques aguardando sua aprovação</p>
                <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">
                    {{ partnerPayouts.summary?.pending_count ?? 0 }}
                    <span class="text-base font-normal text-zinc-500">
                        · {{ formatBRL(partnerPayouts.summary?.pending_amount) }}
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-2 text-amber-700 dark:text-amber-300">
                <Clock class="h-5 w-5 shrink-0" />
                <p class="text-xs max-w-sm">
                    Cartão e boleto exigem aprovação. Confirme que há saldo na plataforma antes de aprovar.
                </p>
            </div>
        </div>

        <p
            v-if="msg"
            class="rounded-lg px-4 py-2 text-sm"
            :class="msg.includes('rejeit') ? 'bg-red-500/10 text-red-700' : 'bg-emerald-500/10 text-emerald-700'"
        >
            {{ msg }}
        </p>

        <div class="fin-panel overflow-hidden">
            <div class="border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">
                    Solicitações de afiliados e co-produtores
                </h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Cada linha é um parceiro que pediu saque da comissão — o papel (afiliado ou co-produtor)
                    aparece ao lado do nome.
                </p>
            </div>

            <div v-if="partnerPayouts.items?.length" class="divide-y divide-zinc-100 dark:divide-zinc-800">
                <div
                    v-for="p in partnerPayouts.items"
                    :key="p.id"
                    class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                            <component :is="walletIcons[p.wallet_bucket] || QrCode" class="h-5 w-5 text-zinc-500" />
                        </div>
                        <div>
                            <p class="flex flex-wrap items-center gap-2 font-medium text-zinc-900 dark:text-white">
                                {{ p.partner?.name || 'Parceiro' }}
                                <span
                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                    :class="roleBadgeClass(p.partner?.role)"
                                >
                                    {{ roleLabel(p.partner?.role) }}
                                </span>
                            </p>
                            <p class="text-xs text-zinc-500">{{ p.partner?.email }}</p>
                            <p class="mt-1 text-sm">
                                <span class="font-semibold">{{ formatBRL(p.amount) }}</span>
                                · {{ p.wallet_label }}
                                · PIX {{ p.pix_key_masked }}
                            </p>
                            <p class="text-xs text-zinc-400">{{ formatDate(p.created_at) }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 sm:flex-col sm:items-end">
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="{
                                'bg-amber-500/15 text-amber-700 dark:text-amber-300': p.status === 'pending_approval',
                                'bg-emerald-500/15 text-emerald-700': p.status === 'completed',
                                'bg-red-500/15 text-red-700': p.status === 'failed' || p.status === 'cancelled',
                                'bg-zinc-500/15 text-zinc-600': !['pending_approval','completed','failed','cancelled'].includes(p.status),
                            }"
                        >
                            {{ statusLabels[p.status] || p.status }}
                        </span>

                        <p
                            v-if="p.status === 'pending_approval' && !canManage"
                            class="text-xs text-zinc-500"
                        >
                            Sem permissão para aprovar
                        </p>
                        <div v-else-if="canManage && p.status === 'pending_approval'" class="flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                :disabled="processingId === p.id"
                                @click="approve(p)"
                            >
                                <CheckCircle class="mr-1 h-4 w-4" />
                                Aprovar
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="processingId === p.id"
                                @click="reject(p)"
                            >
                                <XCircle class="mr-1 h-4 w-4" />
                                Rejeitar
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
            <p v-else class="px-5 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                Nenhuma solicitação de saque de afiliados ou co-produtores no momento.
            </p>
        </div>
    </div>
</template>

<style scoped>
.fin-metric-card {
    border-radius: 1rem;
    border: 1px solid rgb(228 228 231 / 0.8);
    background: white;
    padding: 1rem;
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
}
.dark .fin-metric-card {
    border-color: rgb(39 39 42);
    background: rgb(24 24 27 / 0.8);
}
.fin-panel {
    overflow: hidden;
    border-radius: 1rem;
    border: 1px solid rgb(228 228 231 / 0.8);
    background: white;
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
}
.dark .fin-panel {
    border-color: rgb(39 39 42);
    background: rgb(24 24 27 / 0.8);
}
</style>
