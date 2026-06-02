import { ref, onMounted, onUnmounted, computed } from 'vue';
import axios from 'axios';
import { router, usePage } from '@inertiajs/vue3';
import {
    ensurePushSubscription,
    attachServiceWorkerPushListeners,
} from '@/lib/pushSubscription';

const PANEL_SW_SCRIPT = '/painel-sw.js';
const PANEL_SW_SCOPE = '/painel/';
const PANEL_PUSH_SUBSCRIBE_URL = '/painel/push-subscribe';

let inertiaHealListenerRegistered = false;
let ensureDebounceTimer = null;

/**
 * Registra o Service Worker do painel e subscribe para push com auto-cura após deploy.
 */
export function usePanelPushSubscribe() {
    const page = usePage();
    const pushEnabled = computed(() => !!page.props.push_enabled);
    const vapidPublic = computed(() => page.props.vapid_public ?? null);
    const swScope = computed(() => page.props.pwa_sw_scope ?? PANEL_SW_SCOPE);
    const pushSubscribing = ref(false);
    const pushRegistered = ref(false);
    const lastPushError = ref(null);

    async function syncSubscriptionToServer(payload) {
        const { data } = await axios.post(PANEL_PUSH_SUBSCRIBE_URL, payload);
        return !!data?.success;
    }

    async function runEnsure({ forceRenew = false } = {}) {
        if (!pushEnabled.value || !vapidPublic.value) {
            lastPushError.value = 'push_not_configured';
            pushRegistered.value = false;
            return false;
        }

        const result = await ensurePushSubscription({
            swScriptUrl: PANEL_SW_SCRIPT,
            swScope: swScope.value,
            vapidPublic: vapidPublic.value,
            forceRenew,
            syncToServer: syncSubscriptionToServer,
        });

        pushRegistered.value = result.ok;
        lastPushError.value = result.ok ? null : result.reason;
        return result.ok;
    }

    async function registerAndSubscribe({ forceRenew = false } = {}) {
        lastPushError.value = null;
        pushRegistered.value = false;

        if (typeof navigator === 'undefined' || !navigator.serviceWorker) {
            lastPushError.value = 'service_worker_unavailable';
            return false;
        }

        if (!pushEnabled.value || !vapidPublic.value) {
            lastPushError.value = 'push_not_configured';
            return false;
        }

        if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
            lastPushError.value = 'notification_permission_default';
            return false;
        }
        if (typeof Notification !== 'undefined' && Notification.permission === 'denied') {
            lastPushError.value = 'notification_permission_denied';
            return false;
        }

        if (pushSubscribing.value) {
            return false;
        }

        pushSubscribing.value = true;
        try {
            const reg = await navigator.serviceWorker.register(PANEL_SW_SCRIPT, { scope: swScope.value });
            attachServiceWorkerPushListeners(reg, () => {
                if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
                    runEnsure({ forceRenew: true }).catch(() => {});
                }
            });
            return await runEnsure({ forceRenew });
        } catch (e) {
            console.warn('Panel push subscribe failed:', e);
            lastPushError.value = 'subscription_failed';
            return false;
        } finally {
            pushSubscribing.value = false;
        }
    }

    async function checkExistingSubscription({ forceRenew = false } = {}) {
        if (typeof navigator === 'undefined' || !navigator.serviceWorker?.getRegistration) {
            return false;
        }
        if (typeof Notification !== 'undefined' && Notification.permission !== 'granted') {
            pushRegistered.value = false;
            return false;
        }
        if (!pushEnabled.value || !vapidPublic.value) {
            return false;
        }

        pushSubscribing.value = true;
        try {
            const reg = await navigator.serviceWorker.register(PANEL_SW_SCRIPT, { scope: swScope.value });
            attachServiceWorkerPushListeners(reg, () => {
                runEnsure({ forceRenew: true }).catch(() => {});
            });
            return await runEnsure({ forceRenew });
        } catch (_) {
            return false;
        } finally {
            pushSubscribing.value = false;
        }
    }

    /** Força nova inscrição (ex.: após deploy ou falha persistente). */
    async function reactivatePush() {
        if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
            const result = await Notification.requestPermission();
            if (result !== 'granted') {
                lastPushError.value = 'notification_permission_denied';
                return false;
            }
        }
        return registerAndSubscribe({ forceRenew: true });
    }

    const notificationPermission = computed(() =>
        typeof Notification !== 'undefined' ? Notification.permission : 'default',
    );

    const isStandalone = computed(() => {
        if (typeof window === 'undefined') {
            return false;
        }
        return (
            window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true ||
            document.referrer.includes('android-app://')
        );
    });

    let permissionCheckInterval = null;

    function scheduleEnsureFromNavigation() {
        if (ensureDebounceTimer) {
            clearTimeout(ensureDebounceTimer);
        }
        ensureDebounceTimer = setTimeout(() => {
            if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
                checkExistingSubscription().catch(() => {});
            }
        }, 2000);
    }

    onMounted(() => {
        if (isStandalone.value && notificationPermission.value === 'default') {
            permissionCheckInterval = setInterval(() => {
                if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
                    if (permissionCheckInterval) {
                        clearInterval(permissionCheckInterval);
                        permissionCheckInterval = null;
                    }
                    registerAndSubscribe();
                }
            }, 1500);
            setTimeout(() => {
                if (permissionCheckInterval) {
                    clearInterval(permissionCheckInterval);
                    permissionCheckInterval = null;
                }
            }, 60000);
        } else {
            registerAndSubscribe();
        }

        if (!inertiaHealListenerRegistered) {
            inertiaHealListenerRegistered = true;
            router.on('success', () => scheduleEnsureFromNavigation());
        }
    });

    onUnmounted(() => {
        if (permissionCheckInterval) {
            clearInterval(permissionCheckInterval);
        }
        if (ensureDebounceTimer) {
            clearTimeout(ensureDebounceTimer);
        }
    });

    return {
        pushSubscribing,
        pushRegistered,
        lastPushError,
        notificationPermission,
        isStandalone,
        registerAndSubscribe,
        checkExistingSubscription,
        reactivatePush,
    };
}
