<script setup>
import { ref } from 'vue';
import axios from 'axios';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import { Download, Filter } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    products: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const downloading = ref(false);
const error = ref(null);

const form = ref({
    date_from: props.filters?.date_from || '',
    date_to: props.filters?.date_to || '',
    product_id: props.filters?.product_id || '',
    payment_method: props.filters?.payment_method || '',
    status: props.filters?.status || 'completed',
});

function maskDateBr(raw) {
    const digits = String(raw || '').replace(/\D/g, '').slice(0, 8);
    const dd = digits.slice(0, 2);
    const mm = digits.slice(2, 4);
    const yyyy = digits.slice(4, 8);
    if (digits.length <= 2) return dd;
    if (digits.length <= 4) return `${dd}/${mm}`;
    return `${dd}/${mm}/${yyyy}`;
}

function toIsoDateBr(value) {
    const v = String(value || '').trim();
    if (!v) return null;
    // Accept dd/mm/yyyy
    const m = v.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (!m) return null;
    const dd = Number(m[1]);
    const mm = Number(m[2]);
    const yyyy = Number(m[3]);
    if (mm < 1 || mm > 12 || dd < 1 || dd > 31 || yyyy < 1900) return null;
    return `${String(yyyy).padStart(4, '0')}-${String(mm).padStart(2, '0')}-${String(dd).padStart(2, '0')}`;
}

async function openPdf() {
    error.value = null;
    if (downloading.value) return;
    if (!form.value.date_from || !form.value.date_to) {
        error.value = 'Informe data inicial e data final.';
        return;
    }
    const isoFrom = toIsoDateBr(form.value.date_from);
    const isoTo = toIsoDateBr(form.value.date_to);
    if (!isoFrom || !isoTo) {
        error.value = 'Formato de data inválido. Use dd/mm/aaaa.';
        return;
    }
    const payload = {
        ...form.value,
        date_from: isoFrom,
        date_to: isoTo,
    };

    downloading.value = true;
    try {
        const res = await axios.post('/vendas/comprovacao/exportar/pdf', payload, {
            responseType: 'blob',
        });

        const blob = new Blob([res.data], { type: 'application/pdf' });
        const url = window.URL.createObjectURL(blob);
        window.open(url, '_blank', 'noopener,noreferrer');
        // revoke later (let browser load it)
        setTimeout(() => window.URL.revokeObjectURL(url), 30_000);
    } catch (e) {
        error.value = e?.response?.data?.message || 'Falha ao gerar PDF. Tente novamente.';
    } finally {
        downloading.value = false;
    }
}
</script>

<template>
    <div class="mx-auto w-full max-w-4xl space-y-6 px-4 py-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-2 text-zinc-500">
                    <Filter class="h-4 w-4" />
                    <span class="text-sm">Comprovação</span>
                    <span
                        class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-zinc-200 text-[11px] text-zinc-500 dark:border-zinc-700 dark:text-zinc-400"
                        title="Exporta um PDF com dossiês de comprovação (dados do comprador + evidências de entrega/atividade). Ideal para anexar em gateways em caso de MED/chargeback/auditoria."
                    >
                        ?
                    </span>
                </div>
                <h1 class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Exportar comprovações (PDF)</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    Gera um PDF com comprovações (1 página por pedido) para pedidos filtrados (máximo 200 por exportação).
                </p>
            </div>

            <button
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                :disabled="downloading"
                @click="openPdf"
            >
                <Download class="h-4 w-4" />
                {{ downloading ? 'Gerando...' : 'Abrir PDF' }}
            </button>
        </div>

        <div v-if="error" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
            {{ error }}
        </div>

        <div class="grid grid-cols-1 gap-4 rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950 sm:grid-cols-2">
            <div class="space-y-1">
                <label class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Data de</label>
                <input
                    :value="form.date_from"
                    type="text"
                    inputmode="numeric"
                    placeholder="dd/mm/aaaa"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100"
                    @input="(e) => (form.date_from = maskDateBr(e.target.value))"
                />
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Data até</label>
                <input
                    :value="form.date_to"
                    type="text"
                    inputmode="numeric"
                    placeholder="dd/mm/aaaa"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100"
                    @input="(e) => (form.date_to = maskDateBr(e.target.value))"
                />
            </div>

            <div class="space-y-1">
                <label class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Produto (opcional)</label>
                <select
                    v-model="form.product_id"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100"
                >
                    <option value="">Todos</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
            </div>

            <div class="space-y-1">
                <label class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Forma de pagamento (opcional)</label>
                <select
                    v-model="form.payment_method"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100"
                >
                    <option value="">Todas</option>
                    <option value="pix">PIX</option>
                    <option value="card">Cartão</option>
                    <option value="boleto">Boleto</option>
                </select>
            </div>

            <div class="space-y-1 sm:col-span-2">
                <label class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Status</label>
                <select
                    v-model="form.status"
                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100"
                >
                    <option value="completed">Pago</option>
                    <option value="pending">Pendente</option>
                    <option value="disputed">MED</option>
                    <option value="cancelled">Cancelado</option>
                    <option value="refunded">Reembolsado</option>
                    <option value="all">Todos</option>
                </select>
            </div>
        </div>
    </div>
</template>

