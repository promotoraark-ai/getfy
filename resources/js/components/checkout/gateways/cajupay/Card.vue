<script setup>
defineProps({
    method: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    primaryColor: { type: String, default: '#0ea5e9' },
});
</script>

<template>
    <span
        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg overflow-hidden"
        :style="{ backgroundColor: selected ? primaryColor + '20' : undefined }"
    >
        <!-- Mesmo asset do tile de cartão em DefaultMethodCard (evita Lucide sem cor = baixo contraste). -->
        <img
            src="/images/gateways/card-method.png"
            alt="Cartão"
            class="h-7 w-7 shrink-0 object-contain self-center"
            width="28"
            height="28"
        />
    </span>
    <div class="min-w-0 flex-1">
        <span class="block font-medium text-gray-900">{{ method.label }}</span>
        <span class="block text-xs text-gray-500">Crédito/Débito</span>
    </div>
</template>
