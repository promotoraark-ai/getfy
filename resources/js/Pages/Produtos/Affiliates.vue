<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import ProdutosTabs from '@/components/produtos/ProdutosTabs.vue';
import ProductPartnersTable from '@/components/produtos/ProductPartnersTable.vue';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    affiliates: { type: Object, required: true },
    programs: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const affiliateList = computed(() => props.affiliates?.data ?? []);

const affiliateRows = computed(() =>
    affiliateList.value.map((a) => ({
        id: a.id,
        created_at: a.created_at,
        name: a.user?.name ?? null,
        email: a.user?.email ?? null,
        product_name: a.product_name,
        commission_percent: a.commission_percent,
        status: a.status,
        product_id: a.product_id,
        affiliate_link: a.affiliate_link,
    }))
);

async function approve(id, productId) {
    await axios.put(`/produtos/${productId}/affiliates/${id}`, { status: 'approved' });
    router.reload({ only: ['affiliates'] });
}

async function reject(id, productId) {
    await axios.put(`/produtos/${productId}/affiliates/${id}`, { status: 'rejected' });
    router.reload({ only: ['affiliates'] });
}

function copyLink(url) {
    if (!url) return;
    navigator.clipboard?.writeText(url);
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Afiliados</h1>
            <p class="mt-1 text-sm text-zinc-500">
                Todos os afiliados da conta. Para configurar comissão e página pública, abra o produto.
            </p>
        </div>
        <ProdutosTabs />

        <ProductPartnersTable
            :rows="affiliateRows"
            empty-label="Nenhum afiliado encontrado."
        >
            <template #menu="{ row, close }">
                <template v-if="row">
                    <Link
                        v-if="row.product_id"
                        :href="`/produtos/${row.product_id}/edit?tab=afiliados`"
                        class="flex w-full px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="close()"
                    >
                        Configurar programa
                    </Link>
                    <button
                        v-if="row.affiliate_link"
                        type="button"
                        class="flex w-full px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="copyLink(row.affiliate_link); close()"
                    >
                        Copiar link
                    </button>
                    <button
                        v-if="row.status === 'pending'"
                        type="button"
                        class="flex w-full px-3 py-2 text-left text-sm text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-emerald-900/20"
                        @click="approve(row.id, row.product_id); close()"
                    >
                        Aprovar
                    </button>
                    <button
                        v-if="row.status === 'pending'"
                        type="button"
                        class="flex w-full px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="reject(row.id, row.product_id); close()"
                    >
                        Rejeitar
                    </button>
                </template>
            </template>
        </ProductPartnersTable>

        <details v-if="programs.length" class="rounded-xl border border-zinc-200 bg-zinc-50/50 dark:border-zinc-700 dark:bg-zinc-900/30">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                Programas por produto ({{ programs.length }})
            </summary>
            <ul class="divide-y border-t border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                <li
                    v-for="p in programs"
                    :key="p.product_id"
                    class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm"
                >
                    <div>
                        <p class="font-medium text-zinc-900 dark:text-white">{{ p.product_name }}</p>
                        <p class="text-xs text-zinc-500">
                            {{ p.enabled ? 'Ativo' : 'Inativo' }} · {{ p.affiliates_count }} aprovados ·
                            {{ p.pending_count }} pendentes
                        </p>
                    </div>
                    <Link
                        :href="`/produtos/${p.product_id}/edit?tab=afiliados`"
                        class="text-[var(--color-primary)] hover:underline"
                    >
                        Configurar
                    </Link>
                </li>
            </ul>
        </details>
    </div>
</template>
