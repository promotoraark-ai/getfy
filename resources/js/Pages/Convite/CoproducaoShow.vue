<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Percent, Handshake, ArrowRight, Clock } from 'lucide-vue-next';
import AfiliarLayout from '@/Layouts/AfiliarLayout.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: AfiliarLayout });

const props = defineProps({
    invite: { type: Object, required: true },
    product: { type: Object, required: true },
    token: { type: String, required: true },
    cadastro_url: { type: String, required: true },
    login_url: { type: String, required: true },
});

const durationLabel = computed(() => {
    const d = props.invite.duration_days;
    if (!d) return 'Prazo indeterminado';
    return `${d} dias de co-produção`;
});
</script>

<template>
    <article class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div v-if="product.image_url" class="aspect-[16/9] w-full bg-zinc-100 dark:bg-zinc-800">
            <img :src="product.image_url" :alt="product.name" class="h-full w-full object-cover" />
        </div>
        <div
            v-else
            class="flex aspect-[16/9] w-full items-center justify-center bg-zinc-100 dark:bg-zinc-800"
        >
            <span class="text-4xl font-bold text-zinc-300 dark:text-zinc-600">{{ product.name?.charAt(0) }}</span>
        </div>

        <div class="p-6 sm:p-8">
            <p class="text-xs font-semibold uppercase tracking-wider text-[var(--color-primary)]">
                Convite de co-produção
            </p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-3xl">
                {{ product.name }}
            </h1>
            <p class="mt-2 text-sm text-zinc-500">
                Convite para: <strong class="text-zinc-700 dark:text-zinc-300">{{ invite.email }}</strong>
            </p>

            <ul class="mt-6 space-y-3">
                <li class="flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                    <Percent class="mt-0.5 h-4 w-4 shrink-0 text-[var(--color-primary)]" />
                    <span>
                        <strong class="text-zinc-900 dark:text-white">{{ invite.commission_percent }}%</strong>
                        de comissão sobre o valor líquido (após taxas do gateway)
                    </span>
                </li>
                <li class="flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                    <Clock class="mt-0.5 h-4 w-4 shrink-0 text-[var(--color-primary)]" />
                    <span>{{ durationLabel }}</span>
                </li>
                <li class="flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                    <Handshake class="mt-0.5 h-4 w-4 shrink-0 text-[var(--color-primary)]" />
                    <span>
                        Comissão em
                        <template v-if="invite.commission_on_producer_sales && invite.commission_on_affiliate_sales">
                            vendas diretas e de afiliados
                        </template>
                        <template v-else-if="invite.commission_on_producer_sales">vendas diretas do produtor</template>
                        <template v-else-if="invite.commission_on_affiliate_sales">vendas de afiliados</template>
                        <template v-else>nenhum canal (revise com o produtor)</template>
                    </span>
                </li>
            </ul>

            <div class="mt-8 space-y-3">
                <Link :href="cadastro_url" class="block">
                    <Button type="button" variant="primary" class="w-full gap-2 py-3 text-base">
                        Aceitar convite
                        <ArrowRight class="h-4 w-4" />
                    </Button>
                </Link>
                <p class="text-center text-xs text-zinc-500">
                    Já tem conta?
                    <a :href="login_url" class="font-medium text-[var(--color-primary)] hover:underline">Entrar</a>
                    e concluir em um passo.
                </p>
            </div>
        </div>
    </article>
</template>
