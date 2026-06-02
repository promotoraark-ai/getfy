<script setup>
import { Link } from '@inertiajs/vue3';
import { Lock, PlugZap } from 'lucide-vue-next';

defineProps({
    connected: { type: Boolean, default: false },
    /** producer = link para integrações; partner = mensagem para aguardar produtor */
    variant: {
        type: String,
        default: 'producer',
        validator: (v) => ['producer', 'partner'].includes(v),
    },
    gatewaysUrl: { type: String, default: '/integracoes?tab=gateways&gateway=cajupay' },
});
</script>

<template>
    <div class="relative">
        <div
            class="transition"
            :class="connected ? '' : 'pointer-events-none select-none opacity-40 blur-[2px] grayscale-[30%]'"
            :aria-hidden="!connected"
        >
            <slot />
        </div>

        <div
            v-if="!connected"
            class="absolute inset-0 z-10 flex items-center justify-center p-4 sm:p-8"
        >
            <div
                class="w-full max-w-md rounded-2xl border border-zinc-200/80 bg-white/95 p-6 text-center shadow-xl backdrop-blur-md dark:border-zinc-700 dark:bg-zinc-900/95"
                role="alertdialog"
                aria-labelledby="cajupay-gate-title"
                aria-describedby="cajupay-gate-desc"
            >
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300"
                >
                    <Lock class="h-7 w-7" />
                </div>

                <h2
                    id="cajupay-gate-title"
                    class="mt-4 text-lg font-semibold text-zinc-900 dark:text-white"
                >
                    Saques indisponíveis
                </h2>

                <p
                    id="cajupay-gate-desc"
                    class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400"
                >
                    <template v-if="variant === 'producer'">
                        A API de saque via PIX só funciona com a
                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">CajuPay</strong>
                        conectada e ativa. Você pode consultar saldos abaixo, mas solicitar saques e
                        aprovar repasses de parceiros ficará bloqueado até a integração.
                    </template>
                    <template v-else>
                        Os saques são processados pela CajuPay da conta do produtor. Enquanto ele não
                        conectar o gateway, você pode acompanhar comissões, mas não solicitar saques.
                    </template>
                </p>

                <div
                    v-if="variant === 'producer'"
                    class="mt-5 flex flex-col items-center gap-2"
                >
                    <Link
                        :href="gatewaysUrl"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--color-primary)] px-5 py-2.5 text-sm font-semibold text-zinc-900 shadow-sm transition hover:opacity-90 sm:w-auto"
                    >
                        <PlugZap class="h-4 w-4" />
                        Configurar CajuPay
                    </Link>
                    <p class="text-xs text-zinc-500 dark:text-zinc-500">
                        Integrações → Gateways → CajuPay
                    </p>
                </div>

                <p
                    v-else
                    class="mt-4 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400"
                >
                    Entre em contato com o produtor do produto para habilitar os saques.
                </p>
            </div>
        </div>
    </div>
</template>
