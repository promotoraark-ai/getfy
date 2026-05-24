<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import ConversionPixels from '@/components/checkout/ConversionPixels.vue';
import { firePurchaseWhenReady } from '@/composables/useConversionPurchase';

defineOptions({ layout: null });

const props = defineProps({
    order_id: { type: Number, required: true },
    order_amount: { type: Number, default: 0 },
    order_currency: { type: String, default: 'BRL' },
    conversion_pixels: { type: Object, default: () => ({}) },
    return_url: { type: String, required: true },
    seconds: { type: Number, default: 5 },
    meta_purchase_event_id: { type: String, default: '' },
    purchase_contents: { type: Array, default: () => [] },
});

const conversionPixelsRef = ref(null);

const secondsLeft = ref(Math.max(0, Number(props.seconds || 0)));
let intervalId = null;
let timeoutId = null;

const safeUrl = computed(() => (props.return_url || '').trim() || '/');

async function onConversionPixelsReady() {
    if (!props.order_id || !(Number(props.order_amount) > 0)) return;
    const api = conversionPixelsRef.value;
    const cur = (props.order_currency || 'BRL').toUpperCase();
    const eid =
        (props.meta_purchase_event_id || '').trim() || `getfy_purchase_${props.order_id}`;
    await firePurchaseWhenReady(api, {
        order_id: props.order_id,
        amount: props.order_amount,
        currency: cur,
        meta_event_id: eid,
        purchase_contents: props.purchase_contents,
    });
}

function goNow() {
    window.location.href = safeUrl.value;
}

onMounted(() => {
    timeoutId = setTimeout(goNow, secondsLeft.value * 1000);
    intervalId = setInterval(() => {
        secondsLeft.value = Math.max(0, secondsLeft.value - 1);
        if (secondsLeft.value <= 0 && intervalId) {
            clearInterval(intervalId);
            intervalId = null;
        }
    }, 1000);
});

onUnmounted(() => {
    if (intervalId) clearInterval(intervalId);
    if (timeoutId) clearTimeout(timeoutId);
});
</script>

<template>
    <ConversionPixels ref="conversionPixelsRef" :pixels="props.conversion_pixels" @ready="onConversionPixelsReady" />
    <Head title="Pagamento confirmado" />
    <div class="min-h-screen bg-gray-100 flex items-center justify-center p-6">
        <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-lg text-center">
            <h1 class="text-lg font-bold text-gray-900">Pagamento confirmado</h1>
            <p class="mt-2 text-sm text-gray-600">
                Você será redirecionado de volta em <span class="font-semibold text-gray-900">{{ secondsLeft }}</span>s.
            </p>
            <div class="mt-5 flex justify-center">
                <span class="inline-block h-10 w-10 animate-spin rounded-full border-2 border-gray-300 border-t-gray-700" />
            </div>
            <button
                type="button"
                class="mt-6 w-full rounded-xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90"
                @click="goNow"
            >
                Voltar agora
            </button>
            <p class="mt-3 text-xs text-gray-500 break-all">{{ safeUrl }}</p>
        </div>
    </div>
</template>

