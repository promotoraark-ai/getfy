<script setup>
import { ref, reactive } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    requests: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    status_options: { type: Array, default: () => [] },
    can_manage: { type: Boolean, default: false },
});

const page = usePage();
const localFilters = reactive({
    status: props.filters.status ?? 'all',
    product_id: props.filters.product_id ?? '',
});
const actionId = ref(null);
const notesModalOpen = ref(false);
const notesModalAction = ref('approve');
const notesModalRequestId = ref(null);

const notesForm = useForm({ admin_notes: '' });

function applyFilters() {
    router.get('/reembolsos', {
        status: localFilters.status,
        product_id: localFilters.product_id || undefined,
    }, { preserveState: true, replace: true });
}

function onStatusChange(e) {
    localFilters.status = e.target.value;
    applyFilters();
}

function onProductChange(e) {
    localFilters.product_id = e.target.value;
    applyFilters();
}

function openNotesModal(requestId, action) {
    notesModalRequestId.value = requestId;
    notesModalAction.value = action;
    notesForm.admin_notes = '';
    notesForm.clearErrors();
    notesModalOpen.value = true;
}

function closeNotesModal() {
    notesModalOpen.value = false;
}

function submitNotesAction() {
    if (!notesModalRequestId.value) return;
    const url = notesModalAction.value === 'approve'
        ? `/reembolsos/${notesModalRequestId.value}/approve`
        : `/reembolsos/${notesModalRequestId.value}/reject`;
    actionId.value = notesModalRequestId.value;
    notesForm.post(url, {
        preserveScroll: true,
        onFinish: () => {
            actionId.value = null;
            closeNotesModal();
        },
    });
}

function formatMoney(amount, currency) {
    const cur = (currency || 'BRL').toUpperCase();
    try {
        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: cur }).format(amount);
    } catch {
        return `R$ ${Number(amount).toFixed(2)}`;
    }
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Reembolsos</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Solicitações de reembolso feitas pelos alunos na área de membros.</p>
        </div>

        <div v-if="page.props.flash?.success" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
            {{ page.props.flash.success }}
        </div>

        <div class="flex flex-wrap gap-3">
            <select
                :value="localFilters.status"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                @change="onStatusChange"
            >
                <option v-for="opt in status_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
            <select
                :value="localFilters.product_id"
                class="min-w-[200px] rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                @change="onProductChange"
            >
                <option value="">Todos os produtos</option>
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Aluno</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Produto</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Pedido</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500">Motivo</th>
                            <th v-if="can_manage" class="px-4 py-3 text-right text-xs font-medium uppercase text-zinc-500">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <tr v-for="row in requests.data" :key="row.id" class="text-sm">
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-600 dark:text-zinc-400">
                                {{ row.created_at ? new Date(row.created_at).toLocaleString('pt-BR') : '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ row.user?.name }}</p>
                                <p class="text-xs text-zinc-500">{{ row.user?.email }}</p>
                            </td>
                            <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ row.product?.name }}</td>
                            <td class="px-4 py-3">
                                <span class="text-zinc-700 dark:text-zinc-300">#{{ row.order?.id }}</span>
                                <span class="block text-xs text-zinc-500">
                                    {{ formatMoney(row.order?.amount, row.order?.currency) }} · {{ row.order?.payment_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="{
                                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300': row.status === 'pending',
                                        'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300': row.status === 'processing',
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300': row.status === 'completed',
                                        'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300': row.status === 'rejected' || row.status === 'failed',
                                    }"
                                >
                                    {{ row.status_label }}
                                </span>
                                <span
                                    v-if="row.needs_manual_gateway"
                                    class="mt-1 block text-xs text-amber-600 dark:text-amber-400"
                                >
                                    Aguardando estorno manual no gateway
                                </span>
                                <span v-if="row.failure_reason" class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ row.failure_reason }}</span>
                            </td>
                            <td class="max-w-xs px-4 py-3 text-zinc-600 dark:text-zinc-400">
                                <p class="line-clamp-3">{{ row.reason }}</p>
                            </td>
                            <td v-if="can_manage" class="whitespace-nowrap px-4 py-3 text-right">
                                <div v-if="row.can_approve || row.can_reject" class="flex justify-end gap-2">
                                    <Button
                                        v-if="row.can_approve"
                                        type="button"
                                        size="sm"
                                        :disabled="actionId === row.id"
                                        @click="openNotesModal(row.id, 'approve')"
                                    >
                                        Aprovar
                                    </Button>
                                    <Button
                                        v-if="row.can_reject"
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        :disabled="actionId === row.id"
                                        @click="openNotesModal(row.id, 'reject')"
                                    >
                                        Rejeitar
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!requests.data?.length">
                            <td :colspan="can_manage ? 7 : 6" class="px-4 py-8 text-center text-zinc-500">
                                Nenhuma solicitação encontrada.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="requests.links?.length > 3" class="flex flex-wrap gap-1">
            <template v-for="(link, i) in requests.links" :key="i">
                <button
                    v-if="link.url"
                    type="button"
                    class="rounded px-3 py-1 text-sm"
                    :class="link.active ? 'bg-[var(--color-primary)] text-white' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                    v-html="link.label"
                    @click="router.get(link.url, {}, { preserveState: true })"
                />
            </template>
        </div>

        <div
            v-if="notesModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeNotesModal"
        >
            <div class="w-full max-w-md rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    {{ notesModalAction === 'approve' ? 'Aprovar reembolso' : 'Rejeitar solicitação' }}
                </h3>
                <label class="mt-4 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Observação (opcional)</label>
                <textarea
                    v-model="notesForm.admin_notes"
                    rows="3"
                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                />
                <div v-if="notesForm.errors.admin_notes" class="mt-2 text-sm text-red-600">{{ notesForm.errors.admin_notes }}</div>
                <div class="mt-4 flex gap-2">
                    <Button type="button" variant="outline" class="flex-1" @click="closeNotesModal">Cancelar</Button>
                    <Button type="button" class="flex-1" :disabled="notesForm.processing" @click="submitNotesAction">
                        Confirmar
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
