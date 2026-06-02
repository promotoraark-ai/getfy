<script setup>
defineProps({
    /** sm = tabs/sidebar; xs = tighter inline */
    size: { type: String, default: 'sm' },
});
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center rounded font-semibold uppercase tracking-wide text-zinc-400 ring-1 ring-zinc-200/70 dark:text-zinc-500 dark:ring-zinc-600/60"
        :class="size === 'xs' ? 'px-0.5 py-px text-[8px] leading-none' : 'px-1 py-px text-[9px] leading-tight'"
        title="Funcionalidade em beta"
    >
        Beta
    </span>
</template>
