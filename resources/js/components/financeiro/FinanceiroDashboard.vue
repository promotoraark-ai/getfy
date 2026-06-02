<script setup>
import { computed, ref, watch } from 'vue';
import Button from '@/components/ui/Button.vue';
import {
    Wallet,
    Clock,
    Lock,
    TrendingUp,
    TrendingDown,
    ArrowDownToLine,
    QrCode,
    CreditCard,
    Receipt,
    ChevronDown,
    ArrowDownCircle,
    ArrowUpCircle,
} from 'lucide-vue-next';

const props = defineProps({
    balances: { type: Object, default: () => ({ by_wallet: {}, totals: {} }) },
    walletLabels: { type: Object, default: () => ({}) },
    payouts: { type: Array, default: () => [] },
    transactions: { type: Array, default: () => [] },
    commissions: { type: Array, default: () => [] },
    pixKey: { type: String, default: '' },
    pixKeyType: { type: String, default: 'email' },
    pixOwnerDocument: { type: String, default: '' },
    minPayoutCents: { type: Number, default: 100 },
    cajupayConnected: { type: Boolean, default: true },
    payoutBaseUrl: { type: String, required: true },
    pixBaseUrl: { type: String, required: true },
    partnerSummary: { type: Object, default: null },
    showCommissionsTable: { type: Boolean, default: true },
    canManage: { type: Boolean, default: true },
});

const emit = defineEmits(['reload']);

const walletKeys = ['pix', 'card', 'boleto'];

const walletIcons = {
    pix: QrCode,
    card: CreditCard,
    boleto: Receipt,
};

const selectedWallet = ref('pix');
const showPixForm = ref(false);
const showWithdrawForm = ref(false);
const withdrawAll = ref(true);
const payoutAmount = ref(0);
const payoutMsg = ref('');
const saving = ref(false);
const paying = ref(false);

const pixForm = ref({
    pix_key: props.pixKey || '',
    pix_key_type: props.pixKeyType || 'email',
    pix_owner_document: props.pixOwnerDocument || '',
});

watch(
    () => [props.pixKey, props.pixKeyType, props.pixOwnerDocument],
    () => {
        pixForm.value = {
            pix_key: props.pixKey || '',
            pix_key_type: props.pixKeyType || 'email',
            pix_owner_document: props.pixOwnerDocument || '',
        };
    }
);

const walletBalance = computed(() => props.balances?.by_wallet?.[selectedWallet.value] || {});

const metrics = computed(() => {
    const w = walletBalance.value;
    const totals = props.balances?.totals || {};
    return {
        available: w.available ?? 0,
        pending: w.pending ?? 0,
        reserved: w.reserved ?? 0,
        paidOut: w.paid_total ?? 0,
        totalAvailable: totals.available ?? 0,
    };
});

const maxAvailable = computed(() => metrics.value.available);
const minPayoutReais = computed(() => props.minPayoutCents / 100);

const needsOwnerDocument = computed(() =>
    ['email', 'phone', 'random'].includes(pixForm.value.pix_key_type)
);

const pixKeyTypeLabels = {
    email: 'E-mail',
    cpf: 'CPF',
    cnpj: 'CNPJ',
    phone: 'Telefone',
    random: 'Chave aleatória',
};

const withdrawPixDestination = computed(() => {
    if (!props.pixKey) {
        return null;
    }
    const typeLabel = pixKeyTypeLabels[props.pixKeyType] || props.pixKeyType || 'PIX';
    return `${typeLabel}: ${props.pixKey}`;
});

const entradasPeriodo = computed(() => {
    const credits = props.transactions.filter((t) => t.type === 'credit');
    return credits.reduce((s, t) => s + (t.amount || 0), 0);
});

const saidasPeriodo = computed(() => {
    const debits = props.transactions.filter((t) => t.type === 'debit');
    return debits.reduce((s, t) => s + (t.amount || 0), 0);
});

const chartBars = computed(() => {
    const days = 14;
    const map = {};
    const now = new Date();
    for (let i = days - 1; i >= 0; i--) {
        const d = new Date(now);
        d.setDate(d.getDate() - i);
        const key = d.toISOString().slice(0, 10);
        map[key] = 0;
    }
    for (const c of props.commissions) {
        if (!c.created_at) continue;
        const key = c.created_at.slice(0, 10);
        if (key in map) {
            map[key] += Number(c.commission_amount) || 0;
        }
    }
    const values = Object.values(map);
    const max = Math.max(...values, 1);
    return Object.entries(map).map(([date, value]) => ({
        date,
        label: new Date(date + 'T12:00:00').toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }),
        value,
        height: Math.max(4, Math.round((value / max) * 100)),
    }));
});

