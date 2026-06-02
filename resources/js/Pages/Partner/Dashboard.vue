<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import DashboardPeriodFilter from '@/components/dashboard/DashboardPeriodFilter.vue';
import {
    CircleDollarSign,
    ShoppingCart,
    Wallet,
    Clock,
    Package,
    Eye,
    EyeOff,
    ArrowRight,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const page = usePage();
const valuesVisible = ref(true);
const isDarkMode = ref(false);

onMounted(() => {
    isDarkMode.value = document.documentElement.classList.contains('dark');
});

const props = defineProps({
    period: { type: String, default: 'hoje' },
    comissao_total: { type: Number, default: 0 },
    quantidade_vendas: { type: Number, default: 0 },
    ticket_medio_comissao: { type: Number, default: 0 },
    saldo_pendente: { type: Number, default: 0 },
    saldo_disponivel: { type: Number, default: 0 },
    quantidade_produtos: { type: Number, default: 0 },
    grafico_comissoes: { type: Array, default: () => [] },
    vendas_recentes: { type: Array, default: () => [] },
});

function setPeriod(value) {
    router.get('/parceiro', { period: value }, { preserveState: false });
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function displayCurrency(value) {
    return valuesVisible.value ? formatBRL(value) : '••••••';
}

function displayNumber(value) {
    return valuesVisible.value ? String(value) : '—';
}

function formatDate(iso) {
    if (!iso) return '—';
    try {
        return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function commissionStatusLabel(status) {
    if (status === 'pending') return 'Pendente';
    if (status === 'available') return 'Disponível';
    if (status === 'paid') return 'Pago';
    return status || '';
}

const chartPrimaryColor = computed(() => {
    const fromSettings = page.props.appSettings?.theme_primary;
    if (typeof fromSettings === 'string' && fromSettings.trim() !== '') {
        return fromSettings.trim();
    }
    if (typeof document !== 'undefined') {
        const css = getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim();
        if (css) return css;
    }
    return '#0ea5e9';
});

const chartSeries = computed(() => [
    {
        name: 'Comissões',
        data: valuesVisible.value
            ? props.grafico_comissoes.map((d) => d.total)
            : props.grafico_comissoes.map(() => 0),
    },
]);

const chartOptions = computed(() => {
    const primary = chartPrimaryColor.value;
    const isHourly = props.period === 'hoje' || props.period === 'ontem';

    return {
        chart: {
            type: 'area',
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'inherit',
            animations: { enabled: true, speed: 600 },
        },
        colors: [primary],
        dataLabels: {
            enabled: true,
            formatter: (v) => (valuesVisible.value && v > 0 ? formatBRL(v) : ''),
            style: { fontSize: '10px', colors: [primary] },
            offsetY: -4,
        },
        stroke: { curve: 'smooth', width: 2 },
        fill: {
            type: 'gradient',
            gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0, stops: [0, 100] },
        },
        xaxis: {
            categories: isHourly
                ? props.grafico_comissoes.map((d) => `${Number(d.data)}h`)
                : props.grafico_comissoes.map((d) => {
                      const [, m, day] = (d.data || '').split('-');
                      return day && m ? `${day}/${m}` : d.data;
                  }),
            labels: { style: { colors: '#71717a', fontSize: '11px' } },
        },
        yaxis: {
            labels: {
                style: { colors: '#71717a', fontSize: '11px' },
                formatter: (v) => formatBRL(v),
            },
        },
        grid: {
            borderColor: isDarkMode.value ? '#27272a' : '#e4e4e7',
            strokeDashArray: 4,
        },
        tooltip: {
            theme: isDarkMode.value ? 'dark' : 'light',
            y: { formatter: (v) => (valuesVisible.value ? formatBRL(v) : '••••••') },
        },
    };
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Dashboard</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Resumo das suas comissões e vendas como parceiro.
            </p>
        </div>

        <DashboardPeriodFilter :model-value="period" @update:model-value="setPeriod">
            <template #trailing>
                <button
                    type="button"
                    :aria-label="valuesVisible ? 'Ocultar valores' : 'Mostrar valores'"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-zinc-200/80 bg-zinc-100/90 text-zinc-500 transition-colors hover:text-zinc-800 dark:border-zinc-700/80 dark:bg-zinc-800/50 dark:text-zinc-400 dark:hover:text-zinc-200"
                    @click="valuesVisible = !valuesVisible"
                >
                    <Eye v-if="valuesVisible" class="h-5 w-5" />
                    <EyeOff v-else class="h-5 w-5" />
                </button>
            </template>
        </DashboardPeriodFilter>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
                    <div class="dash-metric-icon-sm"><CircleDollarSign class="h-4 w-4" /></div>
                    <span class="text-xs font-medium">Comissões no período</span>
                </div>
                <p class="mt-2 text-xl font-bold text-zinc-900 dark:text-white">{{ displayCurrency(comissao_total) }}</p>
                <p class="mt-0.5 text-xs text-zinc-500">Ticket médio: {{ displayCurrency(ticket_medio_comissao) }}</p>
            </div>
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
                    <div class="dash-metric-icon-sm"><ShoppingCart class="h-4 w-4" /></div>
                    <span class="text-xs font-medium">Vendas no período</span>
                </div>
                <p class="mt-2 text-xl font-bold text-zinc-900 dark:text-white">{{ displayNumber(quantidade_vendas) }}</p>
                <Link href="/parceiro/vendas" class="mt-0.5 inline-flex items-center gap-1 text-xs text-[var(--color-primary)] hover:underline">
                    Ver todas <ArrowRight class="h-3 w-3" />
                </Link>
            </div>
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
                    <div class="dash-metric-icon-sm"><Clock class="h-4 w-4" /></div>
                    <span class="text-xs font-medium">Saldo pendente</span>
                </div>
                <p class="mt-2 text-xl font-bold text-zinc-900 dark:text-white">{{ displayCurrency(saldo_pendente) }}</p>
                <p class="mt-0.5 text-xs text-zinc-500">Aguardando liberação</p>
            </div>
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
                    <div class="dash-metric-icon-sm"><Wallet class="h-4 w-4" /></div>
                    <span class="text-xs font-medium">Saldo disponível</span>
                </div>
                <p class="mt-2 text-xl font-bold text-zinc-900 dark:text-white">{{ displayCurrency(saldo_disponivel) }}</p>
                <Link href="/parceiro/financeiro" class="mt-0.5 inline-flex items-center gap-1 text-xs text-[var(--color-primary)] hover:underline">
                    Sacar <ArrowRight class="h-3 w-3" />
                </Link>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="panel-card-md lg:col-span-2">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Comissões no período</h2>
                <div v-if="grafico_comissoes.length" class="mt-4 -mx-1">
                    <VueApexCharts type="area" height="260" :options="chartOptions" :series="chartSeries" />
                </div>
                <p v-else class="mt-6 py-8 text-center text-sm text-zinc-500">Nenhuma comissão neste período.</p>
            </div>

            <div class="panel-card-md flex flex-col">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Atalhos</h2>
                </div>
                <ul class="mt-4 space-y-2">
                    <li>
                        <Link
                            href="/parceiro/produtos"
                            class="flex items-center justify-between rounded-lg border border-zinc-200/80 px-3 py-2.5 text-sm transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/80"
                        >
                            <span class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300">
                                <Package class="h-4 w-4 text-[var(--color-primary)]" />
                                Meus produtos
                            </span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ quantidade_produtos }}</span>
                        </Link>
                    </li>
                    <li>
                        <Link
                            href="/parceiro/vendas"
                            class="flex items-center justify-between rounded-lg border border-zinc-200/80 px-3 py-2.5 text-sm transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/80"
                        >
                            <span class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300">
                                <ShoppingCart class="h-4 w-4 text-[var(--color-primary)]" />
                                Vendas
                            </span>
                            <ArrowRight class="h-4 w-4 text-zinc-400" />
                        </Link>
                    </li>
                    <li>
                        <Link
                            href="/parceiro/financeiro"
                            class="flex items-center justify-between rounded-lg border border-zinc-200/80 px-3 py-2.5 text-sm transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/80"
                        >
                            <span class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300">
                                <Wallet class="h-4 w-4 text-[var(--color-primary)]" />
                                Financeiro
                            </span>
                            <ArrowRight class="h-4 w-4 text-zinc-400" />
                        </Link>
                    </li>
                </ul>
            </div>
        </div>

        <div class="panel-card-md">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Vendas recentes</h2>
                <Link href="/parceiro/vendas" class="text-xs font-medium text-[var(--color-primary)] hover:underline">
                    Ver todas
                </Link>
            </div>
            <ul v-if="vendas_recentes.length" class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                <li
                    v-for="v in vendas_recentes"
                    :key="v.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ v.product_name || 'Produto' }}</p>
                        <p v-if="v.buyer_name || v.buyer_email" class="truncate text-xs text-zinc-500">
                            {{ v.buyer_name || v.buyer_email }}
                            <span v-if="v.buyer_masked" class="text-zinc-400"> (mascarado)</span>
                        </p>
                        <p class="text-xs text-zinc-500">{{ formatDate(v.created_at) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ displayCurrency(v.commission_amount) }}</p>
                        <p class="text-[11px] text-zinc-500">{{ commissionStatusLabel(v.commission_status) }}</p>
                    </div>
                </li>
            </ul>
            <p v-else class="mt-6 py-6 text-center text-sm text-zinc-500">Nenhuma venda registrada ainda.</p>
        </div>
    </div>
</template>
