<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Package, TicketPercent, Users, Handshake, UserPlus } from 'lucide-vue-next';
import BetaBadge from '@/components/ui/BetaBadge.vue';

const page = usePage();
const isProdutos = computed(() => {
    const url = page.url;
    return url === '/produtos' || url.startsWith('/produtos?') || url.match(/^\/produtos\/\d+/);
});
const isCupons = computed(() => page.url.startsWith('/produtos/cupons'));
const isAlunos = computed(() => page.url.startsWith('/produtos/alunos'));
const isCoprodutores = computed(() => page.url.startsWith('/produtos/co-produtores'));
const isAfiliadosProgramas = computed(() => page.url.startsWith('/produtos/afiliados-programas'));
</script>

<template>
    <nav
        class="inline-flex rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80"
        aria-label="Abas de produtos"
    >
        <Link
            href="/produtos"
            :class="[
                'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                isProdutos
                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                    : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
            ]"
        >
            <Package class="h-4 w-4 shrink-0" aria-hidden="true" />
            Produtos
        </Link>
        <Link
            href="/produtos/cupons"
            :class="[
                'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                isCupons
                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                    : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
            ]"
        >
            <TicketPercent class="h-4 w-4 shrink-0" aria-hidden="true" />
            Cupons
        </Link>
        <Link
            href="/produtos/alunos"
            :class="[
                'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                isAlunos
                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                    : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
            ]"
        >
            <Users class="h-4 w-4 shrink-0" aria-hidden="true" />
            Alunos
        </Link>
        <Link
            href="/produtos/co-produtores"
            :class="[
                'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                isCoprodutores
                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                    : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
            ]"
        >
            <Handshake class="h-4 w-4 shrink-0" aria-hidden="true" />
            <span class="flex items-center gap-1.5">
                Co-produtores
                <BetaBadge size="xs" />
            </span>
        </Link>
        <Link
            href="/produtos/afiliados-programas"
            :class="[
                'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                isAfiliadosProgramas
                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                    : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
            ]"
        >
            <UserPlus class="h-4 w-4 shrink-0" aria-hidden="true" />
            <span class="flex items-center gap-1.5">
                Afiliados
                <BetaBadge size="xs" />
            </span>
        </Link>
    </nav>
</template>
