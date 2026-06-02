<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import {
    Eye,
    EyeOff,
    ShoppingCart,
    CircleDollarSign,
    Search,
    X,
    ChevronLeft,
    ChevronRight,
    Lock,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    vendas: { type: Object, default: () => ({ data: [], links: [] }) },
    stats: { type: Object, default: () => ({}) },
    status_filter: { type: String, default: 'todas' },
    filters: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
});

const valuesVisible = ref(true);
const vendasList = computed(() => props.vendas?.data ?? []);

const filterOptions = [
    { value: 'aprovadas', label: 'Aprovadas' },
    { value: 'med', label: 'MED' },
    { value: 'todas', label: 'Todas' },
];

const periodOptions = [
    { value: 'all', label: 'Todo período' },
    { value: 'today', label: 'Hoje' },
    { value: '7d', label: 'Últimos 7 dias' },
    { value: '30d', label: 'Últimos 30 dias' },
    { value: 'this_month', label: 'Este mês' },
    { value: 'last_month', label: 'Mês passado' },
    { value: 'custom', label: 'Personalizado' },
];

const paymentStatusOptions = [
    { value: 'all', label: 'Todos status' },
    { value: 'completed', label: 'Pago' },
    { value: 'pending', label: 'Pendente' },
    { value: 'disputed', label: 'MED' },
    { value: 'cancelled', label: 'Cancelado' },
    { value: 'refunded', label: 'Reembolsado' },
];

const filterForm = ref({
    q: props.filters?.q ?? '',
    period: props.filters?.period ?? 'all',
    date_from: props.filters?.date_from ?? '',
    date_to: props.filters?.date_to ?? '',
    product_id: props.filters?.product_id ?? '',
    payment_status: props.filters?.payment_status ?? 'all',
});

let searchTimer = null;

function buildQuery() {
    const params = { status_filter: props.status_filter };
    if (filterForm.value.q?.trim()) params.q = filterForm.value.q.trim();
    if (filterForm.value.period && filterForm.value.period !== 'all') params.period = filterForm.value.period;
    if (filterForm.value.date_from) params.date_from = filterForm.value.date_from;
    if (filterForm.value.date_to) params.date_to = filterForm.value.date_to;
    if (filterForm.value.product_id) params.product_id = filterForm.value.product_id;
    if (filterForm.value.payment_status && filterForm.value.payment_status !== 'all') {
        params.payment_status = filterForm.value.payment_status;
    }
    return params;
}

function applyFilters() {
    router.get('/parceiro/vendas', buildQuery(), { preserveState: true, replace: true });
}

function setStatusFilter(value) {
    router.get('/parceiro/vendas', { ...buildQuery(), status_filter: value }, { preserveState: true });
}

function onSearchInput() {
    const q = (filterForm.value.q ?? '').trim();
    if (q !== '' && q.length < 3) return;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        applyFilters();
        searchTimer = null;
    }, 600);
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function formatMoney(value, currency = 'BRL') {
    try {
        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: currency || 'BRL' }).format(value ?? 0);
    } catch {
        return formatBRL(value);
    }
}

function displayMoney(value, currency = 'BRL') {
    return valuesVisible.value ? formatMoney(value, currency) : '••••••';
}

function displayNumber(value) {
    return valuesVisible.value ? String(value) : '—';
}

function statusBadgeLabel(status) {
    const map = {
        completed: 'Pago',
        pending: 'Pendente',
        disputed: 'MED',
        cancelled: 'Cancelado',
        refunded: 'Reembolsado',
    };
    return map[status] || status || '—';
}

function statusBadgeClass(status) {
    if (status === 'completed') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300';
    if (status === 'pending') return 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300';
    if (status === 'disputed') return 'bg-orange-100 text-orange-800 dark:bg-orange-950/50 dark:text-orange-300';
    if (status === 'refunded' || status === 'cancelled') return 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300';
    return 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400';
}

function commissionStatusLabel(status) {
    if (status === 'awaiting_payment') return 'Aguardando pagamento';
    if (status === 'allocating') return 'Calculando comissão';
    if (status === 'pending') return 'Comissão pendente';
    if (status === 'available') return 'Comissão disponível';
    if (status === 'paid') return 'Comissão paga';
    return status || '';
}

const paginationPrev = computed(() => props.vendas?.links?.[0] ?? null);
const paginationNext = computed(() => {
    const links = props.vendas?.links ?? [];
    return links.length > 1 ? links[links.length - 1] : null;
});
const paginationPages = computed(() => {
    const links = props.vendas?.links ?? [];
    if (links.length <= 2) return [];
    return links.slice(1, -1);
});

