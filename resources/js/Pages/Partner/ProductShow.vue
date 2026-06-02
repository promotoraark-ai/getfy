<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Copy, Check, ExternalLink } from 'lucide-vue-next';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import ConversionPixelsForm from '@/components/produtos/ConversionPixelsForm.vue';
import { mergeConversionPixels } from '@/lib/conversionPixels';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    produto: { type: Object, required: true },
    partner_type: { type: String, required: true },
    affiliate_status: { type: String, default: null },
    commission_percent: { type: Number, default: null },
    affiliate: { type: Object, default: null },
    links: { type: Array, default: () => [] },
    can_use_links: { type: Boolean, default: false },
    can_edit_pixels: { type: Boolean, default: false },
    tab: { type: String, default: 'overview' },
});

const activeTab = ref(props.tab || 'overview');

const tabs = computed(() => {
    const items = [{ id: 'overview', label: 'Visão geral' }];
    if (props.partner_type === 'afiliado') {
        items.push({ id: 'links', label: 'Links' });
        items.push({ id: 'pixels', label: 'Pixels' });
    }
    return items;
});

const priceLabel = computed(() => {
    const value = Number(props.produto.price ?? 0);
    const currency = props.produto.currency || 'BRL';
    if (!value) return null;
    try {
        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency }).format(value);
    } catch {
        return `R$ ${value.toFixed(2)}`;
    }
});

const pixelsData = mergeConversionPixels(props.affiliate?.affiliate_pixels ?? {});

const pixelsForm = useForm({
    affiliate_pixels: pixelsData,
});

function savePixels() {
    pixelsForm.put(`/parceiro/produtos/${props.produto.id}/pixels`, { preserveScroll: true });
}

const copiedIndex = ref(null);

async function copyUrl(url, index) {
    try {
        await navigator.clipboard.writeText(url);
        copiedIndex.value = index;
        setTimeout(() => {
            copiedIndex.value = null;
        }, 2000);
    } catch {
        // ignore
    }
}

function isPendingAffiliate() {
    return props.partner_type === 'afiliado' && props.affiliate_status === 'pending';
}
</script>

<template>
    <div class="space-y-6">
        <Link href="/parceiro/produtos" class="text-sm font-medium text-[var(--color-primary)] hover:underline">
            ← Meus produtos
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ produto.name }}</h1>
                <p class="mt-1 text-sm text-zinc-500">
                    Modo: {{ partner_type }} · somente leitura
                    <span v-if="commission_percent != null"> · {{ commission_percent }}% comissão (líquido)</span>
                </p>
            </div>
            <span
                v-if="affiliate_status"
                class="rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide"
                :class="
                    affiliate_status === 'approved'
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'
                        : 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300'
                "
            >
                {{ affiliate_status === 'approved' ? 'Aprovado' : 'Aguardando aprovação' }}
            </span>
        </div>

        <div
            v-if="isPendingAffiliate()"
            class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-200"
        >
            Sua afiliação está aguardando aprovação do produtor. Após aprovar, você poderá copiar links e configurar pixels.
        </div>

        <nav class="flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
            <button
                v-for="t in tabs"
                :key="t.id"
                type="button"
                class="shrink-0 border-b-2 px-4 py-2.5 text-sm font-medium transition"
                :class="
                    activeTab === t.id
                        ? 'border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'
                "
                @click="activeTab = t.id"
            >
                {{ t.label }}
            </button>
        </nav>

        <!-- Visão geral -->
        <div v-show="activeTab === 'overview'" class="panel-card-lg overflow-hidden">
            <div class="grid gap-6 md:grid-cols-[240px_1fr]">
                <div
                    v-if="produto.image_url"
                    class="aspect-square overflow-hidden rounded-xl bg-zinc-100 dark:bg-zinc-800 md:max-w-[240px]"
                >
                    <img :src="produto.image_url" :alt="produto.name" class="h-full w-full object-cover" />
                </div>
                <div
                    v-else
                    class="flex aspect-square max-h-48 items-center justify-center rounded-xl bg-zinc-100 text-4xl font-bold text-zinc-300 dark:bg-zinc-800 dark:text-zinc-600 md:max-w-[240px]"
                >
                    {{ produto.name?.charAt(0) }}
                </div>
                <div class="min-w-0 space-y-4">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Preço</p>
                        <p class="text-lg font-semibold text-zinc-900 dark:text-white">
                            {{ priceLabel || '—' }}
                        </p>
                    </div>
                    <div v-if="affiliate?.affiliate_code && can_use_links">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Seu código (ref)</p>
                        <p class="font-mono text-sm text-[var(--color-primary)]">{{ affiliate.affiliate_code }}</p>
                    </div>
                    <div v-if="produto.description">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Descrição</p>
                        <p class="mt-1 whitespace-pre-line text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                            {{ produto.description }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Links -->
        <div v-show="activeTab === 'links'">
            <div v-if="!can_use_links" class="panel-card-lg text-sm text-zinc-600 dark:text-zinc-400">
                Links disponíveis após aprovação da afiliação.
            </div>
            <div v-else-if="links.length" class="panel-card-lg space-y-4">
                <h2 class="font-semibold text-zinc-900 dark:text-white">Links de divulgação</h2>
                <p class="text-sm text-zinc-500">Use estes links nas suas campanhas. O parâmetro <code class="text-xs">ref</code> identifica suas vendas.</p>
                <ul class="space-y-3">
                    <li
                        v-for="(l, i) in links"
                        :key="i"
                        class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                    >
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ l.label }}</p>
                        <p class="mt-1 break-all font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ l.url }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <Button type="button" variant="outline" size="sm" class="gap-1.5" @click="copyUrl(l.url, i)">
                                <Check v-if="copiedIndex === i" class="h-4 w-4 text-emerald-600" />
                                <Copy v-else class="h-4 w-4" />
                                {{ copiedIndex === i ? 'Copiado' : 'Copiar' }}
                            </Button>
                            <a
                                :href="l.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            >
                                <ExternalLink class="h-4 w-4" />
                                Abrir
                            </a>
                        </div>
                    </li>
                </ul>
            </div>
            <div v-else class="panel-card-lg text-sm text-zinc-500">Nenhum link adicional configurado para este produto.</div>
        </div>

        <!-- Pixels -->
        <div v-show="activeTab === 'pixels'">
            <div v-if="!can_edit_pixels" class="panel-card-lg text-sm text-zinc-600 dark:text-zinc-400">
                Configuração de pixels disponível após aprovação da afiliação.
            </div>
            <div v-else class="panel-card-lg space-y-4">
                <div>
                    <h2 class="font-semibold text-zinc-900 dark:text-white">Pixels de conversão</h2>
                    <p class="mt-1 text-sm text-zinc-500">
                        Seus pixels substituem os do produtor no checkout acessado com seu link de afiliado.
                    </p>
                </div>
                <ConversionPixelsForm v-model="pixelsForm.affiliate_pixels" />
                <Button variant="primary" :disabled="pixelsForm.processing" @click="savePixels">
                    Salvar pixels
                </Button>
            </div>
        </div>
    </div>
</template>
