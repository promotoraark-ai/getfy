<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { MoreVertical } from 'lucide-vue-next';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    emptyLabel: { type: String, default: 'Nenhum registro encontrado.' },
    statusLabels: { type: Object, default: () => ({}) },
    statusBadgeClasses: { type: Object, default: () => ({}) },
    showProductColumn: { type: Boolean, default: true },
    selectable: { type: Boolean, default: true },
});

const openMenuId = ref(null);
const menuAnchorEl = ref(null);
const menuEl = ref(null);
const menuPos = ref({ top: 0, left: 0 });
const selectedIds = ref(new Set());

const allSelected = computed(() => {
    if (!props.rows.length) return false;
    return props.rows.every((r) => selectedIds.value.has(r.id));
});

const defaultStatusLabels = {
    approved: 'Ativo',
    active: 'Ativo',
    pending: 'Pendente',
    rejected: 'Rejeitado',
    removed: 'Removido',
    revoked: 'Revogado',
    expired: 'Expirado',
};

const defaultStatusClasses = {
    approved: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    active: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    pending: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
    rejected: 'bg-red-500/15 text-red-700 dark:text-red-300',
    removed: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400',
    revoked: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400',
    expired: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400',
};

function statusLabel(status) {
    return props.statusLabels[status] ?? defaultStatusLabels[status] ?? status ?? '—';
}

function statusClass(status) {
    return props.statusBadgeClasses[status] ?? defaultStatusClasses[status] ?? 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400';
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(new Date(iso));
}

function formatCommission(value) {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    if (Number.isNaN(n)) return '—';
    return `${n % 1 === 0 ? n : n.toFixed(2).replace('.', ',')}%`;
}

function toggleSelectAll() {
    if (allSelected.value) {
        selectedIds.value = new Set();
        return;
    }
    selectedIds.value = new Set(props.rows.map((r) => r.id));
}

function toggleRow(id) {
    const next = new Set(selectedIds.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    selectedIds.value = next;
}

async function updateMenuPosition() {
    const anchor = menuAnchorEl.value;
    if (!anchor || openMenuId.value == null) return;

    const rect = anchor.getBoundingClientRect();
    const minMargin = 8;
    const desiredWidth = 192;
    const viewportW = window.innerWidth || 0;
    const viewportH = window.innerHeight || 0;

    let left = rect.right - desiredWidth;
    left = Math.max(minMargin, Math.min(left, Math.max(minMargin, viewportW - desiredWidth - minMargin)));

    let top = rect.bottom + 4;
    menuPos.value = { top, left };

    await nextTick();
    const menu = menuEl.value;
    if (!menu) return;

    const menuRect = menu.getBoundingClientRect();
    const spaceBelow = viewportH - rect.bottom;
    const spaceAbove = rect.top;
    if (menuRect.height + 8 > spaceBelow && spaceAbove >= menuRect.height + 8) {
        menuPos.value = { top: Math.max(minMargin, rect.top - menuRect.height - 4), left };
    }
}

async function toggleMenu(id, event) {
    if (openMenuId.value === id) {
        closeMenu();
        return;
    }
    openMenuId.value = id;
    menuAnchorEl.value = event?.currentTarget ?? null;
    await nextTick();
    await updateMenuPosition();
}

function closeMenu() {
    openMenuId.value = null;
    menuAnchorEl.value = null;
}

function handleClickOutside(event) {
    if (openMenuId.value == null) return;
    const el = document.querySelector(`[data-partner-menu="${openMenuId.value}"]`);
    const menu = menuEl.value;
    if (el?.contains(event.target)) return;
    if (menu?.contains(event.target)) return;
    closeMenu();
}

onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));

defineExpose({ closeMenu });
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/40">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                    <tr>
                        <th v-if="selectable" class="w-10 px-3 py-3">
                            <input
                                type="checkbox"
                                class="rounded border-zinc-300 dark:border-zinc-600"
                                :checked="allSelected"
                                :disabled="!rows.length"
                                aria-label="Selecionar todos"
                                @change="toggleSelectAll"
                            />
                        </th>
                        <th
                            class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Data
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Nome
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            E-mail
                        </th>
                        <th
                            v-if="showProductColumn"
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Produto
                        </th>
                        <th
                            class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Comissão
                        </th>
                        <th
                            class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Status
                        </th>
                        <th class="relative w-12 px-2 py-3">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="transition hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40"
                    >
                        <td v-if="selectable" class="px-3 py-3">
                            <input
                                type="checkbox"
                                class="rounded border-zinc-300 dark:border-zinc-600"
                                :checked="selectedIds.has(row.id)"
                                :aria-label="`Selecionar ${row.name || row.email}`"
                                @change="toggleRow(row.id)"
                            />
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ formatDate(row.created_at) }}
                        </td>
                        <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">
                            {{ row.name || '—' }}
                        </td>
                        <td class="max-w-[200px] truncate px-4 py-3 text-sm">
                            <a
                                v-if="row.email"
                                :href="`mailto:${row.email}`"
                                class="text-[var(--color-primary)] hover:underline"
                                :title="row.email"
                            >
                                {{ row.email }}
                            </a>
                            <span v-else class="text-zinc-500">—</span>
                        </td>
                        <td
                            v-if="showProductColumn"
                            class="max-w-[180px] truncate px-4 py-3 text-sm text-zinc-800 dark:text-zinc-200"
                            :title="row.product_name"
                        >
                            {{ row.product_name || '—' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">
                            {{ formatCommission(row.commission_percent) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span
                                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="statusClass(row.status)"
                            >
                                {{ statusLabel(row.status) }}
                            </span>
                        </td>
                        <td class="relative whitespace-nowrap px-2 py-3 text-right">
                            <div class="relative inline-flex" :data-partner-menu="row.id">
                                <button
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                                    aria-label="Abrir menu de ações"
                                    :aria-expanded="openMenuId === row.id"
                                    @click="toggleMenu(row.id, $event)"
                                >
                                    <MoreVertical class="h-4 w-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td
                            :colspan="selectable ? (showProductColumn ? 8 : 7) : (showProductColumn ? 7 : 6)"
                            class="px-4 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400"
                        >
                            {{ emptyLabel }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Teleport to="body">
            <div
                v-if="openMenuId != null"
                ref="menuEl"
                class="fixed z-[100000] w-48 rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                :style="{ top: `${menuPos.top}px`, left: `${menuPos.left}px` }"
                role="menu"
            >
                <slot name="menu" :row="rows.find((r) => r.id === openMenuId)" :close="closeMenu" />
            </div>
        </Teleport>
    </div>
</template>
