<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

defineOptions({ layout: null });

const props = defineProps({
    order_id: { type: Number, required: true },
    order_status: { type: String, default: 'pending' },
    order_amount: { type: Number, default: 0 },
    order_currency: { type: String, default: 'BRL' },
    tenant_return_url: { type: String, default: null },
});

const isPaid = computed(() => ['completed', 'paid', 'approved'].includes((props.order_status || '').toLowerCase()));
const isFailed = computed(() => ['cancelled', 'rejected', 'failed', 'refunded'].includes((props.order_status || '').toLowerCase()));

const title = computed(() => {
    if (isPaid.value) return 'Pagamento confirmado';
    if (isFailed.value) return 'Pagamento não concluído';
    return 'Pagamento em processamento';
});

const subtitle = computed(() => {
    if (isPaid.value) return 'Recebemos seu pagamento. Você já pode fechar esta página.';
    if (isFailed.value) return 'Não conseguimos concluir este pagamento. Você pode tentar novamente.';
    return 'Estamos confirmando seu pagamento. Em alguns instantes ele será processado.';
});

const formattedAmount = computed(() => {
    const value = Number(props.order_amount || 0);
    if (!Number.isFinite(value) || value <= 0) return null;
    try {
        return new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: (props.order_currency || 'BRL').toUpperCase(),
        }).format(value);
    } catch (_e) {
        return `${value.toFixed(2)} ${props.order_currency || 'BRL'}`;
    }
});

function goBack() {
    if (props.tenant_return_url) {
        window.location.href = props.tenant_return_url;
        return;
    }
    window.location.href = '/';
}
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-gray-100 flex items-center justify-center p-6">
        <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-lg text-center">
            <div
                class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full"
                :class="{
                    'bg-green-100 text-green-700': isPaid,
                    'bg-red-100 text-red-700': isFailed,
                    'bg-amber-100 text-amber-700': !isPaid && !isFailed,
                }"
            >
                <svg v-if="isPaid" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
                <svg v-else-if="isFailed" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
                <span v-else class="inline-block h-5 w-5 animate-spin rounded-full border-2 border-current border-t-transparent" />
            </div>
            <h1 class="text-lg font-bold text-gray-900">{{ title }}</h1>
            <p class="mt-2 text-sm text-gray-600">{{ subtitle }}</p>

            <dl class="mt-5 space-y-2 text-left text-xs text-gray-600">
                <div class="flex justify-between">
                    <dt>Pedido</dt>
                    <dd class="font-mono text-gray-900">#{{ props.order_id }}</dd>
                </div>
                <div v-if="formattedAmount" class="flex justify-between">
                    <dt>Valor</dt>
                    <dd class="font-semibold text-gray-900">{{ formattedAmount }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>Status</dt>
                    <dd class="font-medium text-gray-900 capitalize">{{ props.order_status }}</dd>
                </div>
            </dl>

            <button
                type="button"
                class="mt-6 w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90"
                @click="goBack"
            >
                {{ props.tenant_return_url ? 'Voltar para a loja' : 'Voltar' }}
            </button>
            <p v-if="props.tenant_return_url" class="mt-3 text-xs text-gray-500 break-all">{{ props.tenant_return_url }}</p>
        </div>
    </div>
</template>
