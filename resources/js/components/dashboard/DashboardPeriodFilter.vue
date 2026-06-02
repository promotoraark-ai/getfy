<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import {
    SelectContent,
    SelectItem,
    SelectItemIndicator,
    SelectItemText,
    SelectPortal,
    SelectRoot,
    SelectTrigger,
    SelectValue,
    SelectViewport,
} from 'radix-vue';
import {
    Sun,
    Moon,
    CalendarRange,
    Calendar,
    CalendarClock,
    Infinity,
    ChevronDown,
    Check,
} from 'lucide-vue-next';

const props = defineProps({
    modelValue: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);

const periodOptions = [
    { value: 'hoje', label: 'Hoje', icon: Sun },
    { value: 'ontem', label: 'Ontem', icon: Moon },
    { value: '7dias', label: '7 dias', icon: CalendarRange },
    { value: 'mes', label: 'Mês', icon: Calendar },
    { value: 'ano', label: 'Ano', icon: CalendarClock },
    { value: 'total', label: 'Total', icon: Infinity },
];

const trackRef = ref(null);
const buttonRefs = ref([]);
const indicator = ref({ left: 0, width: 0, opacity: 0 });

const activeOption = computed(() =>
    periodOptions.find((o) => o.value === props.modelValue) ?? periodOptions[0],
);

const activeIndex = computed(() =>
    Math.max(0, periodOptions.findIndex((o) => o.value === props.modelValue)),
);

function setButtonRef(el, index) {
    if (el) {
        buttonRefs.value[index] = el;
    }
}

function updateIndicator() {
    if (typeof window !== 'undefined' && !window.matchMedia('(min-width: 1024px)').matches) {
        return;
    }
    const track = trackRef.value;
    const btn = buttonRefs.value[activeIndex.value];
    if (!track || !btn) {
        return;
    }
    const trackRect = track.getBoundingClientRect();
    const btnRect = btn.getBoundingClientRect();
    indicator.value = {
        left: btnRect.left - trackRect.left,
        width: btnRect.width,
        opacity: 1,
    };
}

function select(value) {
    if (value !== props.modelValue) {
        emit('update:modelValue', value);
    }
}

let resizeObserver;

onMounted(() => {
    nextTick(updateIndicator);
    resizeObserver = new ResizeObserver(() => updateIndicator());
    if (trackRef.value) {
        resizeObserver.observe(trackRef.value);
    }
    window.addEventListener('resize', updateIndicator);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    window.removeEventListener('resize', updateIndicator);
});

watch(
    () => props.modelValue,
    () => nextTick(updateIndicator),
);
</script>

<template>
    <div class="w-full max-w-full lg:w-fit">
        <p class="mb-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-zinc-400 dark:text-zinc-500">
            Período
        </p>

        <!-- Mobile: dropdown -->
        <div class="flex items-center gap-2 lg:hidden">
            <SelectRoot :model-value="modelValue" @update:model-value="select">
                <SelectTrigger
                    type="button"
                    aria-label="Período"
                    class="flex h-11 min-w-0 flex-1 cursor-pointer items-center justify-between gap-2 rounded-2xl border border-zinc-200/80 bg-zinc-100/90 px-4 py-2 text-left text-sm font-medium transition hover:border-zinc-300 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 focus:ring-offset-0 dark:border-zinc-700/80 dark:bg-zinc-800/50 dark:text-white dark:hover:border-zinc-600"
                >
                    <span class="flex min-w-0 items-center gap-2 truncate text-zinc-900 dark:text-white">
                        <component
                            :is="activeOption.icon"
                            class="h-4 w-4 shrink-0 text-[var(--color-primary)]"
                            aria-hidden="true"
                        />
                        <SelectValue :placeholder="activeOption.label" />
                    </span>
                    <ChevronDown class="h-4 w-4 shrink-0 text-zinc-400 dark:text-zinc-500" aria-hidden="true" />
                </SelectTrigger>
                <SelectPortal to="body">
                    <SelectContent
                        class="z-[9999] min-w-[var(--radix-select-trigger-width)] overflow-hidden rounded-2xl border border-zinc-200 bg-white py-1 shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
                        :side-offset="6"
                        position="popper"
                        :avoid-collisions="true"
                    >
                        <SelectViewport class="p-1">
                            <SelectItem
                                v-for="opt in periodOptions"
                                :key="opt.value"
                                :value="opt.value"
                                class="relative flex cursor-pointer select-none items-center gap-2 rounded-xl py-2.5 pl-3 pr-10 text-sm outline-none transition data-[highlighted]:bg-[var(--color-primary)]/10 data-[highlighted]:text-[var(--color-primary)] data-[state=checked]:bg-[var(--color-primary)]/10 data-[state=checked]:text-[var(--color-primary)] dark:data-[highlighted]:bg-[var(--color-primary)]/20 dark:data-[state=checked]:bg-[var(--color-primary)]/20"
                            >
                                <component :is="opt.icon" class="h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />
                                <SelectItemText>{{ opt.label }}</SelectItemText>
                                <SelectItemIndicator class="absolute right-3 flex h-4 w-4 items-center justify-center">
                                    <Check class="h-4 w-4 text-[var(--color-primary)]" />
                                </SelectItemIndicator>
                            </SelectItem>
                        </SelectViewport>
                    </SelectContent>
                </SelectPortal>
            </SelectRoot>
            <slot name="trailing" />
        </div>

        <!-- Desktop: barra segmentada -->
        <div class="hidden items-center gap-2 lg:flex">
            <div
                ref="trackRef"
                class="relative inline-flex w-max items-center gap-0.5 rounded-2xl border border-zinc-200/80 bg-zinc-100/90 p-1 dark:border-zinc-700/80 dark:bg-zinc-800/50"
                role="tablist"
                aria-label="Período do dashboard"
            >
                <div
                    class="pointer-events-none absolute top-1 bottom-1 z-0 rounded-xl bg-white shadow-sm ring-1 ring-zinc-200/80 transition-[left,width,opacity] duration-300 ease-[cubic-bezier(0.34,1.2,0.64,1)] dark:bg-zinc-700 dark:shadow-none dark:ring-zinc-600/80"
                    :style="{
                        left: `${indicator.left}px`,
                        width: `${indicator.width}px`,
                        opacity: indicator.opacity,
                    }"
                    aria-hidden="true"
                />

                <template v-for="(opt, index) in periodOptions" :key="opt.value">
                    <div
                        v-if="index === 3"
                        class="mx-0.5 h-7 w-px shrink-0 self-center bg-gradient-to-b from-transparent via-zinc-300 to-transparent dark:via-zinc-600"
                        aria-hidden="true"
                    />
                    <button
                        :ref="(el) => setButtonRef(el, index)"
                        type="button"
                        role="tab"
                        :aria-selected="modelValue === opt.value"
                        class="relative z-10 flex shrink-0 items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-medium transition-colors duration-200"
                        :class="modelValue === opt.value
                            ? 'text-[var(--color-primary)]'
                            : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                        @click="select(opt.value)"
                    >
                        <component
                            :is="opt.icon"
                            class="h-4 w-4 shrink-0 transition-transform duration-200"
                            :class="modelValue === opt.value ? 'scale-110' : 'opacity-70'"
                            aria-hidden="true"
                        />
                        <span class="whitespace-nowrap">{{ opt.label }}</span>
                    </button>
                </template>
            </div>
            <slot name="trailing" />
        </div>
    </div>
</template>
