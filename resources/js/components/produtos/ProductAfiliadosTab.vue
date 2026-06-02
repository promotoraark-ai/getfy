<script setup>
import { ref, onMounted, computed } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import ProductPartnersTable from '@/components/produtos/ProductPartnersTable.vue';

const props = defineProps({
    productId: { type: String, required: true },
});

const loading = ref(true);
const saving = ref(false);
const program = ref(null);
const affiliates = ref([]);
const message = ref('');

const programForm = ref({
    enabled: false,
    default_commission_percent: 10,
    manual_approval: true,
    share_buyer_data: false,
    public_slug: '',
    support_email: '',
    description: '',
    settlement_days_pix: 0,
    settlement_days_card: 30,
    settlement_days_boleto: 2,
});

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get(`/produtos/${props.productId}/affiliate-program`);
        program.value = data.program;
        affiliates.value = data.affiliates ?? [];
        Object.assign(programForm.value, data.program ?? {});
    } finally {
        loading.value = false;
    }
}

async function saveProgram() {
    saving.value = true;
    message.value = '';
    try {
        const { data } = await axios.put(`/produtos/${props.productId}/affiliate-program`, programForm.value);
        program.value = data.program;
        message.value = 'Programa salvo.';
    } catch (e) {
        message.value = e.response?.data?.message || 'Erro ao salvar.';
    } finally {
        saving.value = false;
    }
}

async function updateAffiliate(affiliate, patch) {
    await axios.put(`/produtos/${props.productId}/affiliates/${affiliate.id}`, patch);
    await load();
}

function copyLink(url) {
    navigator.clipboard?.writeText(url);
    message.value = 'Link copiado.';
}

const publicPageUrl = computed(() => program.value?.public_page_url || '');

const affiliateRows = computed(() =>
    affiliates.value.map((a) => ({
        id: a.id,
        created_at: a.created_at,
        name: a.user?.name ?? null,
        email: a.user?.email ?? null,
        product_name: a.product_name,
        commission_percent:
            a.commission_percent ?? programForm.value.default_commission_percent ?? null,
        status: a.status,
        _raw: a,
    }))
);

function affiliateById(id) {
    return affiliates.value.find((a) => a.id === id);
}

onMounted(load);
</script>

<template>
    <div class="panel-card-lg space-y-8">
        <div>
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Programa de afiliados</h2>
            <form class="mt-4 space-y-4" @submit.prevent="saveProgram">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="programForm.enabled" type="checkbox" />
                    Ativar afiliação para este produto
                </label>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-sm font-medium">Comissão padrão (%)</label>
                        <input v-model.number="programForm.default_commission_percent" type="number" min="0" max="100" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                    </div>
                    <div>
                        <label class="text-sm font-medium">Slug da página pública</label>
                        <input v-model="programForm.public_slug" type="text" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                    </div>
                    <div>
                        <label class="text-sm font-medium">E-mail de suporte</label>
                        <input v-model="programForm.support_email" type="email" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                    </div>
                    <label class="flex items-center gap-2 text-sm md:col-span-2">
                        <input v-model="programForm.manual_approval" type="checkbox" />
                        Aprovar afiliados manualmente
                    </label>
                    <label class="flex items-center gap-2 text-sm md:col-span-2">
                        <input v-model="programForm.share_buyer_data" type="checkbox" />
                        Compartilhar dados do comprador com afiliados (nome, e-mail e telefone nas vendas)
                    </label>
                    <p class="text-xs text-zinc-500 md:col-span-2">
                        Se desativado, afiliados veem os dados mascarados na listagem de vendas.
                    </p>
                </div>
                <div>
                    <label class="text-sm font-medium">Descrição (página de afiliação)</label>
                    <textarea v-model="programForm.description" rows="3" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="text-xs font-medium">Liberação PIX (dias)</label>
                        <input v-model.number="programForm.settlement_days_pix" type="number" min="0" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                    </div>
                    <div>
                        <label class="text-xs font-medium">Cartão (dias)</label>
                        <input v-model.number="programForm.settlement_days_card" type="number" min="0" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                    </div>
                    <div>
                        <label class="text-xs font-medium">Boleto (dias)</label>
                        <input v-model.number="programForm.settlement_days_boleto" type="number" min="0" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                    </div>
                </div>
                <div v-if="publicPageUrl" class="rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                    <p class="font-medium">Página de cadastro de afiliados</p>
                    <a :href="publicPageUrl" class="text-[var(--color-primary)] break-all" target="_blank" rel="noopener">{{ publicPageUrl }}</a>
                </div>
                <Button type="submit" :disabled="saving">{{ saving ? 'Salvando…' : 'Salvar programa' }}</Button>
                <p v-if="message" class="text-sm text-zinc-500">{{ message }}</p>
            </form>
        </div>

        <div>
            <h3 class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">Afiliados</h3>
            <p v-if="loading" class="mt-3 text-sm text-zinc-500">Carregando…</p>
            <ProductPartnersTable
                v-else
                class="mt-4"
                :rows="affiliateRows"
                :show-product-column="false"
                empty-label="Nenhum afiliado cadastrado."
            >
                <template #menu="{ row, close }">
                    <template v-if="row">
                        <button
                            v-if="affiliateById(row.id)?.affiliate_link"
                            type="button"
                            class="flex w-full px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            @click="copyLink(affiliateById(row.id).affiliate_link); close()"
                        >
                            Copiar link
                        </button>
                        <button
                            v-if="row.status === 'pending'"
                            type="button"
                            class="flex w-full px-3 py-2 text-left text-sm text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-emerald-900/20"
                            @click="updateAffiliate(affiliateById(row.id), { status: 'approved' }); close()"
                        >
                            Aprovar
                        </button>
                        <button
                            v-if="row.status === 'pending'"
                            type="button"
                            class="flex w-full px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            @click="updateAffiliate(affiliateById(row.id), { status: 'rejected' }); close()"
                        >
                            Rejeitar
                        </button>
                        <button
                            v-if="row.status === 'approved'"
                            type="button"
                            class="flex w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/20"
                            @click="updateAffiliate(affiliateById(row.id), { status: 'removed' }); close()"
                        >
                            Remover
                        </button>
                    </template>
                </template>
            </ProductPartnersTable>
        </div>
    </div>
</template>
