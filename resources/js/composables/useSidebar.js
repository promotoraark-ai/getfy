import { ref, computed, onMounted, onUnmounted, provide, inject } from 'vue';
import { router } from '@inertiajs/vue3';

const SidebarSymbol = Symbol();

export function useSidebarProvider() {
    const isExpanded = ref(true);
    const isMobileOpen = ref(false);
    const isMobile = ref(false);
    const isHovered = ref(false);

    const closeMobileSidebar = () => {
        isMobileOpen.value = false;
    };

    const handleResize = () => {
        const mobile = window.innerWidth < 1024;
        isMobile.value = mobile;
        if (!mobile) {
            isMobileOpen.value = false;
        }
    };

    let removeNavigateListener = null;

    onMounted(() => {
        handleResize();
        window.addEventListener('resize', handleResize);
        removeNavigateListener = router.on('navigate', () => {
            if (isMobile.value) {
                closeMobileSidebar();
            }
        });
    });

    onUnmounted(() => {
        window.removeEventListener('resize', handleResize);
        removeNavigateListener?.();
    });

    const setExpanded = (value) => {
        if (!isMobile.value) {
            isExpanded.value = !!value;
        }
    };

    const toggleSidebar = () => {
        if (isMobile.value) {
            isMobileOpen.value = !isMobileOpen.value;
        } else {
            isExpanded.value = !isExpanded.value;
        }
    };

    const toggleMobileSidebar = () => {
        isMobileOpen.value = !isMobileOpen.value;
    };

    const setIsHovered = (value) => {
        isHovered.value = value;
    };

    const context = {
        isExpanded: computed(() => (isMobile.value ? false : isExpanded.value)),
        isMobileOpen,
        isMobile,
        isHovered,
        setExpanded,
        toggleSidebar,
        toggleMobileSidebar,
        closeMobileSidebar,
        setIsHovered,
    };

    provide(SidebarSymbol, context);
    return context;
}

export function useSidebar() {
    const context = inject(SidebarSymbol);
    if (!context) {
        throw new Error('useSidebar must be used within a component that has useSidebarProvider as an ancestor');
    }
    return context;
}
