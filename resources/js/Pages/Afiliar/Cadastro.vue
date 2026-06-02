<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import AfiliarLayout from '@/Layouts/AfiliarLayout.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: AfiliarLayout });

const props = defineProps({
    program: { type: Object, required: true },
    product: { type: Object, required: true },
    slug: { type: String, required: true },
    login_url: { type: String, required: true },
    landing_url: { type: String, required: true },
});

const page = usePage();
const errors = computed(() => page.props.errors ?? {});

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(`/afiliar/${props.slug}/cadastro`);
}

const inputClass =
    'w-full rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm text-zinc-900 outline-none transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[color-mix(in_srgb,var(--color-primary)_25%,transparent)] dark:border-zinc-700 dark:bg-zinc-800 dark:text-white';
</script>

<template>
    <div class="space-y-6">
        <Link
            :href="landing_url"
            class="inline-flex items-center gap-1.5 text-sm font-medium text-zinc-500 transition hover:text-[var(--color-primary)] dark:text-zinc-400"
        >
            <ArrowLeft class="h-4 w-4" />
            Voltar ao programa
        </Link>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center gap-4 border-b border-zinc-100 p-5 dark:border-zinc-800 sm:p-6">
                <img
                    v-if="product.image_url"
                    :src="product.image_url"
                    :alt="product.name"
                    class="h-16 w-16 shrink-0 rounded-xl object-cover"
                />
                <div
                    v-else
                    class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-xl font-bold text-zinc-400 dark:bg-zinc-800"
                >
                    {{ product.name?.charAt(0) }}
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wide text-[var(--color-primary)]">Cadastro de afiliado</p>
                    <h1 class="truncate text-lg font-bold text-zinc-900 dark:text-white">{{ product.name }}</h1>
                    <p class="text-sm text-zinc-500">
                        Comissão de {{ program.default_commission_percent }}% (líquido)
                    </p>
                </div>
            </div>

            <form class="space-y-4 p-5 sm:p-6" @submit.prevent="submit">
                <p
                    v-if="errors.email"
                    class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300"
                >
                    {{ errors.email }}
                </p>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome completo</label>
                    <input v-model="form.name" type="text" required autocomplete="name" :class="inputClass" />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-mail</label>
                    <input v-model="form.email" type="email" required autocomplete="email" :class="inputClass" />
                    <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Senha</label>
                    <input v-model="form.password" type="password" required autocomplete="new-password" :class="inputClass" />
                    <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Confirmar senha</label>
                    <input
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        :class="inputClass"
                    />
                </div>

                <Button type="submit" variant="primary" class="w-full py-3" :disabled="form.processing">
                    {{ program.manual_approval ? 'Solicitar afiliação' : 'Criar conta e afiliar' }}
                </Button>

                <p class="text-center text-sm text-zinc-500 dark:text-zinc-400">
                    Já tem conta?
                    <Link :href="login_url" class="font-medium text-[var(--color-primary)] hover:underline">
                        Entrar e afiliar automaticamente
                    </Link>
                </p>
            </form>
        </div>
    </div>
</template>
