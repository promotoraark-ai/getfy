/**
 * Web Push: validação VAPID, renovação de subscription e sync com o backend.
 */

export function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = globalThis.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

export function serializePushSubscription(sub) {
    const p256dh = sub?.getKey?.('p256dh');
    const auth = sub?.getKey?.('auth');
    return {
        endpoint: sub?.endpoint,
        keys: {
            p256dh: p256dh ? btoa(String.fromCharCode.apply(null, new Uint8Array(p256dh))) : '',
            auth: auth ? btoa(String.fromCharCode.apply(null, new Uint8Array(auth))) : '',
        },
    };
}

/** Compara applicationServerKey da subscription com a chave VAPID pública atual. */
export function subscriptionMatchesVapid(subscription, vapidPublicBase64) {
    if (!subscription || !vapidPublicBase64) {
        return false;
    }
    const existing = subscription.options?.applicationServerKey;
    if (!existing) {
        return false;
    }
    let expected;
    try {
        expected = urlBase64ToUint8Array(vapidPublicBase64);
    } catch {
        return false;
    }
    if (existing.byteLength !== expected.byteLength) {
        return false;
    }
    for (let i = 0; i < expected.byteLength; i++) {
        if (existing[i] !== expected[i]) {
            return false;
        }
    }
    return true;
}

/**
 * Garante subscription válida e sincronizada com o servidor.
 *
 * @param {object} options
 * @param {string} options.swScriptUrl - ex: /painel-sw.js
 * @param {string} options.swScope - ex: /painel/
 * @param {string} options.vapidPublic - chave pública VAPID (base64url)
 * @param {boolean} [options.forceRenew=false] - cancela e recria subscription
 * @param {(payload: object) => Promise<boolean>} options.syncToServer - POST endpoint + keys; payload inclui renewed
 * @returns {Promise<{ ok: boolean, reason: string|null, renewed: boolean }>}
 */
export async function ensurePushSubscription({
    swScriptUrl,
    swScope,
    vapidPublic,
    forceRenew = false,
    syncToServer,
}) {
    if (typeof navigator === 'undefined' || !navigator.serviceWorker) {
        return { ok: false, reason: 'service_worker_unavailable', renewed: false };
    }
    if (!vapidPublic) {
        return { ok: false, reason: 'push_not_configured', renewed: false };
    }
    if (typeof Notification !== 'undefined' && Notification.permission === 'denied') {
        return { ok: false, reason: 'notification_permission_denied', renewed: false };
    }
    if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
        return { ok: false, reason: 'notification_permission_default', renewed: false };
    }

    let renewed = false;

    try {
        await navigator.serviceWorker.register(swScriptUrl, { scope: swScope });
    } catch {
        return { ok: false, reason: 'service_worker_registration_failed', renewed: false };
    }

    await navigator.serviceWorker.ready;

    const reg = await navigator.serviceWorker.getRegistration(swScope);
    if (!reg?.pushManager) {
        return { ok: false, reason: 'service_worker_not_found', renewed: false };
    }

    let sub = await reg.pushManager.getSubscription();

    if (forceRenew && sub) {
        try {
            await sub.unsubscribe();
        } catch (_) {}
        sub = null;
        renewed = true;
    }

    if (sub && !subscriptionMatchesVapid(sub, vapidPublic)) {
        try {
            await sub.unsubscribe();
        } catch (_) {}
        sub = null;
        renewed = true;
    }

    if (!sub) {
        try {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublic),
            });
            renewed = true;
        } catch (e) {
            if (e?.name === 'NotAllowedError') {
                return { ok: false, reason: 'notification_permission_denied', renewed: false };
            }
            return { ok: false, reason: 'subscription_failed', renewed: false };
        }
    }

    const payload = serializePushSubscription(sub);
    if (!payload.endpoint || !payload.keys?.p256dh || !payload.keys?.auth) {
        return { ok: false, reason: 'subscription_invalid', renewed };
    }

    const synced = await syncToServer({ ...payload, renewed });
    if (!synced) {
        return { ok: false, reason: 'subscription_sync_failed', renewed };
    }

    return { ok: true, reason: null, renewed };
}

const listenerState = new WeakMap();
const controllerChangeCallbacks = new Set();
let controllerChangeBound = false;

function dispatchControllerChange() {
    controllerChangeCallbacks.forEach((cb) => {
        try {
            cb('controller_change');
        } catch (_) {}
    });
}

/**
 * Revalida push quando o SW atualiza (deploy) — evita subscription órfã.
 */
export function attachServiceWorkerPushListeners(registration, onUpdate) {
    if (!registration || typeof onUpdate !== 'function') {
        return;
    }
    if (!listenerState.has(registration)) {
        listenerState.set(registration, true);
        registration.addEventListener('updatefound', () => {
            const installing = registration.installing;
            if (!installing) {
                return;
            }
            installing.addEventListener('statechange', () => {
                if (installing.state === 'activated') {
                    onUpdate('sw_activated');
                }
            });
        });
    }

    controllerChangeCallbacks.add(onUpdate);

    if (!controllerChangeBound && typeof navigator !== 'undefined' && navigator.serviceWorker) {
        controllerChangeBound = true;
        navigator.serviceWorker.addEventListener('controllerchange', dispatchControllerChange);
    }
}

export function pushErrorMessage(reason) {
    const messages = {
        service_worker_unavailable: 'Service worker não disponível neste navegador.',
        service_worker_registration_failed: 'Não foi possível registrar o service worker.',
        service_worker_not_found: 'Service worker do painel não encontrado.',
        push_not_configured: 'Push não configurado no servidor.',
        notification_permission_denied: 'Permissão de notificações negada.',
        notification_permission_default: 'Permita notificações para ativar o push.',
        subscription_sync_failed: 'Falha ao sincronizar inscrição com o servidor.',
        subscription_failed: 'Falha ao criar inscrição push.',
        subscription_invalid: 'Inscrição push inválida.',
        vapid_mismatch: 'Inscrição desatualizada — reative as notificações.',
        subscription_stale: 'Inscrição expirada — reative as notificações.',
    };
    return messages[reason] || 'Não foi possível ativar as notificações.';
}