const recentMovements = computed(() => {
    const items = [];
    for (const t of props.transactions.slice(0, 8)) {
        items.push({
            id: `t-${t.id}`,
            type: t.type,
            label: t.description || (t.type === 'credit' ? 'Comissão' : 'Saque'),
            amount: t.amount,
            date: t.created_at,
        });
    }
    for (const p of props.payouts.slice(0, 5)) {
        const dest = p.pix_destination || '';
        const wallet = p.wallet_label || '';
        const label = dest
            ? `Saque para ${dest}${wallet ? ` · ${wallet}` : ''}`
            : `Saque ${wallet}`.trim();
        items.push({
            id: `p-${p.id}`,
            type: 'debit',
            label,
            amount: p.amount,
            date: p.created_at,
            status: p.status,
        });
    }
    return items
        .sort((a, b) => new Date(b.date || 0) - new Date(a.date || 0))
        .slice(0, 10);
});

const statusLabels = {
    pending: 'Pendente',
    available: 'Disponível',
    reserved: 'Em saque',
    paid: 'Pago',
    pending_approval: 'Aguardando aprovação',
    awaiting_payout: 'Processando PIX',
    processing: 'Processando',
    completed: 'Concluído',
    failed: 'Falhou',
    cancelled: 'Cancelado',
    settled_externally: 'Split',
};

function formatBRL(v) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));
}

function selectWallet(key) {
    selectedWallet.value = key;
    payoutAmount.value = props.balances?.by_wallet?.[key]?.available ?? 0;
    withdrawAll.value = true;
}

async function savePix() {
    saving.value = true;
    payoutMsg.value = '';
    try {
        const axios = (await import('axios')).default;
        await axios.post(props.pixBaseUrl, pixForm.value);
        payoutMsg.value = 'Chave PIX salva.';
        showPixForm.value = false;
        emit('reload');
    } catch (e) {
        const errors = e.response?.data?.errors;
        payoutMsg.value = errors
            ? Object.values(errors).flat().join(' ')
            : e.response?.data?.message || 'Erro ao salvar PIX.';
    } finally {
        saving.value = false;
    }
}

async function requestPayout() {
    paying.value = true;
    payoutMsg.value = '';
    try {
        const axios = (await import('axios')).default;
        const payload = {
            wallet_bucket: selectedWallet.value,
            withdraw_all: withdrawAll.value,
        };
        if (!withdrawAll.value) {
            payload.amount = payoutAmount.value;
        }
        const { data } = await axios.post(props.payoutBaseUrl, payload);
        const status = data?.payout?.status ?? data?.payout_request?.status;
        const dest =
            data?.payout?.pix_destination
            || data?.payout_request?.pix_destination
            || withdrawPixDestination.value;
        const destSuffix = dest ? ` Destino: ${dest}.` : '';
        payoutMsg.value =
            (data?.message ||
                (status === 'completed'
                    ? 'Saque concluído.'
                    : status === 'pending_approval'
                      ? 'Solicitação enviada. Aguarde aprovação do produtor.'
                      : status === 'awaiting_payout' || status === 'processing'
                        ? 'Saque em processamento. O status será atualizado em breve.'
                        : 'Solicitação registrada.')) + destSuffix;
        if (status === 'completed' || status === 'pending_approval') {
            showWithdrawForm.value = false;
        }
        emit('reload');
    } catch (e) {
        const errors = e.response?.data?.errors;
        payoutMsg.value = errors
            ? Object.values(errors).flat().join(' ')
            : e.response?.data?.message || 'Erro ao solicitar saque.';
    } finally {
        paying.value = false;
    }
}

selectWallet('pix');
</script>

