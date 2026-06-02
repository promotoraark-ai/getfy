<script setup>
import { ref, computed, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import {
    CircleDollarSign,
    ShoppingCart,
    CreditCard,
    RotateCcw,
    Package,
    Users,
    TrendingUp,
    Eye,
    EyeOff,
    XCircle,
    Download,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const valuesVisible = ref(true);
const isDarkMode = ref(false);

onMounted(() => {
    isDarkMode.value = document.documentElement.classList.contains('dark');
});

const props = defineProps({
    period: { type: String, default: 'hoje' },
    receita_total: { type: Number, default: 0 },
    quantidade_vendas: { type: Number, default: 0 },
    ticket_medio: { type: Number, default: 0 },
    total_alunos: { type: Number, default: 0 },
    total_produtos: { type: Number, default: 0 },
    formas_pagamento: { type: Array, default: () => [] },
    grafico_receita: { type: Array, default: () => [] },
    receita_por_produto: { type: Array, default: () => [] },
    abandonados_visit: { type: Number, default: 0 },
    abandonados_form: { type: Number, default: 0 },
    abandonados_total: { type: Number, default: 0 },
    taxa_conversao: { type: Number, default: 0 },
    abandonados_com_email: { type: Array, default: () => [] },
    reembolsos_count: { type: Number, default: 0 },
    reembolsos_total: { type: Number, default: 0 },
    meta_export_products: { type: Array, default: () => [] },
});

const META_HEADER =
    'email,email,email,phone,phone,phone,madid,fn,ln,zip,ct,st,country,dob,doby,gen,age,uid,value';

const showModalCompradores = ref(false);
const showModalAbandonos = ref(false);
const productCompradores = ref('');
const productAbandonos = ref('');

function openModalCompradores() {
    productCompradores.value = props.meta_export_products[0]?.id ?? '';
    showModalCompradores.value = true;
}

function openModalAbandonos() {
    productAbandonos.value = props.meta_export_products[0]?.id ?? '';
    showModalAbandonos.value = true;
}

function downloadMetaCompradores() {
    if (!productCompradores.value) return;
    window.location.href = `/relatorios/export/meta-compradores?product_id=${encodeURIComponent(productCompradores.value)}`;
}

function downloadMetaAbandonos() {
    if (!productAbandonos.value) return;
    window.location.href = `/relatorios/export/meta-abandonos?product_id=${encodeURIComponent(productAbandonos.value)}`;
}

const periodOptions = [
    { value: 'hoje', label: 'Hoje' },
    { value: 'ontem', label: 'Ontem' },
    { value: '7dias', label: '7 dias' },
    { value: 'mes', label: 'Mês' },
    { value: 'ano', label: 'Ano' },
    { value: 'total', label: 'Total' },
];

function setPeriod(value) {
    router.get('/relatorios', { period: value }, { preserveState: false });
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
    if (!iso) return '–';
    const d = new Date(iso);
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const chartSeriesReceita = computed(() => [
    {
        name: 'Receita',
        data: valuesVisible.value ? props.grafico_receita.map((d) => d.total) : props.grafico_receita.map(() => 0),
    },
]);

const chartOptionsReceita = computed(() => ({
    chart: { type: 'area', toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
    colors: ['var(--color-primary)'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { shadeIntensity: 0.2, opacityFrom: 0.4, opacityTo: 0.05 } },
    xaxis: {
        categories: props.grafico_receita.map((d) => {
            const [y, m, day] = (d.data || '').split('-');
            return day && m ? `${day}/${m}` : d.data;
        }),
        labels: { style: { colors: '#71717a' } },
    },
    yaxis: { labels: { style: { colors: '#71717a' }, formatter: (v) => formatBRL(v) } },
    grid: { borderColor: 'var(--chart-grid, #e4e4e7)', strokeDashArray: 4, xaxis: { lines: { show: false } } },
    tooltip: {
        theme: isDarkMode.value ? 'dark' : 'light',
        y: { formatter: (v) => (valuesVisible.value ? formatBRL(v) : '••••••') },
    },
}));

const chartSeriesProduto = computed(() => [
    {
        name: 'Receita',
        data: valuesVisible.value ? props.receita_por_produto.map((d) => d.total) : props.receita_por_produto.map(() => 0),
    },
]);

const chartOptionsProduto = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['var(--color-primary)'],
    dataLabels: { enabled: false },
    plotOptions: { bar: { horizontal: true } },
    xaxis: {
        categories: props.receita_por_produto.map((d) => (d.product_name || 'Produto').slice(0, 35)),
        labels: { style: { colors: '#71717a' }, formatter: (v) => formatBRL(v) },
    },
    yaxis: { labels: { style: { colors: '#71717a' }, maxWidth: 140 } },
    grid: { borderColor: 'var(--chart-grid, #e4e4e7)', strokeDashArray: 4 },
    tooltip: {
        theme: isDarkMode.value ? 'dark' : 'light',
        y: { formatter: (v) => (valuesVisible.value ? formatBRL(v) : '••••••') },
    },
}));

const formasFiltradas = computed(() => props.formas_pagamento.filter((fp) => fp.total > 0));

const chartSeriesFormas = computed(() =>
    valuesVisible.value ? formasFiltradas.value.map((fp) => fp.total) : formasFiltradas.value.map(() => 0)
);

const chartOptionsFormas = computed(() => ({
    chart: { type: 'donut', fontFamily: 'inherit' },
    labels: formasFiltradas.value.map((fp) => fp.label),
    colors: ['#10b981', '#6366f1', '#f59e0b', '#ef4444', '#8b5cf6'].slice(0, formasFiltradas.value.length) || ['#6366f1'],
    dataLabels: { enabled: false },
    legend: { position: 'bottom' },
    tooltip: { theme: isDarkMode.value ? 'dark' : 'light' },
}));
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <nav class="flex flex-wrap items-center gap-1" aria-label="Período">
                <button
                    v-for="opt in periodOptions"
                    :key="opt.value"
                    type="button"
                    :aria-current="period === opt.value ? 'true' : undefined"
                    class="rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                    :class="period === opt.value ? 'bg-[var(--color-primary)] text-white' : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                    @click="setPeriod(opt.value)"
                >
                    {{ opt.label }}
                </button>
            </nav>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-800 shadow-sm transition-colors hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700"
                    :disabled="!meta_export_products.length"
                    @click="openModalCompradores"
                >
                    <Download class="h-4 w-4 shrink-0" aria-hidden="true" />
                    CSV clientes existentes
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-800 shadow-sm transition-colors hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700"
                    :disabled="!meta_export_products.length"
                    @click="openModalAbandonos"
                >
                    <Download class="h-4 w-4 shrink-0" aria-hidden="true" />
                    CSV clientes engajados
                </button>
                <button
                    type="button"
                    :aria-label="valuesVisible ? 'Ocultar valores' : 'Mostrar valores'"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    @click="valuesVisible = !valuesVisible"
                >
                    <Eye v-if="valuesVisible" class="h-5 w-5" aria-hidden="true" />
                    <EyeOff v-else class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
        </div>

        <p
            v-if="!meta_export_products.length"
            class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
        >
            Não há produtos disponíveis para exportação (verifique permissões da equipe ou cadastre um produto).
        </p>

        <div
            v-if="showModalCompradores"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="meta-modal-compradores-title"
            @click.self="showModalCompradores = false"
        >
            <div
                class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-zinc-200 bg-white p-5 shadow-xl dark:border-zinc-600 dark:bg-zinc-900"
                @click.stop
            >
                <h2 id="meta-modal-compradores-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Baixar CSV — clientes existentes (Meta Ads)
                </h2>
                <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                    Será gerado um arquivo no formato de lista de clientes do Meta, com <strong>compradores que concluíram o
                        pagamento</strong> do produto selecionado nos <strong>últimos 180 dias</strong>. Em caso de mais de um
                    pedido no período, usa-se o <strong>pedido mais recente</strong> por e-mail (valor na coluna
                    <code class="rounded bg-zinc-100 px-1 text-xs dark:bg-zinc-800">value</code>).
                </p>
                <label class="mt-4 block text-sm font-medium text-zinc-700 dark:text-zinc-200" for="meta-product-compradores"
                    >Produto</label
                >
                <select
                    id="meta-product-compradores"
                    v-model="productCompradores"
                    class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option v-for="p in meta_export_products" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    Cabeçalho do arquivo: {{ META_HEADER }}
                </p>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        @click="showModalCompradores = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        :disabled="!productCompradores"
                        @click="downloadMetaCompradores"
                    >
                        Baixar CSV
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="showModalAbandonos"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="meta-modal-abandonos-title"
            @click.self="showModalAbandonos = false"
        >
            <div
                class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-zinc-200 bg-white p-5 shadow-xl dark:border-zinc-600 dark:bg-zinc-900"
                @click.stop
            >
                <h2 id="meta-modal-abandonos-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Baixar CSV — clientes engajados (Meta Ads)
                </h2>
                <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                    Lista de quem <strong>iniciou o checkout</strong> (formulário), <strong>não concluiu a compra</strong> no
                    fluxo da sessão, com sessão criada nos <strong>últimos 180 dias</strong>, após o período de graça de
                    abandono. <strong>Não entram</strong> e-mails que tenham <strong>pedido concluído do mesmo produto
                        depois</strong> do momento do abandono (última interação no formulário).
                </p>
                <label class="mt-4 block text-sm font-medium text-zinc-700 dark:text-zinc-200" for="meta-product-abandonos"
                    >Produto</label
                >
                <select
                    id="meta-product-abandonos"
                    v-model="productAbandonos"
                    class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option v-for="p in meta_export_products" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    Cabeçalho do arquivo: {{ META_HEADER }}
                </p>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        @click="showModalAbandonos = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        :disabled="!productAbandonos"
                        @click="downloadMetaAbandonos"
                    >
                        Baixar CSV
                    </button>
                </div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <CircleDollarSign class="h-5 w-5" />
                    <span class="text-sm font-medium">Receita total</span>
                </div>
                <p class="mt-2 text-xl font-bold text-zinc-900 dark:text-white">{{ displayCurrency(receita_total) }}</p>
            </div>
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <ShoppingCart class="h-5 w-5" />
                    <span class="text-sm font-medium">Vendas</span>
                </div>
                <p class="mt-2 text-xl font-bold text-zinc-900 dark:text-white">{{ displayNumber(quantidade_vendas) }}</p>
            </div>
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <TrendingUp class="h-5 w-5" />
                    <span class="text-sm font-medium">Ticket médio</span>
                </div>
                <p class="mt-2 text-xl font-bold text-zinc-900 dark:text-white">{{ displayCurrency(ticket_medio) }}</p>
            </div>
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <Users class="h-5 w-5" />
                    <span class="text-sm font-medium">Alunos</span>
                </div>
                <p class="mt-2 text-xl font-bold text-zinc-900 dark:text-white">{{ displayNumber(total_alunos) }}</p>
            </div>
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <Package class="h-5 w-5" />
                    <span class="text-sm font-medium">Produtos</span>
                </div>
                <p class="mt-2 text-xl font-bold text-zinc-900 dark:text-white">{{ displayNumber(total_produtos) }}</p>
            </div>
            <div class="panel-card-md">
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <XCircle class="h-5 w-5" />
                    <span class="text-sm font-medium">Vendas abandonadas</span>
                </div>
                <p class="mt-2 text-xl font-bold text-zinc-900 dark:text-white">{{ displayNumber(abandonados_total) }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Taxa: {{ valuesVisible ? `${taxa_conversao}%` : '—' }} conversão</p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="panel-card-md">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Receita por período</h2>
                <div class="mt-4 min-h-[260px]">
                    <VueApexCharts
                        v-if="grafico_receita.length"
                        type="area"
                        height="260"
                        :options="chartOptionsReceita"
                        :series="chartSeriesReceita"
                    />
                    <p v-else class="flex h-[260px] items-center justify-center text-sm text-zinc-500 dark:text-zinc-400">
                        Nenhum dado no período
                    </p>
                </div>
            </div>
            <div class="panel-card-md">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Receita por produto (top 10)</h2>
                <div class="mt-4 min-h-[260px]">
                    <VueApexCharts
                        v-if="receita_por_produto.length"
                        type="bar"
                        height="260"
                        :options="chartOptionsProduto"
                        :series="chartSeriesProduto"
                    />
                    <p v-else class="flex h-[260px] items-center justify-center text-sm text-zinc-500 dark:text-zinc-400">
                        Nenhum dado no período
                    </p>
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="panel-card-md lg:col-span-2">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <CreditCard class="h-4 w-4 text-zinc-500" />
                    Formas de pagamento
                </h2>
                <ul class="mt-4 space-y-3">
                    <li
                        v-for="fp in formas_pagamento"
                        :key="fp.metodo"
                        class="flex items-center justify-between border-b border-zinc-200/60 py-2 last:border-0 dark:border-zinc-700/60"
                    >
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ fp.label }}</span>
                        <span class="text-sm font-medium text-zinc-900 dark:text-white">
                            {{ displayCurrency(fp.total) }}
                            <span class="font-normal text-zinc-500">({{ displayNumber(fp.quantidade) }})</span>
                        </span>
                    </li>
                    <li v-if="!formas_pagamento.length" class="py-4 text-center text-sm text-zinc-500 dark:text-zinc-400">
                        Nenhum pagamento no período
                    </li>
                </ul>
            </div>
            <div class="space-y-4">
                <div class="panel-card-md">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Distribuição</h2>
                    <div class="mt-4 min-h-[160px]">
                        <VueApexCharts
                            v-if="formasFiltradas.length"
                            type="donut"
                            height="180"
                            :options="chartOptionsFormas"
                            :series="chartSeriesFormas"
                        />
                        <p v-else class="flex h-[160px] items-center justify-center text-sm text-zinc-500 dark:text-zinc-400">
                            Sem dados
                        </p>
                    </div>
                </div>
                <div class="panel-card-sm">
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <RotateCcw class="h-4 w-4" />
                        <span class="text-sm font-medium">Reembolsos</span>
                    </div>
                    <p class="mt-2 text-lg font-bold text-zinc-900 dark:text-white">{{ displayCurrency(reembolsos_total) }}</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ displayNumber(reembolsos_count) }} pedido(s)</p>
                </div>
            </div>
        </div>

        <div class="panel-card-md">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                <XCircle class="h-4 w-4 text-zinc-500" />
                Vendas abandonadas com e-mail (para recuperação)
            </h2>
            <div class="mt-4 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-100/80 dark:bg-zinc-800/80">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">E-mail</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Nome</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Produto</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Atualizado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <tr
                            v-for="a in abandonados_com_email"
                            :key="a.id"
                            class="bg-white dark:bg-zinc-800/60"
                        >
                            <td class="px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ a.email }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ a.name || '–' }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ a.product_name }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ formatDate(a.updated_at) }}</td>
                        </tr>
                        <tr v-if="!abandonados_com_email.length" class="bg-white dark:bg-zinc-800/60">
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                Nenhum abandono com e-mail no período
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
