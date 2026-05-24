<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import { FileText, RefreshCw, ExternalLink, Download } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    order: { type: Object, required: true },
    proof_document: { type: Object, default: null },
    snapshot: { type: Object, default: () => ({}) },
});

const generating = ref(false);

const snapshotJson = computed(() => {
    try {
        return JSON.stringify(props.snapshot ?? {}, null, 2);
    } catch {
        return String(props.snapshot ?? '');
    }
});

function generate() {
    if (generating.value) return;
    generating.value = true;
    router.post(`/vendas/${props.order.id}/comprovacao/gerar`, {}, {
        preserveScroll: true,
        onFinish: () => (generating.value = false),
    });
}
</script>

<template>
    <div class="mx-auto w-full max-w-6xl px-4 py-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="min-w-0">
                <div class="flex items-center gap-2 text-zinc-500">
                    <FileText class="h-4 w-4" />
                    <span class="text-sm">Log de Atividade do Pedido</span>
                </div>
                <h1 class="mt-1 truncate text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                    Pedido #{{ order.id }}
                </h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    {{ order?.buyer?.name ?? 'Comprador' }}
                    <span v-if="order?.buyer?.email">· {{ order.buyer.email }}</span>
                    <span v-if="order?.product?.name">· {{ order.product.name }}</span>
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                <button
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 disabled:opacity-60 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                    :disabled="generating"
                    @click="generate"
                >
                    <RefreshCw class="h-4 w-4" />
                    {{ generating ? 'Gerando...' : 'Gerar/atualizar dossiê' }}
                </button>

                <a
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-100 dark:hover:bg-zinc-900"
                    :href="`/vendas/${order.id}/comprovacao/pdf`"
                >
                    <Download class="h-4 w-4" />
                    Baixar PDF
                </a>

                <a
                    v-if="proof_document?.verify_url"
                    class="inline-flex items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-100 dark:hover:bg-zinc-900"
                    :href="proof_document.verify_url"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <ExternalLink class="h-4 w-4" />
                    Verificação pública
                </a>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Status</h2>
                <div class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <div><span class="font-medium">Pedido:</span> {{ order.status }}</div>
                    <div v-if="order.gateway"><span class="font-medium">Gateway:</span> {{ order.gateway }}</div>
                    <div v-if="order.gateway_id"><span class="font-medium">Transação:</span> {{ order.gateway_id }}</div>
                    <div v-if="order.customer_ip"><span class="font-medium">IP checkout:</span> {{ order.customer_ip }}</div>
                </div>

                <div v-if="proof_document" class="mt-4 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Documento</h3>
                    <div class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                        <div><span class="font-medium">Código:</span> {{ proof_document.public_code }}</div>
                        <div v-if="proof_document.generated_at"><span class="font-medium">Gerado em:</span> {{ proof_document.generated_at }}</div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Snapshot (JSON)</h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Este JSON é a base do PDF e da verificação pública (com mascaramento). Gere o dossiê para fixar um código.
                </p>
                <pre class="mt-3 max-h-[70vh] overflow-auto rounded-lg bg-zinc-50 p-3 text-xs text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">{{ snapshotJson }}</pre>
            </div>
        </div>
    </div>
</template>