<template>
    <div class="fin-dash space-y-6">
        <!-- Seletor de carteira -->
        <div class="flex flex-wrap items-center gap-2 border-b border-zinc-100 pb-5 dark:border-zinc-800">
            <button
                v-for="key in walletKeys"
                :key="key"
                type="button"
                class="flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-medium transition"
                :class="
                    selectedWallet === key
                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-zinc-900 dark:text-white'
                        : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400'
                "
                @click="selectWallet(key)"
            >
                <component :is="walletIcons[key]" class="h-4 w-4" :class="selectedWallet === key ? 'text-[var(--color-primary)]' : ''" />
                {{ walletLabels[key] || key }}
            </button>
        </div>

        <!-- Cards métricas -->
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="fin-metric-card">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Saldo disponível</p>
                        <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                            {{ formatBRL(metrics.available) }}
                        </p>
                        <p class="mt-1 text-xs text-zinc-500">Carteira {{ walletLabels[selectedWallet] }}</p>
                    </div>
                    <div class="fin-metric-icon fin-metric-icon--primary">
                        <Wallet class="h-5 w-5" />
                    </div>
                </div>
            </div>

            <div class="fin-metric-card">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">A liberar</p>
                        <p class="mt-2 text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                            {{ formatBRL(metrics.pending) }}
                        </p>
                        <p class="mt-1 text-xs text-zinc-500">Aguardando prazo de liquidação</p>
                    </div>
                    <div class="fin-metric-icon fin-metric-icon--green">
                        <TrendingUp class="h-5 w-5" />
                    </div>
                </div>
            </div>

            <div class="fin-metric-card">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Saídas (saques)</p>
                        <p class="mt-2 text-2xl font-bold tracking-tight text-red-600 dark:text-red-400">
                            {{ formatBRL(saidasPeriodo) }}
                        </p>
                        <p class="mt-1 text-xs text-zinc-500">Histórico de movimentações</p>
                    </div>
                    <div class="fin-metric-icon fin-metric-icon--red">
                        <TrendingDown class="h-5 w-5" />
                    </div>
                </div>
            </div>

            <div class="fin-metric-card">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Saques pendentes</p>
                        <p class="mt-2 text-2xl font-bold tracking-tight text-violet-600 dark:text-violet-400">
                            {{ formatBRL(metrics.reserved) }}
                        </p>
                        <p class="mt-1 text-xs text-zinc-500">
                            {{ metrics.reserved > 0 ? 'Processando' : 'Nenhum em andamento' }}
                        </p>
                    </div>
                    <div class="fin-metric-icon fin-metric-icon--violet">
                        <Clock class="h-5 w-5" />
                    </div>
                </div>
            </div>
        </div>

        <div v-if="partnerSummary" class="grid gap-3 sm:grid-cols-2">
            <div class="fin-metric-card">
                <p class="text-xs text-zinc-500">Comissões parceiros (a pagar)</p>
                <p class="mt-1 text-lg font-semibold">{{ formatBRL(partnerSummary.partner_commissions_pending) }}</p>
            </div>
            <div class="fin-metric-card">
                <p class="text-xs text-zinc-500">Comissões parceiros (pagas)</p>
                <p class="mt-1 text-lg font-semibold">{{ formatBRL(partnerSummary.partner_commissions_paid) }}</p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Coluna principal -->
            <div class="space-y-4 lg:col-span-2">
                <div class="fin-panel">
                    <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Visão geral</h2>
                        <span class="text-xs text-zinc-500">Comissões — últimos 14 dias</span>
                    </div>
                    <div class="flex items-end justify-between gap-1 px-5 py-6" style="min-height: 180px">
                        <div
                            v-for="bar in chartBars"
                            :key="bar.date"
                            class="flex flex-1 flex-col items-center gap-2"
                        >
                            <div
                                class="w-full max-w-[28px] rounded-t-md bg-[var(--color-primary)]/80 transition-all dark:bg-[var(--color-primary)]"
                                :style="{ height: `${bar.height}px` }"
                                :title="formatBRL(bar.value)"
                            />
                            <span class="text-[10px] text-zinc-400">{{ bar.label }}</span>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-4 border-t border-zinc-100 px-5 py-3 text-xs dark:border-zinc-800">
                        <span class="flex items-center gap-1.5">
                            <span class="h-2 w-2 rounded-full bg-[var(--color-primary)]" />
                            Entradas (comissões)
                        </span>
                    </div>
                </div>

                <div class="fin-panel">
                    <div class="border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Movimentações recentes</h2>
                    </div>
                    <ul v-if="recentMovements.length" class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <li
                            v-for="item in recentMovements"
                            :key="item.id"
                            class="flex items-center justify-between gap-3 px-5 py-3.5"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-9 w-9 items-center justify-center rounded-full"
                                    :class="item.type === 'credit' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-red-500/10 text-red-600'"
                                >
                                    <ArrowDownCircle v-if="item.type === 'credit'" class="h-4 w-4" />
                                    <ArrowUpCircle v-else class="h-4 w-4" />
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ item.label }}</p>
                                    <p class="text-xs text-zinc-500">{{ formatDate(item.date) }}</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p
                                    class="text-sm font-semibold"
                                    :class="item.type === 'credit' ? 'text-emerald-600' : 'text-red-600'"
                                >
                                    {{ item.type === 'credit' ? '+' : '−' }}{{ formatBRL(item.amount) }}
                                </p>
                                <span
                                    v-if="item.status"
                                    class="text-xs text-zinc-500"
                                >{{ statusLabels[item.status] || item.status }}</span>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="px-5 py-10 text-center text-sm text-zinc-500">Nenhuma movimentação ainda.</p>
                </div>
            </div>

            <!-- Sidebar ações -->
            <div class="space-y-4">
                <div class="fin-panel p-5">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Ações rápidas</h2>
                    <p
                        v-if="!canManage"
                        class="mt-3 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400"
                    >
                        Você pode consultar saldos. Sacar e alterar PIX exigem permissão de gestão financeira.
                    </p>
                    <div v-if="canManage" class="mt-4 space-y-2">
                        <button
                            type="button"
                            class="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--color-primary)] px-4 py-3.5 text-sm font-semibold text-zinc-900 shadow-sm transition hover:opacity-90 disabled:opacity-50"
                            :disabled="!cajupayConnected || maxAvailable < minPayoutReais"
                            @click="showWithdrawForm = !showWithdrawForm"
                        >
                            <ArrowDownToLine class="h-5 w-5" />
                            Sacar
                        </button>
                        <button
                            type="button"
                            class="flex w-full items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            @click="showPixForm = !showPixForm"
                        >
                            <QrCode class="h-4 w-4" />
                            {{ pixKey ? 'Alterar chave PIX' : 'Cadastrar chave PIX' }}
                        </button>
                    </div>

                    <div v-if="canManage && showWithdrawForm && cajupayConnected" class="mt-4 space-y-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                        <p class="text-xs font-medium text-zinc-500">
                            Sacar de {{ walletLabels[selectedWallet] }} · {{ formatBRL(maxAvailable) }} disponível
                        </p>
                        <div
                            v-if="withdrawPixDestination"
                            class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 text-xs dark:border-zinc-700 dark:bg-zinc-800/50"
                        >
                            <p class="font-medium text-zinc-700 dark:text-zinc-300">Transferência PIX para</p>
                            <p class="mt-1 break-all font-mono text-sm text-zinc-900 dark:text-white">
                                {{ withdrawPixDestination }}
                            </p>
                        </div>
                        <p
                            v-else
                            class="rounded-lg border border-amber-200/80 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200"
                        >
                            Cadastre uma chave PIX abaixo antes de confirmar o saque.
                        </p>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="withdrawAll" type="checkbox" class="rounded border-zinc-300" />
                            Sacar tudo
                        </label>
                        <input
                            v-if="!withdrawAll"
                            v-model.number="payoutAmount"
                            type="number"
                            step="0.01"
                            :min="minPayoutReais"
                            :max="maxAvailable"
                            class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        />
                        <Button
                            type="button"
                            class="w-full"
                            :disabled="paying || maxAvailable < minPayoutReais || !withdrawPixDestination"
                            @click="requestPayout"
                        >
                            {{ paying ? 'Processando…' : 'Confirmar saque' }}
                        </Button>
                    </div>

                    <div v-if="canManage && showPixForm" class="mt-4 space-y-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                        <input
                            v-model="pixForm.pix_key"
                            type="text"
                            placeholder="Chave PIX"
                            class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        />
                        <div class="relative">
                            <select
                                v-model="pixForm.pix_key_type"
                                class="w-full appearance-none rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                            >
                                <option value="email">E-mail</option>
                                <option value="cpf">CPF</option>
                                <option value="cnpj">CNPJ</option>
                                <option value="phone">Telefone</option>
                                <option value="random">Aleatória (EVP)</option>
                            </select>
                            <ChevronDown class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                        </div>
                        <input
                            v-if="needsOwnerDocument"
                            v-model="pixForm.pix_owner_document"
                            type="text"
                            placeholder="CPF/CNPJ do titular"
                            class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        />
                        <Button type="button" class="w-full" :disabled="saving" @click="savePix">
                            {{ saving ? 'Salvando…' : 'Salvar' }}
                        </Button>
                    </div>
                </div>

                <div class="fin-panel p-5">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Resumo da carteira</h2>
                    <ul class="mt-4 space-y-3 text-sm">
                        <li class="flex justify-between">
                            <span class="text-zinc-500">Disponível</span>
                            <span class="font-medium text-emerald-600">{{ formatBRL(metrics.available) }}</span>
                        </li>
                        <li class="flex justify-between">
                            <span class="text-zinc-500">A liberar</span>
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ formatBRL(metrics.pending) }}</span>
                        </li>
                        <li class="flex justify-between">
                            <span class="text-zinc-500">Em saque</span>
                            <span class="font-medium text-violet-600">{{ formatBRL(metrics.reserved) }}</span>
                        </li>
                        <li class="flex justify-between border-t border-zinc-100 pt-3 dark:border-zinc-800">
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">Total (todas carteiras)</span>
                            <span class="font-bold text-zinc-900 dark:text-white">{{ formatBRL(metrics.totalAvailable) }}</span>
                        </li>
                    </ul>
                </div>

                <div v-if="metrics.paidOut > 0" class="fin-panel p-5">
                    <div class="flex items-center gap-2 text-xs text-zinc-500">
                        <Lock class="h-3.5 w-3.5" />
                        Já recebido nesta carteira
                    </div>
                    <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">{{ formatBRL(metrics.paidOut) }}</p>
                </div>
            </div>
        </div>

        <p
            v-if="payoutMsg"
            class="rounded-lg px-4 py-2 text-sm"
            :class="payoutMsg.includes('sucesso') || payoutMsg.includes('salva') ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-red-500/10 text-red-700 dark:text-red-400'"
        >
            {{ payoutMsg }}
        </p>

        <div v-if="showCommissionsTable && commissions.length" class="fin-panel overflow-hidden">
            <div class="border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Comissões</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[520px] text-left text-sm">
                    <thead class="bg-zinc-50/80 text-xs uppercase text-zinc-500 dark:bg-zinc-900/50">
                        <tr>
                            <th class="px-5 py-3 font-medium">Produto</th>
                            <th class="px-5 py-3 font-medium">Carteira</th>
                            <th class="px-5 py-3 font-medium">Valor</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 font-medium">Data</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="c in commissions" :key="c.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-900/30">
                            <td class="px-5 py-3 text-zinc-800 dark:text-zinc-200">{{ c.product_name || '—' }}</td>
                            <td class="px-5 py-3 text-zinc-500">{{ walletLabels[c.wallet_bucket] || c.wallet_bucket }}</td>
                            <td class="px-5 py-3 font-medium">{{ formatBRL(c.commission_amount) }}</td>
                            <td class="px-5 py-3">
                                <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs dark:bg-zinc-800">
                                    {{ statusLabels[c.status] || c.status }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-zinc-500">{{ formatDate(c.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
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
:root.dark .fin-metric-card,
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
:root.dark .fin-panel,
.dark .fin-panel {
    border-color: rgb(39 39 42);
    background: rgb(24 24 27 / 0.8);
}
.fin-metric-icon {
    display: flex;
    height: 2.5rem;
    width: 2.5rem;
    align-items: center;
    justify-content: center;
    border-radius: 0.75rem;
}
.fin-metric-icon--primary {
    background: color-mix(in srgb, var(--color-primary) 15%, transparent);
    color: var(--color-primary);
}
.fin-metric-icon--green {
    background: rgb(16 185 129 / 0.15);
    color: rgb(5 150 105);
}
.fin-metric-icon--red {
    background: rgb(239 68 68 / 0.15);
    color: rgb(220 38 38);
}
.fin-metric-icon--violet {
    background: rgb(139 92 246 / 0.15);
    color: rgb(124 58 237);
}
</style>
