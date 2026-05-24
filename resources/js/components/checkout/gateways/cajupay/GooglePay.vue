<script setup>
defineProps({
    method: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    primaryColor: { type: String, default: '#0ea5e9' },
});
</script>

<template>
    <span
        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-white border border-gray-200"
    >
        <!-- "G" multicolorido inspirado na marca Google. -->
        <svg viewBox="0 0 24 24" class="h-5 w-5" aria-hidden="true">
            <path fill="#4285F4" d="M21.6 12.227c0-.71-.064-1.391-.182-2.045H12v3.873h5.39a4.61 4.61 0 0 1-2 3.025v2.512h3.235c1.893-1.745 2.975-4.314 2.975-7.365z" />
            <path fill="#34A853" d="M12 22c2.7 0 4.964-.895 6.625-2.408l-3.235-2.512c-.896.6-2.041.955-3.39.955-2.605 0-4.81-1.76-5.598-4.124H3.057v2.59A9.997 9.997 0 0 0 12 22z" />
            <path fill="#FBBC05" d="M6.402 13.911A5.99 5.99 0 0 1 6.09 12c0-.664.114-1.31.312-1.911V7.5H3.057A9.997 9.997 0 0 0 2 12c0 1.614.385 3.14 1.057 4.5l3.345-2.589z" />
            <path fill="#EA4335" d="M12 5.977c1.468 0 2.785.504 3.823 1.494l2.866-2.866C16.96 3.092 14.7 2.182 12 2.182A9.997 9.997 0 0 0 3.057 7.5l3.345 2.589c.788-2.364 2.993-4.112 5.598-4.112z" />
        </svg>
    </span>
    <div class="min-w-0 flex-1">
        <span class="block font-medium text-gray-900">{{ method.label }}</span>
        <span class="block text-xs text-gray-500">Carteira digital</span>
    </div>
</template>
