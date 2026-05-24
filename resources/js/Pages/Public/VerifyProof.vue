<script setup>
import LayoutGuest from '@/Layouts/LayoutGuest.vue';
import { ShieldCheck, ShieldX } from 'lucide-vue-next';

const props = defineProps({
    valid: { type: Boolean, default: false },
    status: { type: String, default: 'invalid' }, // valid | revoked | invalid
    code: { type: String, default: '' },
    generated_at: { type: String, default: null },
    summary: { type: Object, default: null },
});

const isValid = props.valid === true && props.status === 'valid';
const isRevoked = props.status === 'revoked';
</script>

<template>
    <LayoutGuest>
        <div class="mx-auto w-full max-w-2xl space-y-6 px-4 py-10">
            <div class="flex items-start gap-3">
                <div
                    class="mt-0.5 inline-flex h-10 w-10 items-center justify-center rounded-xl"
                    :class="isValid ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300'"
                >
                    <ShieldCheck v-if="isValid" class="h-5 w-5" />
                    <ShieldX v-else class="h-5 w-5" />
                </div>
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">
                        Verificação de autenticidade
                    </h1>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        Código: <span class="font-mono font-semibold">{{ code || '-' }}</span>
                    </p>
                </div>
            </div>

            <div v-if="isValid" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900 dark:bg-emerald-950/40">
                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-200">
                    Documento válido
                </p>
                <p v-if="generated_at" class="mt-1 text-xs text-emerald-800/80 dark:text-emerald-200/80">
                    Gerado em: {{ generated_at }}
                </p>
            </div>

            <div v-else-if="isRevoked" class="rounded-2xl border border-rose-200 bg-rose-50 p-5 dark:border-rose-900 dark:bg-rose-950/40">
                <p class="text-sm font-semibold text-rose-800 dark:text-rose-200">
                    Documento revogado
                </p>
                <p class="mt-1 text-xs text-rose-800/80 dark:text-rose-200/80">
                    Este código foi invalidado pelo emissor.
                </p>
            </div>

            <div v-else class="rounded-2xl border border-rose-200 bg-rose-50 p-5 dark:border-rose-900 dark:bg-rose-950/40">
                <p class="text-sm font-semibold text-rose-800 dark:text-rose-200">
                    Documento inválido
                </p>
                <p class="mt-1 text-xs text-rose-800/80 dark:text-rose-200/80">
                    Código não encontrado ou não pôde ser validado.
                </p>
            </div>

            <div v-if="isValid && summary" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-950">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Resumo (dados mascarados)</h2>
                <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-zinc-500">Pedido</dt>
                        <dd class="font-medium text-zinc-900 dark:text-zinc-100">{{ summary.order_id ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Produto</dt>
                        <dd class="font-medium text-zinc-900 dark:text-zinc-100">{{ summary.product_name ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Comprador</dt>
                        <dd class="font-medium text-zinc-900 dark:text-zinc-100">{{ summary.buyer_name ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">E-mail</dt>
                        <dd class="font-medium text-zinc-900 dark:text-zinc-100">{{ summary.buyer_email ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Progresso</dt>
                        <dd class="font-medium text-zinc-900 dark:text-zinc-100">{{ summary.completion_percent ?? 0 }}%</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">Última atividade</dt>
                        <dd class="font-medium text-zinc-900 dark:text-zinc-100">{{ summary.last_activity_at ?? '-' }}</dd>
                    </div>
                </dl>

                <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">
                    Este verificador exibe apenas um resumo limitado. O dossiê completo é acessível somente pelo emissor.
                </p>
            </div>
        </div>
    </LayoutGuest>
</template>