function visitPaginationPage(url) {
    if (!url) return;
    router.visit(url, { preserveState: true });
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Minhas vendas</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Vendas atribuídas a você como parceiro. Dados do comprador dependem da configuração do produtor.
            </p>
        </div>

        <div class="flex items-center justify-end">
            <button
                type="button"
                class="flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                :aria-label="valuesVisible ? 'Ocultar valores' : 'Mostrar valores'"
                @click="valuesVisible = !valuesVisible"
            >
                <Eye v-if="valuesVisible" class="h-5 w-5" />
                <EyeOff v-else class="h-5 w-5" />
            </button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-500">
                    <ShoppingCart class="h-5 w-5" />
                    <span class="text-sm font-medium">Vendas encontradas</span>
                </div>
                <p class="mt-2 text-2xl font-bold">{{ displayNumber(stats.vendas_encontradas ?? 0) }}</p>
            </div>
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-500">
                    <CircleDollarSign class="h-5 w-5" />
                    <span class="text-sm font-medium">Suas comissões (filtro)</span>
                </div>
                <p class="mt-2 text-2xl font-bold">{{ displayMoney(stats.comissao_total) }}</p>
            </div>
        </div>

        <nav class="inline-flex rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80" aria-label="Filtrar vendas">
            <button
                v-for="opt in filterOptions"
                :key="opt.value"
                type="button"
                :class="[
                    'rounded-lg px-4 py-2 text-sm font-medium transition',
                    status_filter === opt.value
                        ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400',
                ]"
                @click="setStatusFilter(opt.value)"
            >
                {{ opt.label }}
            </button>
        </nav>

        <div class="flex flex-wrap items-end gap-3">
            <div class="relative min-w-[200px] flex-1 max-w-xl">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                <input
                    v-model="filterForm.q"
                    type="text"
                    placeholder="Buscar pedido, e-mail, produto..."
                    class="w-full rounded-xl border border-zinc-200 bg-white py-2 pl-10 pr-10 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                    @input="onSearchInput"
                />
                <button
                    v-if="filterForm.q"
                    type="button"
                    class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-zinc-400"
                    @click="filterForm.q = ''; applyFilters()"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>
            <select
                v-model="filterForm.period"
                class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                @change="applyFilters"
            >
                <option v-for="o in periodOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
            <select
                v-model="filterForm.product_id"
                class="max-w-[200px] rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                @change="applyFilters"
            >
                <option value="">Todos produtos</option>
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <select
                v-model="filterForm.payment_status"
                class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                @change="applyFilters"
            >
                <option v-for="o in paymentStatusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
        </div>

        <div v-if="filterForm.period === 'custom'" class="flex flex-wrap gap-3">
            <input v-model="filterForm.date_from" type="date" class="rounded-xl border px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" @change="applyFilters" />
            <input v-model="filterForm.date_to" type="date" class="rounded-xl border px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" @change="applyFilters" />
        </div>

        <div class="panel-table overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Data</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Produto</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Comprador</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Valor venda</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Sua comissão</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    <tr v-for="v in vendasList" :key="v.id" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600">
                            {{ v.created_at ? new Date(v.created_at).toLocaleString('pt-BR') : '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">
                            {{ v.product_display_name ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-start gap-1.5">
                                <Lock v-if="v.buyer_masked" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-zinc-400" title="Dados mascarados" />
                                <div>
                                    <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ v.buyer_name ?? '—' }}</p>
                                    <p class="text-xs text-zinc-500">{{ v.buyer_email ?? '—' }}</p>
                                    <p v-if="v.buyer_phone" class="text-xs text-zinc-500">{{ v.buyer_phone }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="['inline-flex rounded-full px-2 py-0.5 text-xs font-medium', statusBadgeClass(v.status)]">
                                {{ statusBadgeLabel(v.status) }}
                            </span>
                            <p class="mt-1 text-xs text-zinc-500">{{ v.gateway_label }}</p>
                        </td>
                        <td class="px-4 py-3 text-sm tabular-nums">{{ displayMoney(v.amount_total ?? v.amount, v.currency) }}</td>
                        <td class="px-4 py-3">
                            <p class="text-sm font-semibold tabular-nums text-[var(--color-primary)]">
                                {{ displayMoney(v.commission_amount) }}
                                <span
                                    v-if="v.commission_is_estimated"
                                    class="ml-1 text-xs font-normal text-zinc-500"
                                    title="Valor estimado até confirmação do pagamento"
                                >*</span>
                            </p>
                            <p class="text-xs text-zinc-500">
                                {{ commissionStatusLabel(v.commission_status) }}
                                <template v-if="v.commission_percent != null"> · {{ v.commission_percent }}%</template>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!vendasList.length" class="px-4 py-12 text-center text-sm text-zinc-500">Nenhuma venda encontrada.</p>
        </div>

        <div v-if="vendas?.links?.length > 3" class="flex items-center justify-center gap-1">
            <button
                type="button"
                :disabled="!paginationPrev?.url"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border text-sm disabled:opacity-40"
                @click="visitPaginationPage(paginationPrev?.url)"
            >
                <ChevronLeft class="h-4 w-4" />
            </button>
            <button
                v-for="(link, i) in paginationPages"
                :key="i"
                type="button"
                :disabled="!link.url"
                class="min-w-9 rounded-lg px-3 py-2 text-sm"
                :class="link.active ? 'bg-[var(--color-primary)] text-white' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                @click="visitPaginationPage(link.url)"
                v-html="link.label"
            />
            <button
                type="button"
                :disabled="!paginationNext?.url"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border text-sm disabled:opacity-40"
                @click="visitPaginationPage(paginationNext?.url)"
            >
                <ChevronRight class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>
