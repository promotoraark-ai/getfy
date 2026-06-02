<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Percent, Mail, ArrowRight, BadgeCheck } from 'lucide-vue-next';
import AfiliarLayout from '@/Layouts/AfiliarLayout.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: AfiliarLayout });

const props = defineProps({
    program: { type: Object, required: true },
    product: { type: Object, required: true },
    slug: { type: String, required: true },
    cadastro_url: { type: String, required: true },
});

const priceLabel = computed(() => {
    const value = Number(props.product.price ?? 0);
    const currency = props.product.currency || 'BRL';
    if (!value) {
        return null;
    }
    try {
        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency }).format(value);
    } catch {
        return `R$ ${value.toFixed(2)}`;
    }
});
</script>

<template>
    <article class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div
            v-if="product.image_url"
            class="aspect-[16/9] w-full bg-zinc-100 dark:bg-zinc-800"
        >
            <img
                :src="product.image_url"
                :alt="product.name"
                class="h-full w-full object-cover"
            />
        </div>
        <div
            v-else
            class="flex aspect-[16/9] w-full items-center justify-center bg-zinc-100 dark:bg-zinc-800"
        >
            <span class="text-4xl font-bold text-zinc-300 dark:text-zinc-600">{{ product.name?.charAt(0) }}</span>
        </div>

        <div class="p-6 sm:p-8">
            <p class="text-xs font-semibold uppercase tracking-wider text-[var(--color-primary)]">
                Programa de afiliados
            </p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-3xl">
                {{ product.name }}
            </h1>
            <p v-if="priceLabel" class="mt-2 text-lg font-medium text-zinc-700 dark:text-zinc-300">
                {{ priceLabel }}
            </p>
            <p
                v-if="product.description"
                class="mt-4 whitespace-pre-line text-sm leading-relaxed text-zinc-600 dark:text-zinc-400"
            >
                {{ product.description }}
            </p>
            <p
                v-if="program.description"
                class="mt-4 whitespace-pre-line rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3 text-sm leading-relaxed text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400"
            >
                {{ program.description }}
            </p>

            <ul class="mt-6 space-y-3">
                <li class="flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                    <Percent class="mt-0.5 h-4 w-4 shrink-0 text-[var(--color-primary)]" />
                    <span>
                        <strong class="text-zinc-900 dark:text-white">{{ program.default_commission_percent }}%</strong>
                        de comissão sobre o valor líquido da venda
                    </span>
                </li>
                <li class="flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                    <BadgeCheck class="mt-0.5 h-4 w-4 shrink-0 text-[var(--color-primary)]" />
                    <span>
                        {{ program.manual_approval ? 'Afiliação sujeita à aprovação do produtor' : 'Afiliação automática após o cadastro' }}
                    </span>
                </li>
                <li
                    v-if="program.support_email"
                    class="flex items-start gap-3 text-sm text-zinc-700 dark:text-zinc-300"
                >
                    <Mail class="mt-0.5 h-4 w-4 shrink-0 text-[var(--color-primary)]" />
                    <span>
                        Suporte:
                        <a
                            :href="`mailto:${program.support_email}`"
                            class="font-medium text-[var(--color-primary)] hover:underline"
                        >{{ program.support_email }}</a>
                    </span>
                </li>
            </ul>

            <div class="mt-8">
                <Link :href="cadastro_url" class="block">
                    <Button type="button" variant="primary" class="w-full gap-2 py-3 text-base">
                        Quero me afiliar
                        <ArrowRight class="h-4 w-4" />
                    </Button>
                </Link>
                <p class="mt-3 text-center text-xs text-zinc-500 dark:text-zinc-500">
                    No próximo passo você cria sua conta ou entra com uma existente.
                </p>
            </div>
        </div>
    </article>
</template>
