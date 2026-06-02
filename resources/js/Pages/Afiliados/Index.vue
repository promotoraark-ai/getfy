<script setup>
import { computed } from 'vue';
import axios from 'axios';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import ProdutosTabs from '@/components/produtos/ProdutosTabs.vue';
import ProductPartnersTable from '@/components/produtos/ProductPartnersTable.vue';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    affiliates: { type: Object, required: true },
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
    window.location.reload();
}

async function reject(id, productId) {
    await axios.put(`/produtos/${productId}/affiliates/${id}`, { status: 'rejected' });
    window.location.reload();
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
            <p class="mt-1 text-sm text-zinc-500">Gerencie afiliados de todos os produtos.</p>
        </div>
        <ProdutosTabs />
        <ProductPartnersTable
            :rows="affiliateRows"
            empty-label="Nenhum afiliado encontrado."
        >
            <template #menu="{ row, close }">
                <template v-if="row">
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
    </div>
</template>
