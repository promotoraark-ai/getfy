<script setup>
defineProps({
    method: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    primaryColor: { type: String, default: '#0ea5e9' },
});
</script>

<template>
    <span
        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-black"
    >
        <!-- Logotipo "Pay" tipográfico (cor branca para contrastar). -->
        <svg viewBox="0 0 24 24" class="h-5 w-5 text-white" fill="currentColor" aria-hidden="true">
            <path d="M17.564 12.42c-.02-2.11 1.72-3.13 1.8-3.18-0.98-1.43-2.5-1.62-3.04-1.64-1.29-.13-2.52.76-3.17.76-.66 0-1.66-.74-2.74-.72-1.41.02-2.71.82-3.43 2.08-1.46 2.54-.37 6.3 1.06 8.36.7 1.01 1.53 2.14 2.62 2.1 1.05-.04 1.45-.68 2.72-.68 1.27 0 1.62.68 2.73.66 1.13-.02 1.85-1.03 2.54-2.04.8-1.17 1.13-2.31 1.15-2.37-.03-.01-2.21-.85-2.24-3.33zM15.43 5.36c.58-.7.97-1.67.86-2.64-.84.03-1.85.56-2.45 1.26-.54.62-1.01 1.61-.88 2.56.93.07 1.89-.47 2.47-1.18z" />
        </svg>
    </span>
    <div class="min-w-0 flex-1">
        <span class="block font-medium text-gray-900">{{ method.label }}</span>
        <span class="block text-xs text-gray-500">Carteira digital</span>
    </div>
</template>
