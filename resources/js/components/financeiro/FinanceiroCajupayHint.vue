<script setup>
import { Link } from '@inertiajs/vue3';
import { Info } from 'lucide-vue-next';

defineProps({
    variant: {
        type: String,
        default: 'producer',
        validator: (v) => ['producer', 'partner'].includes(v),
    },
    gatewaysUrl: { type: String, default: '/integracoes?tab=gateways&gateway=cajupay' },
});
</script>

<template>
    <p
        class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-zinc-200/70 bg-zinc-50/60 px-3 py-2 text-xs leading-relaxed text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/30 dark:text-zinc-400"
        role="note"
    >
        <Info class="h-3.5 w-3.5 shrink-0 text-zinc-400 dark:text-zinc-500" aria-hidden="true" />
        <span>
            <template v-if="variant === 'producer'">
                Saques e repasses de parceiros usam exclusivamente a
                <span class="font-medium text-zinc-600 dark:text-zinc-300">API CajuPay</span>
                (transferência PIX).
            </template>
            <template v-else>
                Saques via PIX são processados pela
                <span class="font-medium text-zinc-600 dark:text-zinc-300">API CajuPay</span>
                da conta do produtor.
            </template>
        </span>
        <Link
            v-if="variant === 'producer'"
            :href="gatewaysUrl"
            class="shrink-0 font-medium text-zinc-600 underline-offset-2 hover:text-zinc-900 hover:underline dark:text-zinc-400 dark:hover:text-zinc-200"
        >
            Ver integração
        </Link>
    </p>
</template>
