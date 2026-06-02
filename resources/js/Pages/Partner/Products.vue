<script setup>
import { Link } from '@inertiajs/vue3';
import { Package } from 'lucide-vue-next';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';

defineOptions({ layout: LayoutInfoprodutor });

defineProps({
    products: { type: Array, default: () => [] },
    partner_role: { type: String, default: '' },
});

function formatPrice(value, currency = 'BRL') {
    const n = Number(value ?? 0);
    if (!n) return null;
    try {
        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency }).format(n);
    } catch {
        return `R$ ${n.toFixed(2)}`;
    }
}

function statusLabel(status) {
    if (status === 'approved') return 'Aprovado';
    if (status === 'pending') return 'Aguardando aprovação';
    return status || '';
}

function statusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300';
    }
    if (status === 'pending') {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300';
    }
    return 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400';
}
</script>

<template>
    <div class="space-y-8">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Meus produtos</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Produtos em que você atua como parceiro. Clique para ver detalhes, links e pixels.
            </p>
        </div>

        <section>
            <div
                v-if="products.length === 0"
                class="panel-card-lg flex flex-col items-center justify-center py-12 text-center"
            >
                <Package class="mb-3 h-10 w-10 text-zinc-300 dark:text-zinc-600" />
                <p class="font-medium text-zinc-700 dark:text-zinc-300">Nenhum produto ainda</p>
                <p class="mt-2 max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                    Afilie-se pelo link público do produtor ou aguarde a aprovação se já solicitou afiliação.
                </p>
            </div>

            <ul v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="p in products" :key="p.id">
                    <Link
                        :href="`/parceiro/produtos/${p.id}`"
                        class="flex items-center gap-3 rounded-xl border border-zinc-200/80 bg-white p-3 shadow-sm transition hover:ring-2 hover:ring-[var(--color-primary)] dark:border-zinc-800 dark:bg-zinc-900"
                    >
                        <div
                            class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800"
                        >
                            <img
                                v-if="p.image_url"
                                :src="p.image_url"
                                :alt="p.name"
                                class="h-full w-full object-cover"
                            />
                            <span
                                v-else
                                class="text-lg font-semibold text-zinc-400 dark:text-zinc-500"
                            >
                                {{ p.name?.charAt(0) }}
                            </span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ p.name }}</p>
                                <span
                                    v-if="p.affiliate_status"
                                    class="shrink-0 rounded-full px-1.5 py-0.5 text-[9px] font-semibold uppercase leading-tight"
                                    :class="statusClass(p.affiliate_status)"
                                >
                                    {{ statusLabel(p.affiliate_status) }}
                                </span>
                            </div>
                            <p
                                v-if="formatPrice(p.price, p.currency)"
                                class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400"
                            >
                                {{ formatPrice(p.price, p.currency) }}
                            </p>
                            <p class="mt-1 text-[11px] text-zinc-500">
                                <span v-if="p.commission_percent != null">{{ p.commission_percent }}% comissão</span>
                                <span v-if="p.partner_type" class="capitalize"> · {{ p.partner_type }}</span>
                            </p>
                        </div>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
