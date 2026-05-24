<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';
import ConversionPixels from '@/components/checkout/ConversionPixels.vue';
import { firePurchaseWhenReady } from '@/composables/useConversionPurchase';

defineOptions({ layout: null });

const conversionPixelsRef = ref(null);

const props = defineProps({
    redirect_url: { type: String, default: '/' },
    redirect_label: { type: String, default: 'Acessar área de membros' },
    subtitle: { type: String, default: 'Seu pedido foi registrado. Acesse o conteúdo pelo link abaixo.' },
    show_button: { type: Boolean, default: true },
    conversion_pixels: { type: Object, default: () => ({}) },
    order_id: { type: Number, default: null },
    order_amount: { type: Number, default: 0 },
    order_currency: { type: String, default: 'BRL' },
    meta_purchase_event_id: { type: String, default: '' },
    purchase_contents: { type: Array, default: () => [] },
});

async function onConversionPixelsReady() {
    if (!props.order_id || !(Number(props.order_amount) > 0)) return;
    const api = conversionPixelsRef.value;
    const eid =
        (props.meta_purchase_event_id || '').trim() || `getfy_purchase_${props.order_id}`;
    const cur =
        typeof props.order_currency === 'string' && props.order_currency.trim()
            ? props.order_currency.trim().toUpperCase()
            : 'BRL';
    await firePurchaseWhenReady(api, {
        order_id: props.order_id,
        amount: props.order_amount,
        currency: cur,
        meta_event_id: eid,
        purchase_contents: props.purchase_contents,
    });
}
</script>

<template>
    <ConversionPixels ref="conversionPixelsRef" :pixels="props.conversion_pixels" @ready="onConversionPixelsReady" />
    <Head>
        <title>Obrigado pela compra</title>
    </Head>
    <div class="min-h-screen flex flex-col items-center justify-center bg-zinc-50 px-4">
        <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                <CheckCircle2 class="h-8 w-8" />
            </div>
            <h1 class="mt-4 text-xl font-semibold text-zinc-900">
                Obrigado pela sua compra
            </h1>
            <p class="mt-2 text-sm text-zinc-600">
                {{ subtitle }}
            </p>
            <a
                v-if="show_button"
                :href="redirect_url"
                class="mt-6 inline-flex w-full justify-center rounded-xl bg-[var(--color-primary,#0ea5e9)] px-4 py-3 text-sm font-semibold text-white shadow-sm hover:opacity-90 transition-opacity"
            >
                {{ redirect_label }}
            </a>
        </div>
    </div>
</template>
